<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password-only-for-tests-123';

    private function createUser(UserRole $role = UserRole::Student): User
    {
        $user = new User(['name' => 'Test User', 'email' => 'user@example.test', 'password' => self::PASSWORD]);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function registration(): array
    {
        return ['name' => 'Student', 'email' => 'student@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_registration_creates_a_student_and_authenticates_the_session(): void
    {
        $this->withSession([]);
        $oldSession = session()->getId();

        $this->postJson('/api/v1/register', $this->registration())
            ->assertCreated()
            ->assertHeader('Location', '/api/v1/user')
            ->assertJsonPath('data.role', 'Student')
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'student@example.test')->firstOrFail();
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
    }

    #[DataProvider('privilegedRoles')]
    public function test_public_registration_rejects_privileged_roles(string $role): void
    {
        $this->postJson('/api/v1/register', [...$this->registration(), 'role' => $role])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseCount('users', 0);
    }

    public static function privilegedRoles(): array
    {
        return [['Administrator'], ['Coach']];
    }

    public function test_registration_rejects_invalid_fields_and_confirmation(): void
    {
        $this->postJson('/api/v1/register', ['name' => '', 'email' => 'invalid', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_an_existing_normalized_email(): void
    {
        $this->createUser();
        $this->postJson('/api/v1/register', [...$this->registration(), 'email' => 'USER@EXAMPLE.TEST'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_regenerates_the_session(): void
    {
        $user = $this->createUser();
        $this->withSession([]);
        $oldSession = session()->getId();

        $this->postJson('/api/v1/login', ['email' => 'USER@EXAMPLE.TEST', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
    }

    public function test_registration_rejects_passwords_above_the_bcrypt_byte_limit(): void
    {
        $password = str_repeat('á', 37);
        $this->postJson('/api/v1/register', [...$this->registration(), 'password' => $password, 'password_confirmation' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_credentials_keep_the_request_anonymous(): void
    {
        $this->createUser();
        $this->postJson('/api/v1/login', ['email' => 'user@example.test', 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_login_rejects_extra_bytes_after_a_maximum_length_password(): void
    {
        $password = str_repeat('a', 72);
        User::create(['name' => 'Test User', 'email' => 'long@example.test', 'password' => $password]);

        $this->postJson('/api/v1/login', ['email' => 'long@example.test', 'password' => $password.'suffix'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertGuest('web');
    }

    public function test_registration_rejects_passwords_containing_null_bytes(): void
    {
        $password = "Password-with-null\0-byte-123";
        $this->postJson('/api/v1/register', [...$this->registration(), 'password' => $password, 'password_confirmation' => $password])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->createUser();
        $this->actingAs($user, 'web')->withSession(['marker' => 'old-session']);
        $oldSession = session()->getId();

        $this->postJson('/api/v1/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNull(session('marker'));
    }

    public function test_anonymous_requests_cannot_read_the_current_user_or_logout(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized()->assertJsonPath('code', 401);
        $this->postJson('/api/v1/logout')->assertUnauthorized();
    }

    public function test_bearer_input_does_not_require_a_token_database(): void
    {
        $this->withToken('unrecognized')->getJson('/api/v1/user')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_each_role_can_read_its_own_identity(UserRole $role): void
    {
        $user = $this->createUser($role);
        $this->actingAs($user, 'web')->getJson('/api/v1/user')
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role->value]]);

        $other = new User;
        $other->id = $user->id + 1;
        $this->assertSame($role === UserRole::Administrator, Gate::forUser($user)->allows('view', $other));
    }

    public static function roles(): array
    {
        return array_map(fn (UserRole $role) => [$role], UserRole::cases());
    }

    public function test_a_denied_profile_policy_returns_http_403(): void
    {
        $user = $this->createUser();
        $other = new User;
        $other->id = $user->id + 1;
        $this->actingAs($user, 'web');
        $this->app->bind(CurrentUserController::class, function () use ($other) {
            Gate::authorize('view', $other);

            return new CurrentUserController;
        });

        $this->getJson('/api/v1/user')->assertForbidden()->assertJsonPath('code', 403);
    }

    public function test_login_is_rate_limited_with_a_retry_header(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 429);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/register', [])->assertStatus(429)->assertJsonPath('code', 429);
    }

    public function test_role_changes_are_available_only_from_the_console(): void
    {
        $user = $this->createUser();
        $this->artisan('users:set-role', ['email' => $user->email, 'role' => 'Administrator'])->assertSuccessful();
        $this->assertSame(UserRole::Administrator, $user->fresh()->role);
        $this->artisan('users:set-role', ['email' => $user->email, 'role' => 'invalid'])->assertFailed();
    }
}
