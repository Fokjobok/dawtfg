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
use Illuminate\Support\Facades\Schema;

final class CourseCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => $name, 'email' => 'user'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function details(User $owner): array
    {
        $specialty = Specialty::create(['name' => 'Programming']);
        $owner->specialties()->attach($specialty);

        return ['title' => 'JavaScript', 'description' => 'Functions and objects.', 'specialty_id' => $specialty->id, 'price' => '0.00'];
    }

    public function test_coach_creates_a_course_with_other_coaches_of_the_same_specialty(): void
    {
        $owner = $this->user('Owner');
        $details = $this->details($owner);
        $first = $this->user('Alice');
        $second = $this->user('Bob');
        $first->specialties()->attach($details['specialty_id']);
        $second->specialties()->attach($details['specialty_id']);

        $response = $this->actingAs($owner)->postJson('/api/v1/courses', [...$details, 'additional_coach_ids' => [$second->id, $first->id]])
            ->assertCreated()->assertJsonPath('data.coach_id', $owner->id)
            ->assertJsonPath('data.additional_coaches', [['id' => $first->id, 'name' => 'Alice'], ['id' => $second->id, 'name' => 'Bob']]);
        $this->assertDatabaseCount('course_coach', 2);
        $course = Course::findOrFail($response->json('data.id'));
        $this->assertTrue($course->coach->is($owner));
        $this->assertCount(2, $course->additionalCoaches);
        $this->assertDatabaseMissing('course_coach', ['course_id' => $course->id, 'coach_id' => $owner->id]);

        $admin = $this->user('Admin', UserRole::Administrator);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$first->id, ['role' => 'Student'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson('/api/v1/users/'.$first->id, ['specialty_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_ids');
        $this->assertDatabaseHas('coach_specialty', ['coach_id' => $first->id, 'specialty_id' => $details['specialty_id']]);
    }

    public function test_invalid_additional_coaches_are_rejected_without_creating_a_course(): void
    {
        $owner = $this->user('Owner');
        $details = $this->details($owner);
        $valid = $this->user('Valid');
        $valid->specialties()->attach($details['specialty_id']);
        $outsider = $this->user('Outsider');
        $student = $this->user('Student', UserRole::Student);
        $admin = $this->user('Admin', UserRole::Administrator);
        // Even invalid role memberships inserted outside the API cannot grant course access.
        $student->specialties()->attach($details['specialty_id']);
        $admin->specialties()->attach($details['specialty_id']);
        $this->actingAs($owner);

        foreach ([[$owner->id], [$valid->id, $outsider->id], [$student->id], [$admin->id], [999999], [$valid->id, $valid->id], null, 'invalid', array_fill(0, 101, $valid->id)] as $ids) {
            $response = $this->postJson('/api/v1/courses', [...$details, 'additional_coach_ids' => $ids])->assertUnprocessable();
            $this->assertNotEmpty(array_filter(array_keys($response->json('errors')), fn ($key) => str_starts_with($key, 'additional_coach_ids')));
        }
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('course_coach', 0);
    }

    public function test_coaches_are_listed_only_after_an_authorized_specialty_is_selected(): void
    {
        $owner = $this->user('Owner');
        $details = $this->details($owner);
        $first = $this->user('Alice');
        $second = $this->user('Bob');
        $outsider = $this->user('Outsider');
        $student = $this->user('Student', UserRole::Student);
        foreach ([$first, $second, $student] as $user) {
            $user->specialties()->attach($details['specialty_id']);
        }
        $path = '/api/v1/specialties/'.$details['specialty_id'].'/coaches';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($student)->getJson($path)->assertForbidden();
        $this->actingAs($outsider)->getJson($path)->assertForbidden();

        $this->actingAs($owner)->getJson($path.'?per_page=1')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data', [['id' => $first->id, 'name' => 'Alice']]);
        $this->getJson($path.'?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->getJson($path.'?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
        $this->head($path)->assertOk()->assertContent('');
        $this->getJson('/api/v1/specialties/999999/coaches')->assertNotFound();

        $admin = $this->user('Admin', UserRole::Administrator);
        $this->actingAs($admin)->getJson($path)->assertOk()->assertJsonPath('meta.total', 3);
        $owner->specialties()->detach();
        $this->actingAs($owner)->getJson($path)->assertForbidden();
    }

    public function test_new_coach_joining_the_specialty_becomes_available_for_selection(): void
    {
        $owner = $this->user('Owner');
        $details = $this->details($owner);
        $other = $this->user('Other');
        $path = '/api/v1/specialties/'.$details['specialty_id'].'/coaches';
        $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('data', [['id' => $owner->id, 'name' => 'Owner']]);
        $admin = $this->user('Admin', UserRole::Administrator);
        $this->actingAs($admin)->patchJson('/api/v1/users/'.$other->id, ['specialty_ids' => [$details['specialty_id']]])->assertOk();
        $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('data', [['id' => $other->id, 'name' => 'Other'], ['id' => $owner->id, 'name' => 'Owner']]);
        $this->postJson('/api/v1/courses', [...$details, 'additional_coach_ids' => [$other->id]])->assertCreated();
    }

    public function test_membership_schema_preserves_courses_on_rollback_and_restricts_user_deletion(): void
    {
        $owner = $this->user('Owner');
        $details = $this->details($owner);
        $other = $this->user('Other');
        $other->specialties()->attach($details['specialty_id']);
        $id = $this->actingAs($owner)->postJson('/api/v1/courses', [...$details, 'additional_coach_ids' => [$other->id]])->assertCreated()->json('data.id');

        foreach ([fn () => DB::table('course_coach')->insert(['course_id' => $id, 'coach_id' => $other->id]), fn () => $other->delete()] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('The database accepted an invalid course membership operation.');
            } catch (QueryException) {
                $this->assertDatabaseHas('course_coach', ['course_id' => $id, 'coach_id' => $other->id]);
            }
        }

        $migration = require database_path('migrations/2026_11_17_000001_create_course_coach_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('course_coach'));
        $this->assertDatabaseHas('courses', ['id' => $id, 'coach_id' => $owner->id]);
        $migration->up();
        $this->assertTrue(Schema::hasTable('course_coach'));
        $course = Course::findOrFail($id);
        $course->additionalCoaches()->attach($other);
        $course->delete();
        $this->assertDatabaseCount('course_coach', 0);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }
}
