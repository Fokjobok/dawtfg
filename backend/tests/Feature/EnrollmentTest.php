<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

final class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Student, array $attributes = []): User
    {
        $user = new User(['name' => 'Learner '.User::count(), 'email' => 'enrollment'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->forceFill(['role' => $role, ...$attributes])->saveOrFail();

        return $user->fresh();
    }

    private function course(array $attributes = []): Course
    {
        $coach = $this->user(UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Subject '.Specialty::count()]);
        $coach->specialties()->attach($specialty);
        $course = new Course;
        $course->forceFill(['title' => 'Course '.Course::count(), 'description' => 'Learn with this course.', 'coach_id' => $coach->id, 'specialty_id' => $specialty->id, 'price' => '0.00', 'status' => 'published', ...$attributes])->saveOrFail();

        return $course->fresh();
    }

    public function test_students_enroll_once_and_can_read_and_withdraw_their_own_enrollment(): void
    {
        $student = $this->user();
        $course = $this->course();
        $response = $this->actingAs($student, 'web')->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertCreated();
        $id = $response->json('data.id');
        $response->assertHeader('Location', '/api/v1/enrollments/'.$id)->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.course.id', $course->id)->assertJsonPath('data.featured_order', null)->assertJsonMissingPath('data.user_id');
        $this->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('enrollments', 1);
        $this->getJson('/api/v1/enrollments/'.$id)->assertOk();
        $this->head('/api/v1/enrollments/'.$id)->assertOk()->assertContent('');
        $this->getJson('/api/v1/enrollments')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/enrollments/'.$id)->assertNoContent();
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertDatabaseHas('courses', ['id' => $course->id]);
    }

    public function test_guests_and_non_students_cannot_create_enrollments(): void
    {
        $course = $this->course();
        $this->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertUnauthorized();
        $this->getJson('/api/v1/enrollments')->assertUnauthorized();
        foreach ([UserRole::Coach, UserRole::Administrator] as $role) {
            $this->actingAs($this->user($role), 'web')->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertForbidden();
        }
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_paid_draft_and_incomplete_courses_reject_new_enrollments(): void
    {
        $this->actingAs($this->user(), 'web');
        $paid = $this->course(['price' => '10.00']);
        $this->postJson('/api/v1/courses/'.$paid->id.'/enrollments')->assertUnprocessable()->assertJsonValidationErrors('course');
        foreach ([['status' => 'draft'], ['coach_id' => null], ['specialty_id' => null]] as $attributes) {
            $course = $this->course($attributes);
            $this->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertNotFound();
        }
        $this->postJson('/api/v1/courses/999999/enrollments')->assertNotFound();
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_ownership_and_identity_are_enforced_including_against_administrators(): void
    {
        $student = $this->user();
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $student->id]);
        foreach ([UserRole::Student, UserRole::Coach, UserRole::Administrator] as $role) {
            $this->actingAs($this->user($role), 'web')->getJson('/api/v1/enrollments')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/enrollments/'.$enrollment->id)->assertForbidden();
            $this->deleteJson('/api/v1/enrollments/'.$enrollment->id)->assertForbidden();
        }
        $this->actingAs($student, 'web');
        foreach (['user_id' => 999, 'course_id' => 999, 'price' => 1, 'status' => 'active', 'featured_order' => 1] as $field => $value) {
            $this->postJson('/api/v1/courses/'.$course->id.'/enrollments', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_own_list_is_paginated_and_can_batch_filter_catalog_course_ids(): void
    {
        $student = $this->user();
        $ids = [];
        foreach (range(1, 3) as $number) {
            $course = $this->course();
            $ids[] = $course->id;
            $course->enrollments()->create(['user_id' => $student->id]);
        }
        $this->actingAs($student, 'web')->getJson('/api/v1/enrollments?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->head('/api/v1/enrollments')->assertOk()->assertContent('');
        $this->getJson('/api/v1/enrollments?'.http_build_query(['filter' => ['course_ids' => [$ids[0], $ids[2]]]]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        foreach (['per_page=101', 'page=0', 'filter[course_ids][]=0', 'filter[course_ids]=invalid'] as $query) {
            $this->getJson('/api/v1/enrollments?'.$query)->assertUnprocessable();
        }
    }

    public function test_existing_enrollments_remain_private_and_withdrawable_after_course_or_role_changes(): void
    {
        $student = $this->user();
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $student->id]);
        $course->status = 'draft';
        $course->save();
        $this->actingAs($student, 'web')->getJson('/api/v1/enrollments')->assertOk()->assertJsonPath('data.0.course', null)
            ->assertJsonPath('data.0.course_id', $course->id);
        $this->getJson('/api/v1/courses/'.$course->id)->assertForbidden();
        $student->role = UserRole::Coach;
        $student->save();
        $this->actingAs($student->fresh(), 'web')->deleteJson('/api/v1/enrollments/'.$enrollment->id)->assertNoContent();
    }

    public function test_enrollment_authorization_is_rechecked_with_the_locked_identity(): void
    {
        $student = $this->user();
        $stale = $student->fresh();
        $course = $this->course();
        $student->role = UserRole::Coach;
        $student->save();
        $this->actingAs($stale, 'web')->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertForbidden();
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_enrollment_and_withdrawal_require_csrf_even_without_origin(): void
    {
        $student = $this->user();
        $course = $this->course();
        $this->actingAs($student, 'web');
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertStatus(419);
        $token = 'test-only-enrollment-token';
        $response = $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertCreated();
        $this->withHeader('X-CSRF-TOKEN', 'invalid')->deleteJson('/api/v1/enrollments/'.$response->json('data.id'))->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', $token)->deleteJson('/api/v1/enrollments/'.$response->json('data.id'))->assertNoContent();
    }

    #[DataProvider('privacy')]
    public function test_featured_courses_respect_global_and_section_privacy(bool $private, bool $sectionPrivate): void
    {
        $owner = $this->user(attributes: ['is_private' => $private, 'enrollments_is_private' => $sectionPrivate]);
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $owner->id]);
        $enrollment->featured_order = 1;
        $enrollment->save();
        foreach ([null, $this->user(), $this->user(UserRole::Administrator)] as $viewer) {
            $this->app['auth']->forgetGuards();
            if ($viewer !== null) {
                $this->actingAs($viewer, 'web');
            }
            $response = $this->getJson('/api/v1/profiles/'.$owner->id)->assertOk()
                ->assertJsonMissingPath('data.featured_course_ids')->assertJsonMissingPath('data.enrollments_is_private');
            if ($private || $sectionPrivate) {
                $response->assertJsonMissingPath('data.featured_courses');
            } else {
                $response->assertJsonPath('data.featured_courses.0.id', $course->id)->assertJsonMissingPath('data.featured_courses.0.cover_path');
            }
        }
        $this->actingAs($owner, 'web')->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.featured_courses.0.id', $course->id)
            ->assertJsonPath('data.featured_course_ids', [$course->id])->assertJsonPath('data.enrollments_is_private', $sectionPrivate);
        $this->getJson('/api/v1/session')->assertOk()->assertJsonMissingPath('data.featured_courses')->assertJsonMissingPath('data.enrollments_is_private');
    }

    public static function privacy(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    public function test_feature_selection_is_ordered_limited_to_three_and_restricted_to_own_enrollments(): void
    {
        $owner = $this->user();
        $courses = [];
        foreach (range(1, 4) as $number) {
            $course = $this->course();
            $course->enrollments()->create(['user_id' => $owner->id]);
            $courses[] = $course;
        }
        $ids = array_column(array_reverse($courses), 'id');
        $selected = array_slice($ids, 0, 3);
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['featured_course_ids' => $selected])->assertOk()
            ->assertJsonPath('meta.updated', true)->assertJsonPath('data.featured_course_ids', $selected)
            ->assertJsonPath('data.featured_courses.0.id', $selected[0])->assertJsonPath('data.save_limits.remaining_updates', 2);
        $before = $owner->fresh()->updated_at;
        $this->patchJson('/api/v1/profile', ['featured_course_ids' => $selected])->assertOk()->assertJsonPath('meta.updated', false);
        $this->assertTrue($owner->fresh()->updated_at->equalTo($before));
        foreach ([$ids, [$ids[0], $ids[0]], null, '{}', [999999]] as $invalid) {
            $this->patchJson('/api/v1/profile', ['featured_course_ids' => $invalid])->assertUnprocessable();
        }
        $otherCourse = $this->course();
        $otherCourse->enrollments()->create(['user_id' => $this->user()->id]);
        $this->patchJson('/api/v1/profile', ['featured_course_ids' => [$otherCourse->id]])->assertUnprocessable()->assertJsonValidationErrors('featured_course_ids');
        $this->post('/api/v1/profile', ['_method' => 'PATCH', 'featured_course_ids' => '[]', 'enrollments_is_private' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.featured_courses', [])->assertJsonPath('data.enrollments_is_private', true);
    }

    public function test_feature_only_edits_obey_the_profile_cooldown_and_withdrawal_cleans_the_selection(): void
    {
        $this->freezeTime();
        $owner = $this->user(attributes: ['profile_save_count' => 2]);
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $owner->id]);
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['featured_course_ids' => [$course->id]])->assertOk()->assertJsonPath('data.save_limits.retry_after', 60);
        $this->patchJson('/api/v1/profile', ['featured_course_ids' => []])->assertStatus(429);
        $this->assertSame(1, $enrollment->fresh()->featured_order);
        $this->deleteJson('/api/v1/enrollments/'.$enrollment->id)->assertNoContent();
        $this->getJson('/api/v1/profiles/'.$owner->id)->assertOk()->assertJsonPath('data.featured_courses', [])->assertJsonPath('data.featured_course_ids', []);
    }

    public function test_unpublished_courses_never_leak_through_featured_cards(): void
    {
        $owner = $this->user();
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $owner->id]);
        $enrollment->featured_order = 1;
        $enrollment->save();
        $course->status = 'draft';
        $course->save();
        $this->getJson('/api/v1/profiles/'.$owner->id)->assertOk()->assertJsonPath('data.featured_courses', []);
        $this->actingAs($owner, 'web')->getJson('/api/v1/enrollments')->assertOk()->assertJsonPath('data.0.course', null);
        $course->delete();
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_database_constraints_reject_duplicates_and_invalid_feature_positions(): void
    {
        $owner = $this->user();
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $owner->id]);
        try {
            DB::transaction(fn () => $course->enrollments()->create(['user_id' => $owner->id]));
            $this->fail('Duplicate enrollment should fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('enrollments', 1);
        }
        try {
            DB::transaction(fn () => $enrollment->forceFill(['featured_order' => 4])->saveOrFail());
            $this->fail('Invalid featured position should fail.');
        } catch (QueryException) {
            $this->assertNull($enrollment->fresh()->featured_order);
        }
    }

    public function test_failed_profile_save_rolls_back_feature_selection_and_counter(): void
    {
        $owner = $this->user();
        $course = $this->course();
        $enrollment = $course->enrollments()->create(['user_id' => $owner->id]);
        Log::spy();
        User::updating(fn () => false);
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['featured_course_ids' => [$course->id]])->assertStatus(500);
        $this->assertNull($enrollment->fresh()->featured_order);
        $this->assertSame(0, $owner->fresh()->profile_save_count);
    }

    public function test_feature_positions_are_unique_per_user_and_migration_preserves_existing_account_and_courses(): void
    {
        $owner = $this->user(attributes: ['bio' => 'Biography.', 'is_private' => true]);
        $course = $this->course();
        $first = $course->enrollments()->create(['user_id' => $owner->id]);
        $first->featured_order = 1;
        $first->save();
        $second = $this->course()->enrollments()->create(['user_id' => $owner->id]);
        try {
            DB::transaction(fn () => $second->forceFill(['featured_order' => 1])->saveOrFail());
            $this->fail('Featured positions should be unique per user.');
        } catch (QueryException) {
            $this->assertNull($second->fresh()->featured_order);
        }
        $migration = require database_path('migrations/2026_11_23_000001_create_enrollments_table.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'bio' => 'Biography.', 'is_private' => true]);
        $this->assertDatabaseHas('courses', ['id' => $course->id]);
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'bio' => 'Biography.', 'enrollments_is_private' => false]);
        $this->assertDatabaseCount('enrollments', 0);
    }
}
