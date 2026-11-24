<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Medal;
use App\Models\Specialty;
use App\Models\User;
use App\Services\MedalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class MedalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(UserRole $role = UserRole::Student): User
    {
        $user = new User(['name' => 'Recipient '.User::count(), 'email' => 'medal'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function medal(?Specialty $specialty = null, string $name = 'Medal'): Medal
    {
        $specialty ??= Specialty::create(['name' => 'Game '.Specialty::count()]);

        return Medal::create(['name' => $name, 'specialty_id' => $specialty->id]);
    }

    private function png(int $width = 1, int $height = 1): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width * 3), $height))).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('medal.png', $bytes);
    }

    public function test_guest_and_other_roles_cannot_manage_medals_or_recipients(): void
    {
        $target = $this->user();
        $medal = $this->medal();
        $path = '/api/v1/users/'.$target->id.'/medals';
        foreach ([null, UserRole::Student, UserRole::Coach] as $role) {
            if ($role !== null) {
                $this->actingAs($this->user($role), 'web');
            }
            $status = $role === null ? 401 : 403;
            $this->getJson('/api/v1/medals')->assertStatus($status);
            $this->getJson('/api/v1/medal-recipients')->assertStatus($status);
            $this->postJson('/api/v1/medals', ['name' => 'Forbidden', 'specialty_id' => $medal->specialty_id])->assertStatus($status);
            $this->getJson($path)->assertStatus($status);
            $this->putJson($path.'/'.$medal->id)->assertStatus($status);
            $this->deleteJson($path.'/'.$medal->id)->assertStatus($status);
        }
        $this->assertDatabaseCount('medal_user', 0);
        $this->assertDatabaseCount('medals', 1);
    }

    public function test_admin_creates_medals_without_awarding_them_and_names_are_unique_per_game(): void
    {
        $this->actingAs($this->user(UserRole::Administrator), 'web');
        $first = Specialty::create(['name' => 'Tarkov']);
        $second = Specialty::create(['name' => 'Counter Strike']);
        $this->postJson('/api/v1/medals', ['name' => '  Expert  ', 'specialty_id' => $first->id])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'Expert')->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.specialty.id', $first->id)->assertJsonMissingPath('data.image_path');
        $this->postJson('/api/v1/medals', ['name' => 'Expert', 'specialty_id' => $first->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/medals', ['name' => 'Expert', 'specialty_id' => $second->id])->assertCreated();
        $this->getJson('/api/v1/medals?filter[specialty_id]='.$first->id.'&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/medals?per_page=101')->assertUnprocessable();
        $this->assertDatabaseCount('medal_user', 0);
    }

    public function test_images_are_optional_validated_and_served_without_internal_paths(): void
    {
        $this->actingAs($this->user(UserRole::Administrator), 'web');
        $specialty = Specialty::create(['name' => 'Game']);
        $input = ['name' => 'With image', 'specialty_id' => $specialty->id];
        $response = $this->post('/api/v1/medals', [...$input, 'image' => $this->png(512, 512)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonMissingPath('data.image_path');
        $medal = Medal::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($medal->image_path);
        $this->app['auth']->forgetGuards();
        $this->get($response->json('data.image_url'))->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->head($response->json('data.image_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->user(UserRole::Administrator), 'web');
        foreach ([$this->png(513, 1), $this->png(1, 513), UploadedFile::fake()->create('big.png', 1025, 'image/png'), UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')] as $file) {
            $this->post('/api/v1/medals', [...$input, 'name' => 'Invalid', 'image' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->get('/api/v1/medals/'.$this->medal()->id.'/image')->assertNotFound();
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_invalid_fields_do_not_create_medals_or_awards(): void
    {
        $this->actingAs($this->user(UserRole::Administrator), 'web');
        $medal = $this->medal();
        $this->postJson('/api/v1/medals', ['name' => ' ', 'specialty_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['name', 'specialty_id']);
        $this->postJson('/api/v1/medals', ['name' => str_repeat('a', 101), 'specialty_id' => $medal->specialty_id, 'image_path' => 'outside.png', 'user_ids' => [1]])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'image_path', 'user_ids']);
        $this->putJson('/api/v1/users/999999/medals/'.$medal->id)->assertNotFound();
        $this->assertDatabaseCount('medals', 1);
        $this->assertDatabaseCount('medal_user', 0);
    }

    public function test_recipient_selector_includes_every_role_without_changing_the_normal_user_list(): void
    {
        $admin = $this->user(UserRole::Administrator);
        $this->user(UserRole::Coach);
        $this->user();
        $this->actingAs($admin, 'web')->getJson('/api/v1/medal-recipients?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)
            ->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.bio');
        $this->getJson('/api/v1/medal-recipients?filter[name]='.urlencode($admin->name))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.role', 'Administrator');
        $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 2);
        $this->head('/api/v1/medals')->assertOk()->assertContent('');
    }

    #[DataProvider('roles')]
    public function test_admin_awards_and_revokes_any_role_idempotently(UserRole $role): void
    {
        $this->actingAs($this->user(UserRole::Administrator), 'web');
        $target = $this->user($role);
        $medal = $this->medal();
        $path = '/api/v1/users/'.$target->id.'/medals';
        $this->putJson($path.'/'.$medal->id)->assertOk()->assertJsonPath('data.id', $medal->id);
        $before = DB::table('medal_user')->first()->updated_at;
        $this->travel(5)->seconds();
        $this->putJson($path.'/'.$medal->id)->assertOk();
        $this->assertDatabaseCount('medal_user', 1);
        $this->assertSame($before, DB::table('medal_user')->first()->updated_at);
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $medal->id);
        $this->deleteJson($path.'/'.$medal->id)->assertNoContent();
        $this->deleteJson($path.'/'.$medal->id)->assertNoContent();
        $this->assertDatabaseCount('medal_user', 0);
        $this->assertDatabaseHas('medals', ['id' => $medal->id]);
        $this->assertSame(0, $target->fresh()->profile_save_count);
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], UserRole::cases());
    }

    public function test_mutations_require_csrf_even_without_origin(): void
    {
        $admin = $this->user(UserRole::Administrator);
        $medal = $this->medal();
        $path = '/api/v1/users/'.$admin->id.'/medals/'.$medal->id;
        $this->actingAs($admin, 'web');
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/medals', ['name' => 'Blocked', 'specialty_id' => $medal->specialty_id])->assertStatus(419);
        $this->post('/api/v1/medals', ['name' => 'Blocked', 'specialty_id' => $medal->specialty_id, 'image' => $this->png()], ['Accept' => 'application/json'])->assertStatus(419);
        $this->putJson($path)->assertStatus(419);
        $this->deleteJson($path)->assertStatus(419);
        $this->withSession(['_token' => 'medal-test-token'])->withHeader('X-CSRF-TOKEN', 'medal-test-token')->putJson($path)->assertOk();
    }

    public function test_course_medals_are_filtered_by_game_and_hidden_by_global_or_section_privacy(): void
    {
        $coach = $this->user(UserRole::Coach);
        $medal = $this->medal();
        $other = $this->medal();
        $coach->specialties()->attach($medal->specialty_id);
        $coach->medals()->attach([$medal->id, $other->id]);
        $course = new Course;
        $course->forceFill(['title' => 'Course', 'description' => 'Description', 'coach_id' => $coach->id, 'specialty_id' => $medal->specialty_id, 'status' => 'published', 'price' => 0])->save();
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonCount(1, 'data.0.coach.medals')
            ->assertJsonPath('data.0.coach.medals.0.id', $medal->id)->assertJsonMissingPath('data.0.coach.medals.0.image_path');
        $student = $this->user();
        $enrollment = $this->actingAs($student, 'web')->postJson('/api/v1/courses/'.$course->id.'/enrollments')->assertCreated()->json('data.id');
        $this->getJson('/api/v1/enrollments/'.$enrollment)->assertOk()->assertJsonPath('data.course.coach.medals.0.id', $medal->id);
        $this->patchJson('/api/v1/profile', ['featured_course_ids' => [$course->id]])->assertOk()->assertJsonPath('data.featured_courses.0.coach.medals.0.id', $medal->id);
        foreach (['is_private', 'medals_is_private'] as $field) {
            $coach->forceFill(['is_private' => false, 'medals_is_private' => false, $field => true])->save();
            $this->actingAs($this->user(UserRole::Administrator), 'web')->getJson('/api/v1/courses')->assertOk()->assertJsonMissingPath('data.0.coach.medals');
            $this->getJson('/api/v1/profiles/'.$coach->id)->assertOk()->assertJsonMissingPath('data.medals')->assertJsonMissingPath('data.medals_is_private');
            $this->actingAs($coach->fresh(), 'web')->getJson('/api/v1/profile')->assertOk()->assertJsonCount(2, 'data.medals');
        }
        $this->getJson('/api/v1/session')->assertOk()->assertJsonMissingPath('data.medals');
    }

    public function test_section_privacy_uses_profile_no_op_cooldown_and_cannot_self_award(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web')->patchJson('/api/v1/profile', ['medals_is_private' => false])->assertOk()->assertJsonPath('meta.updated', false);
        $this->patchJson('/api/v1/profile', ['medals_is_private' => true])->assertOk()->assertJsonPath('meta.updated', true);
        $this->patchJson('/api/v1/profile', ['medals_is_private' => false])->assertOk();
        $this->patchJson('/api/v1/profile', ['medals_is_private' => true])->assertOk()->assertJsonPath('data.save_limits.retry_after', 60);
        $this->patchJson('/api/v1/profile', ['medals_is_private' => false])->assertStatus(429);
        $this->patchJson('/api/v1/profile', ['medal_ids' => [$this->medal()->id]])->assertUnprocessable()->assertJsonValidationErrors('medal_ids');
        $this->assertDatabaseCount('medal_user', 0);
    }

    public function test_service_revalidates_the_current_admin_role(): void
    {
        $admin = $this->user(UserRole::Administrator);
        $medal = $this->medal();
        User::whereKey($admin->id)->update(['role' => UserRole::Student->value]);
        $this->expectException(AuthorizationException::class);
        try {
            app(MedalService::class)->assign($admin, $admin, $medal);
        } finally {
            $this->assertDatabaseCount('medal_user', 0);
        }
    }

    public function test_failed_creation_rolls_back_and_cleans_the_new_image(): void
    {
        $admin = $this->user(UserRole::Administrator);
        $specialty = Specialty::create(['name' => 'Game']);
        Medal::creating(function (): void {
            throw new RuntimeException('Simulated failure');
        });
        try {
            app(MedalService::class)->create($admin, ['name' => 'Rollback', 'specialty_id' => $specialty->id, 'image' => $this->png()]);
            $this->fail('Creation should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        } finally {
            Medal::flushEventListeners();
        }
        $this->assertDatabaseCount('medals', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_database_rejects_duplicate_awards(): void
    {
        $user = $this->user();
        $medal = $this->medal();
        $user->medals()->attach($medal->id);
        $this->expectException(QueryException::class);
        $user->medals()->attach($medal->id);
    }

    public function test_database_keeps_award_names_unique_per_specialty(): void
    {
        $medal = $this->medal();
        $this->expectException(QueryException::class);
        Medal::create(['name' => $medal->name, 'specialty_id' => $medal->specialty_id]);
    }

    public function test_user_deletion_removes_awards_and_preserves_catalog_medals(): void
    {
        $user = $this->user();
        $medal = $this->medal();
        $user->medals()->attach($medal->id);
        $user->delete();
        $this->assertDatabaseCount('medal_user', 0);
        $this->assertDatabaseHas('medals', ['id' => $medal->id]);
    }

    public function test_medal_migration_rolls_back_and_reapplies_without_losing_users_or_specialties(): void
    {
        $user = $this->user();
        $medal = $this->medal();
        $migration = require database_path('migrations/2026_11_24_000001_create_medals_tables.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'medals_is_private' => false]);
        $this->assertDatabaseHas('specialties', ['id' => $medal->specialty_id]);
        $this->assertDatabaseCount('medals', 0);
        $this->assertDatabaseCount('medal_user', 0);
    }
}
