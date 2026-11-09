<?php

namespace App\Actions\User;

use App\Models\DatabaseNotification;
use App\Models\Enums\UserRole;
use App\Models\PresentationComment;
use App\Models\PresentationLike;
use App\Models\PresentationView;
use App\Models\Registration;
use App\Models\Review;
use App\Models\Role;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Permission\PermissionRegistrar;

/**
 * Moves everything owned by $source to $head, then permanently deletes $source.
 * The head account keeps its own profile, email and password.
 */
class UserMergeAction
{
    use AsAction;

    /**
     * Columns that point at a user and can be repointed without conflicts.
     *
     * @var array<string, array<int, string>>
     */
    public const REFERENCES = [
        'submissions' => ['user_id'],
        'submission_files' => ['user_id'],
        'reviews' => ['user_id'],
        'discussion_topics' => ['user_id'],
        'discussions' => ['sent_by'],
        'presentation_comments' => ['user_id'],
        'payments' => ['user_id'],
        'registrations' => ['user_id'],
        'user_invitations' => ['invited_by'],
        'submission_review_rounds' => ['triggered_by'],
        'website_revisions' => ['user_id'],
    ];

    /**
     * Tables where the same (user + key columns) row must not exist twice.
     * Rows the head already has are kept; the source's duplicates are dropped.
     *
     * @var array<string, array<int, string>>
     */
    public const UNIQUE_REFERENCES = [
        'presentation_views' => ['presentation_id'],
        'presentation_likes' => ['presentation_id'],
        'submission_has_participants' => ['submission_id', 'role_id'],
        'discussion_topic_participants' => ['discussion_topic_id'],
    ];

    /**
     * Polymorphic references: table => [type column, id column].
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    public const MORPH_REFERENCES = [
        'activity_log' => [['causer_type', 'causer_id'], ['subject_type', 'subject_id']],
        'notifications' => [['notifiable_type', 'notifiable_id']],
        'bans' => [['created_by_type', 'created_by_id']],
    ];

    public function handle(User $head, User $source): User
    {
        $this->guard($head, $source);

        $plan = $this->plan($head, $source);

        if ($plan['blocked'] !== []) {
            throw ValidationException::withMessages(['source' => $plan['blocked']]);
        }

        $sourceSnapshot = ['id' => $source->getKey(), 'email' => $source->email, 'name' => $source->full_name];

        DB::transaction(function () use ($head, $source, $plan) {
            $this->applyPlan($plan);

            foreach (self::REFERENCES as $table => $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        DB::table($table)->where($column, $source->getKey())->update([$column => $head->getKey()]);
                    }
                }
            }

            foreach (self::UNIQUE_REFERENCES as $table => $keys) {
                $this->mergeUniqueRows($table, $keys, $head, $source);
            }

            foreach (self::MORPH_REFERENCES as $table => $pairs) {
                foreach ($pairs as [$typeColumn, $idColumn]) {
                    if (Schema::hasColumn($table, $idColumn)) {
                        DB::table($table)
                            ->where($typeColumn, $source->getMorphClass())
                            ->where($idColumn, $source->getKey())
                            ->update([$idColumn => $head->getKey()]);
                    }
                }
            }

            $this->mergePivot(config('permission.table_names.model_has_roles', 'model_has_roles'), config('permission.column_names.role_pivot_key') ?: 'role_id', $head, $source);
            $this->mergePivot(config('permission.table_names.model_has_permissions', 'model_has_permissions'), config('permission.column_names.permission_pivot_key') ?: 'permission_id', $head, $source);

            // Tokens are credentials of the removed account and must not outlive it.
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $source->getMorphClass())
                ->where('tokenable_id', $source->getKey())
                ->delete();

            // Bans targeting the removed account and its live sessions die with it.
            DB::table(config('ban.table', 'bans'))
                ->where('bannable_type', $source->getMorphClass())
                ->where('bannable_id', $source->getKey())
                ->delete();

            if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
                DB::table('sessions')->where('user_id', $source->getKey())->delete();
            }

            // Fresh instance: relations loaded before the move would still show old submissions.
            User::query()->findOrFail($source->getKey())->delete();
        });

        $this->flushModelCaches();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('leconfe')
            ->causedBy(auth()->user())
            ->performedOn($head)
            ->event('user-merge')
            ->withProperties([
                'merged_user' => $sourceSnapshot,
                'head_id' => $head->getKey(),
                'removed_participants' => $plan['participants'],
                'removed_reviews' => $plan['reviews'],
            ])
            ->log("User {$sourceSnapshot['email']} merged into {$head->email}");

        return $head->refresh();
    }

    protected function guard(User $head, User $source): void
    {
        if ($head->is($source)) {
            throw ValidationException::withMessages(['source' => 'An account cannot be merged into itself.']);
        }

        if (static::isAdmin($source)) {
            throw ValidationException::withMessages(['source' => 'An Admin account cannot be merged into another account.']);
        }
    }

    /**
     * What the merge has to remove so the head account never ends up twice in the same submission.
     * The submitter wins: editor assignments on a submission the merged account submitted are released.
     * Without a submitter involved, the head account's assignment is kept.
     *
     * @return array{participants: array<int, array<string, mixed>>, reviews: array<int, array<string, mixed>>, blocked: array<int, string>}
     */
    public function plan(User $head, User $source): array
    {
        $ids = [$head->getKey(), $source->getKey()];
        $roleNames = Role::withoutGlobalScopes()->pluck('name', 'id');
        $authorRoleIds = $roleNames->filter(fn ($name) => $name === UserRole::Author->value)->keys();
        $editorRoleIds = $roleNames->filter(fn ($name) => in_array($name, [UserRole::ScheduledConferenceEditor->value, UserRole::TrackEditor->value], true))->keys();
        $names = [$head->getKey() => $head->full_name, $source->getKey() => $source->full_name];

        $participantDrops = [];
        $reviewDrops = [];
        $blocked = [];

        $rows = DB::table('submission_has_participants')->whereIn('user_id', $ids)->get()->groupBy('submission_id');
        $submitters = DB::table('submissions')->whereIn('user_id', $ids)->pluck('user_id', 'id');

        foreach ($rows->keys()->merge($submitters->keys())->unique() as $submissionId) {
            $mine = $rows->get($submissionId, collect());
            $submitter = $submitters->get($submissionId);

            $involved = $mine->pluck('user_id')->when($submitter, fn ($users) => $users->push($submitter))->unique();

            if ($involved->count() < 2) {
                continue;
            }

            $drop = $submitter !== null
                ? $mine->reject(fn ($row) => $authorRoleIds->contains($row->role_id))
                : $mine->where('user_id', $source->getKey());

            foreach ($drop as $row) {
                $participantDrops[] = [
                    'id' => $row->id,
                    'submission_id' => (int) $submissionId,
                    'user_id' => $row->user_id,
                    'user' => $names[$row->user_id] ?? null,
                    'role_id' => $row->role_id,
                    'role' => $roleNames->get($row->role_id),
                ];
            }

            $droppingEditor = $drop->contains(fn ($row) => $editorRoleIds->contains($row->role_id));

            if ($droppingEditor && ! DB::table('submission_has_participants')
                ->where('submission_id', $submissionId)
                ->whereIn('role_id', $editorRoleIds)
                ->whereNotIn('id', $drop->pluck('id'))
                ->exists()) {
                $blocked[] = __('user_merge.blocked_no_editor', ['id' => $submissionId]);
            }
        }

        $reviews = DB::table('reviews')->whereIn('user_id', $ids)->get()
            ->groupBy(fn ($review) => $review->submission_id.':'.($review->review_round_id ?? 0));

        $rank = fn ($review) => match (true) {
            ! is_null($review->recommendation) && ! is_null($review->date_completed) => 2,
            $review->status === \App\Constants\ReviewerStatus::ACCEPTED => 1,
            default => 0,
        };

        foreach ($reviews as $group) {
            if ($group->pluck('user_id')->unique()->count() < 2) {
                continue;
            }

            $best = $group->map($rank)->max();

            if ($group->filter(fn ($review) => $rank($review) === 2)->count() > 1) {
                $blocked[] = __('user_merge.blocked_reviews', ['id' => $group->first()->submission_id]);

                continue;
            }

            $keep = $group->first(fn ($review) => $rank($review) === $best && $review->user_id === $head->getKey())
                ?? $group->first(fn ($review) => $rank($review) === $best);

            foreach ($group->reject(fn ($review) => $review->id === $keep->id) as $review) {
                $reviewDrops[] = (array) $review;
            }
        }

        return ['participants' => $participantDrops, 'reviews' => $reviewDrops, 'blocked' => array_values(array_unique($blocked))];
    }

    /**
     * Human readable lines for the confirmation modal.
     *
     * @return array<int, string>
     */
    public function describePlan(array $plan): array
    {
        return array_merge(
            $plan['blocked'],
            array_map(fn ($row) => __('user_merge.preview_participant', ['id' => $row['submission_id'], 'role' => $row['role'], 'name' => $row['user']]), $plan['participants']),
            array_map(fn ($row) => __('user_merge.preview_review', ['id' => $row['submission_id']]), $plan['reviews']),
        );
    }

    protected function applyPlan(array $plan): void
    {
        if ($plan['participants'] !== []) {
            DB::table('submission_has_participants')->whereIn('id', array_column($plan['participants'], 'id'))->delete();
        }

        foreach ($plan['reviews'] as $row) {
            DB::table('reviewer_assigned_files')->where('review_id', $row['id'])->delete();

            if ($review = Review::withoutGlobalScopes()->find($row['id'])) {
                $review->delete();
            }
        }
    }

    public static function isAdmin(User $user): bool
    {
        $assignments = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivot = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        return DB::table($assignments)
            ->join('roles', 'roles.id', '=', "{$assignments}.{$rolePivot}")
            ->where("{$assignments}.model_type", $user->getMorphClass())
            ->where("{$assignments}.{$modelKey}", $user->getKey())
            ->where('roles.name', UserRole::Admin->value)
            ->exists();
    }

    protected function mergeUniqueRows(string $table, array $keys, User $head, User $source): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        DB::table($table)->where('user_id', $source->getKey())->get()->each(function ($row) use ($table, $keys, $head) {
            $conditions = collect($keys)->mapWithKeys(fn (string $key) => [$key => $row->{$key}])->all();

            $headHasRow = DB::table($table)->where('user_id', $head->getKey())->where($conditions)->exists();

            $query = DB::table($table)->where('id', $row->id);

            $headHasRow
                ? $query->delete()
                : $query->update(['user_id' => $head->getKey()]);
        });
    }

    /**
     * Union of the pivot rows: whatever the source had is given to the head,
     * rows the head already has are not duplicated.
     */
    protected function mergePivot(string $table, string $pivotKey, User $head, User $source): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $modelKey = config('permission.column_names.model_morph_key', 'model_id');
        $type = $source->getMorphClass();

        $sourceRows = DB::table($table)->where('model_type', $type)->where($modelKey, $source->getKey())->get();

        foreach ($sourceRows as $row) {
            $attributes = [
                $pivotKey => $row->{$pivotKey},
                'conference_id' => $row->conference_id,
                'scheduled_conference_id' => $row->scheduled_conference_id,
                'model_type' => $type,
            ];

            if (! DB::table($table)->where($attributes)->where($modelKey, $head->getKey())->exists()) {
                DB::table($table)->insert($attributes + [$modelKey => $head->getKey()]);
            }
        }

        DB::table($table)->where('model_type', $type)->where($modelKey, $source->getKey())->delete();
    }

    /**
     * Rows were moved with the query builder, which bypasses model caching.
     */
    protected function flushModelCaches(): void
    {
        $models = [
            Submission::class,
            SubmissionParticipant::class,
            Review::class,
            Registration::class,
            PresentationComment::class,
            PresentationLike::class,
            PresentationView::class,
            DatabaseNotification::class,
        ];

        foreach ($models as $model) {
            (new $model)->flushCache();
        }
    }
}
