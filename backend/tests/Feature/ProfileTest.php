<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(UserRole $role = UserRole::Student, array $profile = []): User
    {
        $user = new User(['name' => 'Profile '.User::count(), 'email' => 'profile'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->forceFill($profile)->save();

        return $user;
    }

    private function png(int $width = 1, int $height = 1): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width * 3), $height))).$chunk('IEND', '');
    }

    public function test_public_profiles_expose_only_the_approved_fields(): void
    {
        $user = $this->user(profile: ['bio' => 'Public biography.']);
        $path = '/api/v1/profiles/'.$user->id;
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
            'id' => $user->id, 'name' => $user->name, 'level' => 1, 'avatar_url' => null, 'is_private' => false, 'can_edit' => false, 'bio' => 'Public biography.', 'featured_courses' => [], 'medals' => [],
        ]]);
        $this->head($path)->assertOk()->assertContent('');
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/profiles/999999')->assertNotFound();
        $this->get('/api/v1/profiles/'.$user->id.'/avatar')->assertNotFound();
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', ['name' => 'Attempt'])->assertUnauthorized();
    }

    public function test_private_profiles_keep_identity_public_but_only_owners_can_read_details(): void
    {
        $user = $this->user(profile: ['bio' => 'Private biography.', 'is_private' => true]);
        $path = '/api/v1/profiles/'.$user->id;
        $basic = ['data' => ['id' => $user->id, 'name' => $user->name, 'level' => 1, 'avatar_url' => null, 'is_private' => true, 'can_edit' => false]];
        $this->getJson($path)->assertOk()->assertExactJson($basic);
        foreach (UserRole::cases() as $role) {
            $viewer = $this->user($role);
            $this->actingAs($viewer, 'web')->getJson($path)->assertOk()->assertExactJson($basic);
            $this->assertFalse($viewer->can('updateProfile', $user));
        }
        $this->actingAs($user, 'web')->getJson($path)->assertOk()->assertJsonPath('data.bio', 'Private biography.')
            ->assertJsonPath('data.can_edit', true)->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.role');
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.bio', 'Private biography.');
        $this->head('/api/v1/profile')->assertOk()->assertContent('');
    }

    #[DataProvider('roles')]
    public function test_each_role_can_edit_its_own_profile_without_changing_credentials(UserRole $role): void
    {
        $user = $this->user($role);
        $password = $user->password;
        $this->actingAs($user, 'web')->patchJson('/api/v1/profile', ['name' => 'Updated name', 'bio' => 'Biography.', 'is_private' => true])
            ->assertOk()->assertJsonPath('data.name', 'Updated name')->assertJsonPath('data.bio', 'Biography.')
            ->assertJsonPath('data.is_private', true)->assertJsonPath('data.can_edit', true);
        $fresh = $user->fresh();
        $this->assertSame($role, $fresh->role);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame($password, $fresh->password);
        $this->assertTrue($fresh->is_private);
        $this->actingAs($fresh, 'web')->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.name', 'Updated name');
        $this->getJson('/api/v1/session')->assertOk()->assertJsonMissingPath('data.bio')->assertJsonMissingPath('data.avatar_path');
    }

    public static function roles(): array
    {
        return [[UserRole::Student], [UserRole::Coach], [UserRole::Administrator]];
    }

    public function test_privileged_fields_and_other_user_identifiers_cannot_be_changed_through_profile(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $this->actingAs($owner, 'web');
        foreach (['role' => 'Administrator', 'email' => 'changed@example.test', 'password' => 'Changed-password-123', 'user_id' => $other->id, 'id' => $other->id, 'specialty_ids' => [1], 'avatar_path' => 'outside.png'] as $field => $value) {
            $this->patchJson('/api/v1/profile', ['name' => 'Attempt', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'role' => 'Student']);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => $other->name]);
        $this->patchJson('/api/v1/users/'.$other->id, ['name' => 'Attempt'])->assertForbidden();
    }

    public function test_profile_validation_rejects_empty_invalid_or_oversized_input(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');
        foreach ([['name' => ''], ['name' => str_repeat('x', 101)], ['bio' => str_repeat('x', 2001)], ['is_private' => null], ['is_private' => 'invalid'], ['is_private' => []]] as $payload) {
            $this->patchJson('/api/v1/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors(array_keys($payload)[0]);
        }
        $this->patchJson('/api/v1/profile', [])->assertUnprocessable()->assertJsonValidationErrors('profile');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'bio' => null, 'is_private' => false]);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_json_and_multipart_profile_changes_require_csrf_without_origin(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');
        $this->app->instance('env', 'local');
        $this->patchJson('/api/v1/profile', ['is_private' => true])->assertStatus(419);
        $this->post('/api/v1/profile', ['_method' => 'PATCH', 'avatar' => UploadedFile::fake()->createWithContent('avatar.png', $this->png())], ['Accept' => 'application/json'])->assertStatus(419);
        $this->withSession(['_token' => 'profile-test-token'])->withHeader('X-CSRF-TOKEN', 'profile-test-token')
            ->patchJson('/api/v1/profile', ['is_private' => true])->assertOk()->assertJsonPath('data.is_private', true);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_avatars_remain_public_on_private_profiles_and_replacements_change_the_url(): void
    {
        $user = $this->user(profile: ['bio' => 'Hidden biography.', 'is_private' => true]);
        $bytes = $this->png(1024, 1024);
        $saved = $this->actingAs($user, 'web')->post('/api/v1/profile', ['_method' => 'PATCH', 'avatar' => UploadedFile::fake()->createWithContent('original.png', $bytes)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonMissingPath('data.avatar_path');
        $originalPath = $user->fresh()->avatar_path;
        $originalUrl = $saved->json('data.avatar_url');
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/session')->assertOk()
            ->assertJsonPath('data.avatar_url', $originalUrl)->assertJsonMissingPath('data.avatar_path')->assertJsonMissingPath('data.bio');
        // Feature requests share guards; a real HTTP request reloads the current identity.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.avatar_url', $originalUrl);
        $this->assertStringStartsWith('profiles/avatars/', $originalPath);
        $this->assertNotSame('original.png', basename($originalPath));
        $this->get($originalUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent($bytes);
        $this->head($originalUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->user(), 'web')->getJson('/api/v1/profiles/'.$user->id)->assertOk()
            ->assertJsonPath('data.avatar_url', $originalUrl)->assertJsonPath('data.name', $user->name)->assertJsonMissingPath('data.bio');
        $this->get($originalUrl)->assertOk()->assertStreamedContent($bytes);
        $replacement = $this->actingAs($user->fresh(), 'web')->post('/api/v1/profile', ['_method' => 'PATCH', 'avatar' => UploadedFile::fake()->createWithContent('replacement.png', $this->png())], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotSame($originalUrl, $replacement->json('data.avatar_url'));
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/session')->assertOk()
            ->assertJsonPath('data.avatar_url', $replacement->json('data.avatar_url'));
        Storage::disk('local')->assertMissing($originalPath);
        $this->patchJson('/api/v1/profile', ['remove_avatar' => true])->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->get($originalUrl)->assertNotFound();
    }

    public function test_invalid_avatar_files_and_conflicting_removal_do_not_change_profile(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->createWithContent('large.png', $this->png().str_repeat('x', 2 * 1024 * 1024)),
            UploadedFile::fake()->createWithContent('wide.png', $this->png(1025)),
            UploadedFile::fake()->createWithContent('tall.png', $this->png(1, 1025)),
        ] as $avatar) {
            $this->post('/api/v1/profile', ['_method' => 'PATCH', 'avatar' => $avatar], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        }
        $this->post('/api/v1/profile', ['_method' => 'PATCH', 'remove_avatar' => '1', 'avatar' => UploadedFile::fake()->createWithContent('avatar.png', $this->png())], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => null]);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[DataProvider('databaseFailures')]
    public function test_failed_database_update_preserves_original_avatar_and_cleans_new_file(bool $throw): void
    {
        $user = $this->user(profile: ['avatar_path' => 'profiles/avatars/old.png']);
        Storage::disk('local')->put($user->avatar_path, $this->png());
        Log::spy();
        User::updating(function () use ($throw) {
            if ($throw) {
                throw new RuntimeException('Test-only profile update failure.');
            }

            return false;
        });
        try {
            $this->actingAs($user, 'web')->post('/api/v1/profile', ['_method' => 'PATCH', 'name' => 'Attempt', 'avatar' => UploadedFile::fake()->createWithContent('avatar.png', $this->png())], ['Accept' => 'application/json'])->assertStatus(500);
        } finally {
            User::flushEventListeners();
        }
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'avatar_path' => 'profiles/avatars/old.png']);
        Storage::disk('local')->assertExists('profiles/avatars/old.png');
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Log::shouldHaveReceived('error')->once();
    }

    public static function databaseFailures(): array
    {
        return [[true], [false]];
    }

    public function test_storage_cleanup_failure_does_not_undo_a_saved_profile(): void
    {
        $user = $this->user(profile: ['avatar_path' => 'profiles/avatars/old.png']);
        $disk = Storage::disk('local');
        $disk->put($user->avatar_path, $this->png());
        Log::spy();
        Storage::shouldReceive('disk')->once()->with('local')->andThrow(new RuntimeException('Test-only avatar cleanup failure.'));
        $this->actingAs($user, 'web')->patchJson('/api/v1/profile', ['remove_avatar' => true])->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => null]);
        $this->assertTrue($disk->exists('profiles/avatars/old.png'));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_profile_migration_preserves_existing_identity_and_credentials(): void
    {
        $user = $this->user(UserRole::Coach);
        $password = $user->password;
        $migration = require database_path('migrations/2026_11_21_000001_add_profile_fields_to_users_table.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'Coach', 'password' => $password]);
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'bio' => null, 'is_private' => false, 'avatar_path' => null]);
        $this->assertArrayNotHasKey('avatar_path', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('bio', $user->fresh()->toArray());
    }
}
