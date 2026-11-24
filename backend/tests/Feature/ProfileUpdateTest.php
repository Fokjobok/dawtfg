<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $attributes = []): User
    {
        $owner = new User(['name' => 'Profile owner', 'email' => 'profile-update@example.test', 'password' => 'Test-only-password-123']);
        $owner->forceFill($attributes)->saveOrFail();

        return $owner->fresh();
    }

    public function test_unchanged_values_do_not_save_or_modify_timestamps(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $before = $owner->getRawOriginal();
        $this->travel(5)->seconds();
        User::updating(fn () => throw new RuntimeException('Unchanged profile must not be saved.'));

        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', [
            'name' => ' Profile owner ', 'bio' => '', 'is_private' => false, 'bio_is_private' => false, 'remove_avatar' => true,
        ])->assertOk()->assertJsonPath('meta.updated', false);

        $this->assertSame($before, $owner->fresh()->getRawOriginal());
    }

    public function test_an_identical_avatar_does_not_replace_the_file(): void
    {
        Storage::fake('local');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jM1sAAAAASUVORK5CYII=');
        $owner = $this->owner(['avatar_path' => 'profiles/avatars/current.png']);
        Storage::disk('local')->put($owner->avatar_path, $bytes);
        $before = $owner->getRawOriginal();

        $this->actingAs($owner, 'web')->post('/api/v1/profile', [
            '_method' => 'PATCH', 'avatar' => UploadedFile::fake()->createWithContent('same.png', $bytes),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('meta.updated', false);

        $this->assertSame($before, $owner->fresh()->getRawOriginal());
        $this->assertSame(['profiles/avatars/current.png'], Storage::disk('local')->allFiles());
    }

    public function test_a_real_change_is_reported_as_an_update(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['name' => 'Updated owner'])
            ->assertOk()->assertJsonPath('meta.updated', true)->assertJsonPath('data.name', 'Updated owner');
    }

    public function test_three_actual_saves_start_a_persistent_sixty_second_cooldown_and_then_a_new_block(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        foreach (range(1, 3) as $save) {
            $this->actingAs($owner->fresh(), 'web')->patchJson('/api/v1/profile', ['name' => 'Saved '.$save])
                ->assertOk()->assertJsonPath('meta.updated', true)
                ->assertJsonPath('data.save_limits.remaining_updates', 3 - $save)
                ->assertJsonPath('data.save_limits.retry_after', $save === 3 ? 60 : 0);
        }

        // Reload the identity and consult both owner endpoints, as another session would.
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner->fresh(), 'web')->getJson('/api/v1/profile')
            ->assertOk()->assertJsonPath('data.save_limits.retry_after', 60);
        $this->getJson('/api/v1/profiles/'.$owner->id)->assertOk()->assertJsonPath('data.save_limits.retry_after', 60);
        $this->patchJson('/api/v1/profile', ['name' => 'Blocked'])
            ->assertStatus(429)->assertHeader('Retry-After', '60')->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['error' => 'Too Many Requests', 'code' => 429, 'retry_after' => 60]);
        $this->patchJson('/api/v1/profile', ['name' => 'Saved 3'])->assertOk()->assertJsonPath('meta.updated', false)
            ->assertJsonPath('data.save_limits.retry_after', 60);
        $this->travel(59)->seconds();
        $this->patchJson('/api/v1/profile', ['name' => 'Still blocked'])->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->travel(1)->seconds();
        $this->actingAs($owner->fresh(), 'web')->getJson('/api/v1/profile')->assertOk()
            ->assertJsonPath('data.save_limits.remaining_updates', 3)->assertJsonPath('data.save_limits.retry_after', 0)
            ->assertJsonPath('data.save_limits.available_at', null);
        $this->patchJson('/api/v1/profile', ['name' => 'New block'])->assertOk()
            ->assertJsonPath('data.save_limits.remaining_updates', 2)->assertJsonPath('data.save_limits.retry_after', 0);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => 'New block', 'profile_save_count' => 1, 'profile_save_available_at' => null]);
    }

    public function test_failed_and_unchanged_requests_do_not_consume_or_reset_successful_saves(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['name' => 'First'])->assertOk();
        $this->patchJson('/api/v1/profile', ['name' => 'First'])->assertOk()->assertJsonPath('meta.updated', false);
        $this->patchJson('/api/v1/profile', ['name' => ''])->assertUnprocessable();
        $this->assertSame(1, $owner->fresh()->profile_save_count);
        Log::spy();
        User::updating(fn () => false);
        $this->patchJson('/api/v1/profile', ['name' => 'Not saved'])->assertStatus(500);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => 'First', 'profile_save_count' => 1]);
    }

    public function test_multipart_uploads_obey_the_same_limit_without_storing_a_blocked_file(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        $owner = $this->owner(['profile_save_count' => 2]);
        $this->actingAs($owner, 'web')->post('/api/v1/profile', ['_method' => 'PATCH', 'bio' => 'Third save.'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.save_limits.retry_after', 60);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jM1sAAAAASUVORK5CYII=');
        $this->post('/api/v1/profile', ['_method' => 'PATCH', 'avatar' => UploadedFile::fake()->createWithContent('blocked.png', $bytes)], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'avatar_path' => null, 'profile_save_count' => 3]);
    }

    public function test_limits_are_per_account_private_and_cannot_be_set_by_the_client(): void
    {
        $owner = $this->owner(['profile_save_count' => 3, 'profile_save_available_at' => now()->addSeconds(60)]);
        $other = User::create(['name' => 'Other', 'email' => 'other-profile@example.test', 'password' => 'Test-only-password-123']);
        $this->actingAs($other, 'web')->getJson('/api/v1/profiles/'.$owner->id)->assertOk()->assertJsonMissingPath('data.save_limits');
        $this->patchJson('/api/v1/profile', ['name' => 'Independent account'])->assertOk()->assertJsonPath('data.save_limits.remaining_updates', 2);
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['profile_save_count' => 0, 'profile_save_available_at' => '2020-01-01T00:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors(['profile_save_count', 'profile_save_available_at']);
        $this->patchJson('/api/v1/profile', ['name' => 'Still blocked'])->assertStatus(429);
        $this->assertArrayNotHasKey('profile_save_count', $owner->fresh()->toArray());
        $this->getJson('/api/v1/session')->assertOk()->assertJsonMissingPath('data.save_limits')->assertJsonMissingPath('data.profile_save_count');
    }

    public function test_new_limit_migration_preserves_identity_and_profile_data(): void
    {
        $owner = $this->owner(['bio' => 'Biography.', 'is_private' => true, 'bio_is_private' => true]);
        $password = $owner->password;
        $migration = require database_path('migrations/2026_11_22_000001_add_profile_save_limits_to_users_table.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'password' => $password, 'bio' => 'Biography.', 'is_private' => true, 'bio_is_private' => true]);
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'password' => $password, 'bio' => 'Biography.', 'profile_save_count' => 0, 'profile_save_available_at' => null]);
    }
}
