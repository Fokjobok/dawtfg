<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, UserRole $role = UserRole::Student): User
    {
        $user = new User(['name' => 'Test User', 'email' => $email, 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function administrator(): User
    {
        $admin = $this->user('admin@example.test', UserRole::Administrator);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        $target = $this->user('target@example.test');
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->get('/api/v1/users')->assertUnauthorized()->assertExactJson(['error' => 'Unauthorized', 'code' => 401]);
        $this->patchJson('/api/v1/users/'.$target->id, ['name' => 'Changed'])->assertUnauthorized();
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_other_roles_cannot_list_or_edit_users(UserRole $role): void
    {
        $actor = $this->user('actor@example.test', $role);
        $target = $this->user('target@example.test');
        $this->actingAs($actor);
        $this->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('code', 403);
        $this->patchJson('/api/v1/users/'.$target->id, ['role' => 'Administrator'])->assertForbidden();
        $this->assertSame(UserRole::Student, $target->fresh()->role);
    }

    public static function nonAdministrativeRoles(): array
    {
        return [[UserRole::Coach], [UserRole::Student]];
    }

    public function test_list_is_paginated_filtered_sorted_and_private(): void
    {
        $this->administrator();
        $first = $this->user('a@example.test', UserRole::Coach);
        $last = $this->user('z@example.test', UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Programming']);
        $last->specialties()->attach($specialty->id);

        $this->getJson('/api/v1/users?filter[role]=Coach&sort=-email&per_page=1')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $last->id)
            ->assertJsonPath('data.0.specialties.0.name', 'Programming')
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
        $this->getJson('/api/v1/users?filter[role]=Coach&sort=-email&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $first->id);
    }

    public function test_invalid_list_parameters_are_rejected(): void
    {
        $this->administrator();
        $this->getJson('/api/v1/users?per_page=101&page=0&sort=password&filter[role]=Other')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page', 'sort', 'filter.role']);
    }

    public function test_administrators_are_excluded_from_results_and_pagination_even_when_requested(): void
    {
        $this->administrator();
        $this->user('second-admin@example.test', UserRole::Administrator);
        $coach = $this->user('coach@example.test', UserRole::Coach);
        $student = $this->user('student@example.test');

        $this->getJson('/api/v1/users?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $coach->id);
        $this->getJson('/api/v1/users?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $student->id);
        $this->getJson('/api/v1/users?filter[role]=Administrator')->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_administrator_can_edit_a_user_and_assign_coach_specialties(): void
    {
        $this->administrator();
        $target = $this->user('target@example.test');
        $password = $target->password;
        $specialty = Specialty::create(['name' => 'Programming']);

        $this->patchJson('/api/v1/users/'.$target->id, [
            'name' => 'New Coach', 'email' => ' COACH@EXAMPLE.TEST ',
            'role' => 'Coach', 'specialty_ids' => [$specialty->id],
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'New Coach')->assertJsonPath('data.email', 'coach@example.test')
            ->assertJsonPath('data.role', 'Coach')->assertJsonPath('data.specialties.0.id', $specialty->id)
            ->assertJsonMissingPath('data.password');
        $this->assertSame($password, $target->fresh()->password);
        $this->assertDatabaseHas('coach_specialty', ['coach_id' => $target->id, 'specialty_id' => $specialty->id]);
    }

    public function test_a_partial_update_keeps_role_and_specialties_and_resets_email_verification(): void
    {
        $this->administrator();
        $coach = $this->user('coach@example.test', UserRole::Coach);
        $coach->email_verified_at = now();
        $coach->save();
        $specialty = Specialty::create(['name' => 'Programming']);
        $coach->specialties()->attach($specialty->id);

        $this->patchJson('/api/v1/users/'.$coach->id, ['email' => 'new@example.test'])
            ->assertOk()->assertJsonPath('data.role', 'Coach')->assertJsonCount(1, 'data.specialties');
        $this->assertNull($coach->fresh()->email_verified_at);
    }

    public function test_self_demotion_is_rejected(): void
    {
        $admin = $this->administrator();
        $this->patchJson('/api/v1/users/'.$admin->id, ['role' => 'Student'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame(UserRole::Administrator, $admin->fresh()->role);
    }

    public function test_role_change_detaches_specialties_when_no_courses_depend_on_the_coach(): void
    {
        $this->administrator();
        $coach = $this->user('coach@example.test', UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Programming']);
        $coach->specialties()->attach($specialty->id);
        $this->patchJson('/api/v1/users/'.$coach->id, ['role' => 'Student'])
            ->assertOk()->assertJsonPath('data.specialties', []);
        $this->assertDatabaseCount('coach_specialty', 0);
    }

    public function test_a_coach_with_courses_keeps_required_role_and_specialties(): void
    {
        $this->administrator();
        $coach = $this->user('coach@example.test', UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Programming']);
        $coach->specialties()->attach($specialty->id);
        DB::table('courses')->insert(['title' => 'Test course', 'description' => 'Fixture', 'coach_id' => $coach->id, 'specialty_id' => $specialty->id]);

        $this->patchJson('/api/v1/users/'.$coach->id, ['role' => 'Student', 'name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson('/api/v1/users/'.$coach->id, ['specialty_ids' => [], 'name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_ids');
        $this->assertSame('Test User', $coach->fresh()->name);
        $this->assertDatabaseCount('coach_specialty', 1);
    }

    public function test_invalid_user_fields_and_unknown_specialties_are_rejected(): void
    {
        $this->administrator();
        $target = $this->user('target@example.test');
        $this->patchJson('/api/v1/users/'.$target->id, [
            'name' => '', 'email' => 'admin@example.test', 'role' => 'Owner',
            'specialty_ids' => [999999, 999999], 'password' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role', 'specialty_ids.0', 'password']);
    }

    public function test_specialties_are_rejected_for_non_coaches(): void
    {
        $this->administrator();
        $target = $this->user('target@example.test');
        $specialty = Specialty::create(['name' => 'Programming']);
        $this->patchJson('/api/v1/users/'.$target->id, ['specialty_ids' => [$specialty->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_ids');
    }

    public function test_missing_user_returns_not_found(): void
    {
        $this->administrator();
        $this->patchJson('/api/v1/users/999999', ['name' => 'New name'])->assertNotFound();
    }

    public function test_edit_requires_csrf_even_without_an_origin_header(): void
    {
        $admin = $this->administrator();
        $this->app->instance('env', 'local');
        $this->patchJson('/api/v1/users/'.$admin->id, ['name' => 'Changed'])->assertStatus(419);
        $this->withSession(['_token' => 'admin-test-csrf'])->withHeader('X-CSRF-TOKEN', 'admin-test-csrf')
            ->patchJson('/api/v1/users/'.$admin->id, ['name' => 'Updated Admin'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Admin');
    }
}
