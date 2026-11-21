<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SessionStatusTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Student): User
    {
        $user = new User(['name' => 'Test User', 'email' => 'session@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_anonymous_session_query_is_a_private_success_without_changing_protected_routes(): void
    {
        $this->get('/api/v1/session')->assertOk()->assertExactJson(['data' => null])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->head('/api/v1/session')->assertOk()->assertContent('')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/specialties')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_each_role_receives_only_its_own_public_identity_fields(UserRole $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user, 'web')->getJson('/api/v1/session')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role->value, 'avatar_url' => null]]);
    }

    public static function roles(): array
    {
        return array_map(fn (UserRole $role) => [$role], UserRole::cases());
    }

    public function test_session_status_tracks_login_and_logout(): void
    {
        $user = $this->user();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Test-only-password-123'])->assertOk();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data', null);
    }

    public function test_query_parameters_cannot_select_a_different_identity(): void
    {
        $user = $this->user();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'Test-only-password-123']);
        $this->actingAs($user, 'web')->getJson('/api/v1/session?user_id='.$other->id)
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissing(['email' => $other->email]);
    }

    public function test_bearer_input_does_not_authenticate_a_session(): void
    {
        $this->withToken('unrecognized')->getJson('/api/v1/session')->assertOk()->assertExactJson(['data' => null]);
    }
}
