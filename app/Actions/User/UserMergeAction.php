<?php

namespace App\Actions\User;

use App\Models\DatabaseNotification;
use App\Models\Enums\UserRole;
use App\Models\PresentationComment;
use App\Models\PresentationLike;
use App\Models\PresentationView;
use App\Models\Registration;
use App\Models\Review;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

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
    ];

    public function handle(User $head, User $source): User
    {
        $this->guard($head, $source);

        DB::transaction(function () use ($head, $source) {
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

            // Fresh instance: relations loaded before the move would still show old submissions.
            User::query()->findOrFail($source->getKey())->delete();
        });

        $this->flushModelCaches();

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
