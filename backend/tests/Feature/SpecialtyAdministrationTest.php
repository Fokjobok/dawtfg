<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SpecialtyAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(UserRole $role = UserRole::Administrator): User
    {
        $user = new User(['name' => 'Test User', 'email' => 'actor@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();
        $this->actingAs($user);

        return $user;
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/specialties')->assertUnauthorized();
        $this->postJson('/api/v1/specialties', ['name' => 'Programming'])->assertUnauthorized();
    }

    #[DataProvider('otherRoles')]
    public function test_only_administrators_can_list_or_create_specialties(UserRole $role): void
    {
        $this->actor($role);
        $this->getJson('/api/v1/specialties')->assertForbidden();
        $this->postJson('/api/v1/specialties', ['name' => 'Programming'])->assertForbidden();
        $this->assertDatabaseCount('specialties', 0);
    }

    public static function otherRoles(): array
    {
        return [[UserRole::Coach], [UserRole::Student]];
    }

    public function test_list_is_paginated_sorted_private_and_excludes_user_details(): void
    {
        $this->actor();
        Specialty::create(['name' => 'Valorant']);
        $first = Specialty::create(['name' => 'Counter-Strike']);
        $this->getJson('/api/v1/specialties?per_page=1')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonMissingPath('data.0.coaches');
        $this->getJson('/api/v1/specialties?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.name', 'Valorant');
        $this->getJson('/api/v1/specialties?per_page=101&page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page']);
    }

    public function test_admin_creates_a_specialty_and_can_assign_it_only_to_a_coach(): void
    {
        $this->actor();
        $coach = new User(['name' => 'Test Coach', 'email' => 'coach@example.test', 'password' => 'Test-only-password-123']);
        $coach->role = UserRole::Coach;
        $coach->save();
        $student = User::create(['name' => 'Student', 'email' => 'student@example.test', 'password' => 'Test-only-password-123']);

        $response = $this->postJson('/api/v1/specialties', ['name' => '  Counter-Strike  '])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'Counter-Strike');
        $id = $response->json('data.id');
        $this->assertDatabaseCount('coach_specialty', 0);

        $this->patchJson('/api/v1/users/'.$coach->id, ['specialty_ids' => [$id]])
            ->assertOk()->assertJsonPath('data.specialties.0.id', $id);
        $this->patchJson('/api/v1/users/'.$student->id, ['specialty_ids' => [$id]])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_ids');
        $this->assertDatabaseMissing('coach_specialty', ['coach_id' => $student->id]);
        $this->assertDatabaseHas('coach_specialty', ['coach_id' => $coach->id, 'specialty_id' => $id]);
    }

    public function test_names_are_required_limited_and_unique_without_creating_duplicates(): void
    {
        $this->actor();
        Specialty::create(['name' => 'Valorant']);
        foreach (['', str_repeat('a', 101), 'Valorant', ' Valorant '] as $name) {
            $this->postJson('/api/v1/specialties', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->assertDatabaseCount('specialties', 1);
    }

    public function test_creation_requires_csrf_even_without_origin(): void
    {
        $this->actor();
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/specialties', ['name' => 'Programming'])->assertStatus(419);
        $this->withSession(['_token' => 'specialty-test-token'])->withHeader('X-CSRF-TOKEN', 'specialty-test-token')
            ->postJson('/api/v1/specialties', ['name' => 'Programming'])->assertCreated();
    }
}
