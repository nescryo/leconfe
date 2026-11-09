<?php

namespace Tests\Feature;

use App\Actions\User\UserMergeAction;
use App\Models\Conference;
use App\Models\Enums\UserRole;
use App\Models\Role;
use App\Models\ScheduledConference;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserMergeActionTest extends TestCase
{
    use RefreshDatabase;

    private Conference $conference;

    private ScheduledConference $scheduledA;

    private ScheduledConference $scheduledB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conference = Conference::factory()->create(['path' => 'merge-conference']);
        $this->scheduledA = ScheduledConference::factory()->create([
            'conference_id' => $this->conference->getKey(),
            'path' => 'merge-a',
        ]);
        $this->scheduledB = ScheduledConference::factory()->create([
            'conference_id' => $this->conference->getKey(),
            'path' => 'merge-b',
        ]);

        app()->setCurrentConferenceId($this->conference->getKey());
        app()->setCurrentScheduledConferenceId($this->scheduledA->getKey());

        \App\Models\Permission::query()->firstOrCreate(['name' => 'User:merge', 'guard_name' => 'web']);
    }

    public function test_roles_from_both_accounts_are_combined_and_source_is_deleted(): void
    {
        $head = $this->makeUser(['email' => 'head@example.test']);
        $source = $this->makeUser(['email' => 'source@example.test']);

        $this->assignScopedRole($head, UserRole::ScheduledConferenceEditor, $this->scheduledA);
        $this->assignScopedRole($source, UserRole::Reviewer, $this->scheduledB);

        UserMergeAction::run($head, $source);

        $this->assertDatabaseMissing('users', ['id' => $source->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $head->getKey(), 'email' => 'head@example.test']);
        $this->assertSame(2, $this->assignmentCount($head));
        $this->assertSame(0, $this->assignmentCount($source));
    }

    public function test_same_role_in_same_scheduled_conference_is_not_duplicated(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();

        $this->assignScopedRole($head, UserRole::Reviewer, $this->scheduledA);
        $this->assignScopedRole($source, UserRole::Reviewer, $this->scheduledA);

        UserMergeAction::run($head, $source);

        $this->assertSame(1, $this->assignmentCount($head));
    }

    public function test_owned_records_move_to_head_account(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();

        $submissionId = $this->createSubmissionFor($source);

        UserMergeAction::run($head, $source);

        $this->assertDatabaseHas('submissions', ['id' => $submissionId, 'user_id' => $head->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $source->getKey()]);
    }

    public function test_website_revisions_move_to_head_instead_of_cascading_away(): void
    {
        // The table has no migration in this repo; it exists on installed databases with ON DELETE CASCADE.
        if (! \Illuminate\Support\Facades\Schema::hasTable('website_revisions')) {
            \Illuminate\Support\Facades\Schema::create('website_revisions', function ($table) {
                $table->id();
                $table->unsignedBigInteger('website_id')->default(1);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->timestamps();
            });
        }

        $head = $this->makeUser();
        $source = $this->makeUser();

        $revisionId = DB::table('website_revisions')->insertGetId([
            'website_id' => 1,
            'user_id' => $source->getKey(),
            'name' => 'Home',
            'slug' => 'home',
        ]);

        UserMergeAction::run($head, $source);

        $this->assertDatabaseHas('website_revisions', ['id' => $revisionId, 'user_id' => $head->getKey()]);
    }

    public function test_account_cannot_be_merged_into_itself(): void
    {
        $user = $this->makeUser();

        $this->expectException(ValidationException::class);

        UserMergeAction::run($user, $user);
    }

    public function test_admin_account_cannot_be_the_source(): void
    {
        $head = $this->makeUser();
        $admin = $this->makeUser();

        Role::withoutGlobalScopes()->firstOrCreate([
            'name' => UserRole::Admin->value,
            'guard_name' => 'web',
            'conference_id' => 0,
            'scheduled_conference_id' => 0,
        ]);
        $admin->assignRole(UserRole::Admin->value);

        try {
            UserMergeAction::run($head, $admin);
            $this->fail('Merging an Admin account must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('users', ['id' => $admin->getKey()]);
        }
    }

    public function test_conference_manager_can_merge_accounts_that_only_exist_in_current_scheduled_conference(): void
    {
        $manager = $this->makeConferenceManager();
        $source = $this->makeUser();
        $this->assignScopedRole($source, UserRole::Reviewer, $this->scheduledA);

        $this->assertTrue((new UserPolicy)->merge($manager, $source));
        $this->assertTrue($manager->can('merge', $source));
    }

    public function test_first_permission_check_is_true_when_permission_row_does_not_exist_yet(): void
    {
        \App\Models\Permission::query()->where('name', 'User:merge')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $this->makeConferenceManager();

        $this->assertTrue($manager->can('User:merge'));
        $this->assertTrue($manager->can('User:merge'));
    }

    public function test_conference_manager_can_merge_account_with_roles_in_another_scheduled_conference(): void
    {
        $manager = $this->makeConferenceManager();
        $source = $this->makeUser();
        $this->assignScopedRole($source, UserRole::Reviewer, $this->scheduledA);
        $this->assignScopedRole($source, UserRole::Author, $this->scheduledB);

        $this->assertTrue((new UserPolicy)->merge($manager, $source));
    }

    public function test_conference_manager_cannot_merge_self_or_admin_or_on_site_level(): void
    {
        $manager = $this->makeConferenceManager();
        $source = $this->makeUser();
        $this->assignScopedRole($source, UserRole::Reviewer, $this->scheduledA);

        $this->assertFalse((new UserPolicy)->merge($manager, $manager));

        $admin = $this->makeAdmin();
        $this->assertFalse((new UserPolicy)->merge($manager, $admin));

        app()->setConferenceContext(null, null);
        $this->assertFalse((new UserPolicy)->merge($manager, $source));
    }

    public function test_admin_can_merge_accounts_across_conferences_but_not_self_or_another_admin(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeUser();
        $this->assignScopedRole($source, UserRole::Reviewer, $this->scheduledA);
        $this->assignScopedRole($source, UserRole::Author, $this->scheduledB);

        app()->setConferenceContext(null, null);

        $this->assertTrue((new UserPolicy)->merge($admin, $source));
        $this->assertFalse((new UserPolicy)->merge($admin, $admin));
        $this->assertFalse((new UserPolicy)->merge($admin, $this->makeAdmin()));
    }

    public function test_editor_assignment_is_released_when_merged_account_submitted_the_paper(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();
        $otherEditor = $this->makeUser();

        $submissionId = $this->createSubmissionFor($head);
        $this->addParticipant($submissionId, $head, UserRole::Author);
        $this->addParticipant($submissionId, $source, UserRole::TrackEditor);
        $this->addParticipant($submissionId, $otherEditor, UserRole::TrackEditor);

        UserMergeAction::run($head, $source);

        $this->assertDatabaseMissing('users', ['id' => $source->getKey()]);
        $this->assertSame(1, DB::table('submission_has_participants')->where('submission_id', $submissionId)->where('user_id', $head->getKey())->count());
        $this->assertDatabaseHas('submission_has_participants', ['submission_id' => $submissionId, 'user_id' => $otherEditor->getKey()]);

        $log = \Spatie\Activitylog\Models\Activity::query()->where('event', 'user-merge')->latest('id')->firstOrFail();
        $this->assertSame($source->email, $log->properties['merged_user']['email']);
        $this->assertCount(1, $log->properties['removed_participants']);
    }

    public function test_merge_is_rejected_when_it_would_leave_the_paper_without_an_editor(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();

        $submissionId = $this->createSubmissionFor($head);
        $this->addParticipant($submissionId, $head, UserRole::Author);
        $this->addParticipant($submissionId, $source, UserRole::TrackEditor);

        try {
            UserMergeAction::run($head, $source);
            $this->fail('Merge must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('users', ['id' => $source->getKey()]);
            $this->assertDatabaseHas('submission_has_participants', ['submission_id' => $submissionId, 'user_id' => $source->getKey()]);
        }
    }

    public function test_author_and_editor_on_different_papers_are_merged_untouched(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();

        $x = $this->createSubmissionFor($head);
        $y = $this->createSubmissionFor($this->makeUser());
        $this->addParticipant($x, $head, UserRole::Author);
        $this->addParticipant($y, $source, UserRole::TrackEditor);

        UserMergeAction::run($head, $source);

        $this->assertDatabaseHas('submission_has_participants', ['submission_id' => $x, 'user_id' => $head->getKey()]);
        $this->assertDatabaseHas('submission_has_participants', ['submission_id' => $y, 'user_id' => $head->getKey()]);
    }

    public function test_duplicate_reviews_keep_the_completed_one(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();
        $submissionId = $this->createSubmissionFor($this->makeUser());

        $pending = DB::table('reviews')->insertGetId(['submission_id' => $submissionId, 'user_id' => $head->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $done = DB::table('reviews')->insertGetId(['submission_id' => $submissionId, 'user_id' => $source->getKey(), 'recommendation' => 'accept', 'date_completed' => now(), 'created_at' => now(), 'updated_at' => now()]);

        UserMergeAction::run($head, $source);

        $this->assertDatabaseMissing('reviews', ['id' => $pending]);
        $this->assertDatabaseHas('reviews', ['id' => $done, 'user_id' => $head->getKey()]);
    }

    public function test_two_completed_reviews_on_one_paper_block_the_merge(): void
    {
        $head = $this->makeUser();
        $source = $this->makeUser();
        $submissionId = $this->createSubmissionFor($this->makeUser());

        foreach ([$head, $source] as $user) {
            DB::table('reviews')->insert(['submission_id' => $submissionId, 'user_id' => $user->getKey(), 'recommendation' => 'accept', 'date_completed' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->expectException(ValidationException::class);

        UserMergeAction::run($head, $source);
    }

    private function addParticipant(int $submissionId, User $user, UserRole $role): void
    {
        $roleId = Role::withoutGlobalScopes()
            ->where('name', $role->value)
            ->where('conference_id', $this->conference->getKey())
            ->where('scheduled_conference_id', $this->scheduledA->getKey())
            ->firstOrFail()
            ->getKey();

        DB::table('submission_has_participants')->insert([
            'submission_id' => $submissionId,
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAdmin(): User
    {
        Role::withoutGlobalScopes()->firstOrCreate([
            'name' => UserRole::Admin->value,
            'guard_name' => 'web',
            'conference_id' => 0,
            'scheduled_conference_id' => 0,
        ]);

        $admin = $this->makeUser();
        $admin->assignRole(UserRole::Admin->value);

        return $admin;
    }

    private function makeConferenceManager(): User
    {
        $role = Role::withoutGlobalScopes()
            ->where('name', UserRole::ConferenceManager->value)
            ->where('conference_id', $this->conference->getKey())
            ->firstOrFail();

        $manager = $this->makeUser();
        $manager->assignRole($role);

        return $manager;
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['password' => 'password123456']);
    }

    private function assignScopedRole(User $user, UserRole $role, ScheduledConference $scheduledConference): void
    {
        $model = Role::withoutGlobalScopes()
            ->where('name', $role->value)
            ->where('conference_id', $this->conference->getKey())
            ->where('scheduled_conference_id', $scheduledConference->getKey())
            ->firstOrFail();

        $user->assignRole($model);
    }

    private function assignmentCount(User $user): int
    {
        return DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('model_type', $user->getMorphClass())
            ->where(config('permission.column_names.model_morph_key', 'model_id'), $user->getKey())
            ->count();
    }

    private function createSubmissionFor(User $user): int
    {
        $track = \App\Models\Track::withoutGlobalScopes()
            ->where('scheduled_conference_id', $this->scheduledA->getKey())
            ->firstOrFail();

        return \App\Models\Submission::withoutGlobalScopes()->forceCreate([
            'user_id' => $user->getKey(),
            'conference_id' => $this->conference->getKey(),
            'scheduled_conference_id' => $this->scheduledA->getKey(),
            'track_id' => $track->getKey(),
            'stage' => \App\Models\Enums\SubmissionStage::CallforAbstract,
            'status' => \App\Models\Enums\SubmissionStatus::Incomplete,
        ])->getKey();
    }
}
