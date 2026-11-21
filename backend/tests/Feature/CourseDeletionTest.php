<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CourseDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class CourseDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => 'Coach '.User::count(), 'email' => 'deletion'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function fixture(): array
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $stranger = $this->user();
        $admin = $this->user(UserRole::Administrator);
        $student = $this->user(UserRole::Student);
        $specialty = Specialty::create(['name' => 'Programming']);
        foreach ([$owner, $collaborator, $stranger] as $coach) {
            $coach->specialties()->attach($specialty);
        }
        $course = new Course;
        $course->forceFill(['title' => 'Delete target', 'description' => 'Description.', 'coach_id' => $owner->id, 'specialty_id' => $specialty->id, 'status' => 'published', 'price' => 0, 'cover_path' => 'courses/covers/target.png'])->save();
        $course->additionalCoaches()->attach($collaborator);
        Storage::disk('local')->put($course->cover_path, 'Fixture cover bytes');

        return compact('owner', 'collaborator', 'stranger', 'admin', 'student', 'specialty', 'course');
    }

    #[DataProvider('allowedActors')]
    public function test_owner_and_admin_can_delete_without_removing_other_courses_users_or_specialties(string $actor): void
    {
        $f = $this->fixture();
        $other = new Course;
        $other->forceFill(['title' => 'Keep', 'description' => 'Keep', 'coach_id' => $f['owner']->id, 'specialty_id' => $f['specialty']->id, 'status' => 'published', 'price' => 0])->save();
        $this->actingAs($f[$actor])->deleteJson('/api/v1/courses/'.$f['course']->id)
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseMissing('courses', ['id' => $f['course']->id]);
        $this->assertDatabaseMissing('course_coach', ['course_id' => $f['course']->id]);
        $this->assertDatabaseHas('courses', ['id' => $other->id, 'title' => 'Keep']);
        $this->assertDatabaseHas('users', ['id' => $f['owner']->id]);
        $this->assertDatabaseHas('users', ['id' => $f['collaborator']->id]);
        $this->assertDatabaseHas('specialties', ['id' => $f['specialty']->id]);
        Storage::disk('local')->assertMissing($f['course']->cover_path);
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $other->id);
    }

    public static function allowedActors(): array
    {
        return [['owner'], ['admin']];
    }

    #[DataProvider('deniedActors')]
    public function test_other_roles_cannot_delete_or_spoof_the_responsible_coach(string $actor): void
    {
        $f = $this->fixture();
        $this->actingAs($f[$actor])->deleteJson('/api/v1/courses/'.$f['course']->id, ['coach_id' => $f[$actor]->id])->assertForbidden();
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id, 'coach_id' => $f['owner']->id]);
        $this->assertDatabaseHas('course_coach', ['course_id' => $f['course']->id, 'coach_id' => $f['collaborator']->id]);
        Storage::disk('local')->assertExists($f['course']->cover_path);
    }

    public static function deniedActors(): array
    {
        return [['collaborator'], ['stranger'], ['student']];
    }

    public function test_guests_cannot_delete_courses(): void
    {
        $f = $this->fixture();
        $this->deleteJson('/api/v1/courses/'.$f['course']->id)->assertUnauthorized();
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id]);
        Storage::disk('local')->assertExists($f['course']->cover_path);
    }

    public function test_delete_capability_is_private_and_matches_each_editors_permission(): void
    {
        $f = $this->fixture();
        foreach (['owner' => true, 'admin' => true, 'collaborator' => false] as $actor => $allowed) {
            $this->actingAs($f[$actor])->getJson('/api/v1/teaching/courses')->assertOk()->assertJsonPath('data.0.can_delete', $allowed);
            $this->getJson('/api/v1/courses/'.$f['course']->id)->assertOk()->assertJsonPath('data.can_delete', $allowed);
        }
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonMissingPath('data.0.can_delete');
    }

    public function test_deletion_requires_csrf_even_without_origin(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['owner']);
        $this->app->instance('env', 'local');
        $this->deleteJson($path)->assertStatus(419);
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id]);
        $this->withSession(['_token' => 'deletion-test-token'])->withHeader('X-CSRF-TOKEN', 'deletion-test-token')
            ->deleteJson($path)->assertNoContent();
    }

    #[DataProvider('databaseFailures')]
    public function test_failed_database_deletion_preserves_course_team_and_file(bool $throw): void
    {
        $f = $this->fixture();
        Log::spy();
        Course::deleting(function () use ($throw) {
            if ($throw) {
                throw new RuntimeException('Test-only database deletion failure.');
            }

            return false;
        });
        try {
            $this->actingAs($f['owner'])->deleteJson('/api/v1/courses/'.$f['course']->id)->assertStatus(500);
        } finally {
            Course::flushEventListeners();
        }
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id]);
        $this->assertDatabaseHas('course_coach', ['course_id' => $f['course']->id]);
        Storage::disk('local')->assertExists($f['course']->cover_path);
        Log::shouldHaveReceived('error')->once();
    }

    public static function databaseFailures(): array
    {
        return [[true], [false]];
    }

    public function test_storage_failure_is_reported_without_falsely_failing_a_committed_deletion(): void
    {
        $f = $this->fixture();
        $disk = Storage::disk('local');
        Log::spy();
        Storage::shouldReceive('disk')->once()->with('local')->andThrow(new RuntimeException('Test-only cleanup failure.'));
        $this->actingAs($f['admin'])->deleteJson('/api/v1/courses/'.$f['course']->id)->assertNoContent();
        $this->assertDatabaseMissing('courses', ['id' => $f['course']->id]);
        $this->assertTrue($disk->exists($f['course']->cover_path));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_service_rechecks_the_current_role(): void
    {
        $f = $this->fixture();
        User::whereKey($f['admin']->id)->update(['role' => UserRole::Student]);
        $this->expectException(AuthorizationException::class);
        app(CourseDeletionService::class)->delete($f['admin'], $f['course']);
    }

    public function test_service_rechecks_the_current_responsible_coach(): void
    {
        $f = $this->fixture();
        Course::whereKey($f['course']->id)->update(['coach_id' => $f['stranger']->id]);
        $this->expectException(AuthorizationException::class);
        app(CourseDeletionService::class)->delete($f['owner'], $f['course']);
    }

    public function test_missing_courses_return_not_found(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin'])->deleteJson('/api/v1/courses/999999')->assertNotFound();
        $this->assertDatabaseCount('courses', 1);
    }
}
