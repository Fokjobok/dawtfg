<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProfilePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        $user = new User(['name' => 'Profile '.User::count(), 'email' => 'privacy'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->forceFill($attributes)->save();

        return $user->fresh();
    }

    #[DataProvider('privacySettings')]
    public function test_global_privacy_overrides_sections_while_owners_always_see_their_details(bool $private, bool $bioPrivate): void
    {
        $owner = $this->user(['bio' => 'Biography.', 'is_private' => $private, 'bio_is_private' => $bioPrivate, 'level' => 7]);
        $path = '/api/v1/profiles/'.$owner->id;
        $visible = ! $private && ! $bioPrivate;
        $expected = ['id' => $owner->id, 'name' => $owner->name, 'level' => 7, 'avatar_url' => null, 'is_private' => $private, 'can_edit' => false];
        if (! $private) {
            $expected['featured_courses'] = [];
            $expected['medals'] = [];
        }
        if ($visible) {
            $expected['bio'] = 'Biography.';
        }
        $this->getJson($path)->assertOk()->assertExactJson(['data' => $expected]);
        foreach (UserRole::cases() as $role) {
            $visitor = $this->user(['role' => $role]);
            $this->actingAs($visitor, 'web')->getJson($path)->assertOk()->assertExactJson(['data' => $expected]);
        }
        $this->actingAs($owner, 'web')->getJson($path)->assertOk()->assertJsonPath('data.bio', 'Biography.')
            ->assertJsonPath('data.bio_is_private', $bioPrivate)->assertJsonPath('data.can_edit', true);
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.level', 7)
            ->assertJsonPath('data.bio_is_private', $bioPrivate)->assertJsonPath('data.bio', 'Biography.');
    }

    public static function privacySettings(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    public function test_global_switch_preserves_section_preferences_and_hides_details_in_the_api(): void
    {
        $owner = $this->user(['bio' => 'Biography.']);
        $visitor = $this->user();
        $path = '/api/v1/profiles/'.$owner->id;
        $this->actingAs($owner, 'web')->patchJson('/api/v1/profile', ['bio_is_private' => true])->assertOk()
            ->assertJsonPath('data.bio_is_private', true)->assertJsonPath('data.is_private', false);
        $this->actingAs($visitor, 'web')->getJson($path)->assertOk()->assertJsonMissingPath('data.bio')
            ->assertJsonMissingPath('data.bio_is_private');
        $this->actingAs($owner->fresh(), 'web')->patchJson('/api/v1/profile', ['is_private' => true])->assertOk()
            ->assertJsonPath('data.bio_is_private', true);
        $this->patchJson('/api/v1/profile', ['is_private' => false])->assertOk()->assertJsonPath('data.bio_is_private', true);
        $this->actingAs($visitor, 'web')->getJson($path)->assertOk()->assertJsonMissingPath('data.bio');
        // Three effective saves have completed; the next block starts after the cooldown.
        $this->travel(60)->seconds();
        $this->actingAs($owner->fresh(), 'web')->post('/api/v1/profile', ['_method' => 'PATCH', 'bio_is_private' => '0'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.bio_is_private', false);
        $this->actingAs($visitor, 'web')->getJson($path)->assertOk()->assertJsonPath('data.bio', 'Biography.');
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'level' => 1, 'bio_is_private' => false, 'is_private' => false]);
    }

    public function test_privacy_requires_valid_booleans_and_level_or_subject_ranks_are_not_self_editable(): void
    {
        $owner = $this->user();
        $this->actingAs($owner, 'web');
        foreach ([null, 'private', [], 2] as $invalid) {
            $this->patchJson('/api/v1/profile', ['bio_is_private' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('bio_is_private');
        }
        foreach (['level' => 99, 'experience' => 10000, 'specialty_ranks' => [['specialty_id' => 1, 'rank' => 'expert']]] as $field => $value) {
            $this->patchJson('/api/v1/profile', ['name' => 'Attempt', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => $owner->name, 'level' => 1, 'bio_is_private' => false]);
        $this->assertArrayNotHasKey('bio_is_private', $owner->fresh()->toArray());
    }

    public function test_new_migration_preserves_existing_profile_and_account_fields(): void
    {
        $owner = $this->user(['bio' => 'Biography.', 'is_private' => true]);
        $password = $owner->password;
        $migration = require database_path('migrations/2026_11_21_000002_add_level_and_bio_privacy_to_users_table.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'password' => $password, 'bio' => 'Biography.', 'is_private' => true]);
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'password' => $password, 'bio' => 'Biography.', 'is_private' => true, 'level' => 1, 'bio_is_private' => false]);
    }
}
