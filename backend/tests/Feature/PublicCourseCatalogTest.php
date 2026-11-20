<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

final class PublicCourseCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => 'Coach '.User::count(), 'email' => 'catalog'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function course(array $attributes = []): Course
    {
        $owner = $this->user();
        $specialty = Specialty::create(['name' => 'Specialty '.Specialty::count()]);
        $owner->specialties()->attach($specialty);
        $course = new Course;
        $course->forceFill([
            'title' => 'Public course', 'description' => 'Course description.',
            'coach_id' => $owner->id, 'specialty_id' => $specialty->id,
            'status' => 'published', 'price' => '19.90', ...$attributes,
        ])->save();

        return $course;
    }

    public function test_catalog_is_public_and_only_exposes_published_courses_with_valid_assignments(): void
    {
        $public = $this->course(['price' => 0]);
        $this->course(['title' => 'Secret draft', 'status' => 'draft']);
        $this->course(['coach_id' => null]);
        $this->course(['specialty_id' => null]);
        $invalidRole = $this->course();
        $invalidRole->coach()->update(['role' => UserRole::Student->value]);
        $unassigned = $this->course();
        $unassigned->coach->specialties()->detach();

        $response = $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data', [[
                'id' => $public->id,
                'title' => $public->title,
                'description' => $public->description,
                'level' => null,
                'price' => '0.00',
                'specialty' => ['id' => $public->specialty_id, 'name' => $public->specialty->name],
                'coach' => ['id' => $public->coach_id, 'name' => $public->coach->name],
                'cover_url' => null,
            ]]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($public->coach->email, $response->getContent());
        $response->assertJsonMissing(['title' => 'Secret draft'])
            ->assertJsonMissingPath('data.0.cover_path')->assertJsonMissingPath('data.0.additional_coaches');
    }

    public function test_catalog_is_paginated_newest_first_and_validates_page_limits(): void
    {
        $old = $this->course(['title' => 'Older course']);
        $new = $this->course(['title' => 'Newer course']);
        $this->getJson('/api/v1/courses?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $new->id)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/courses?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/courses?page=0&per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
        $this->getJson('/api/v1/courses?page=3&per_page=1')->assertOk()->assertJsonCount(0, 'data');
        $this->head('/api/v1/courses')->assertOk()->assertContent('');
    }

    public function test_query_parameters_and_authenticated_roles_cannot_expose_drafts_in_the_public_catalog(): void
    {
        $public = $this->course();
        $this->course(['title' => 'Private draft', 'status' => 'draft']);
        $path = '/api/v1/courses?status=draft&filter[status]=draft';
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $public->id);
        foreach (UserRole::cases() as $role) {
            $this->actingAs($this->user($role))->getJson($path)->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonMissing(['title' => 'Private draft']);
        }
    }

    #[DataProvider('creationStates')]
    public function test_authorized_creators_can_choose_draft_or_published(UserRole $role, string $status): void
    {
        $actor = $this->user($role);
        $owner = $role === UserRole::Coach ? $actor : $this->user();
        $specialty = Specialty::create(['name' => 'Programming']);
        $owner->specialties()->attach($specialty);
        $response = $this->actingAs($actor)->postJson('/api/v1/courses', [
            'title' => 'Chosen state', 'description' => 'Description.',
            'specialty_id' => $specialty->id, 'coach_id' => $owner->id,
            'price' => '0.00', 'status' => $status,
        ])->assertCreated()->assertJsonPath('data.status', $status);
        $this->assertDatabaseHas('courses', ['id' => $response->json('data.id'), 'status' => $status]);
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('meta.total', $status === 'published' ? 1 : 0);
    }

    public static function creationStates(): array
    {
        return [
            [UserRole::Coach, 'draft'], [UserRole::Coach, 'published'],
            [UserRole::Administrator, 'draft'], [UserRole::Administrator, 'published'],
        ];
    }

    public function test_invalid_states_are_rejected_and_students_cannot_publish(): void
    {
        $owner = $this->user();
        $specialty = Specialty::create(['name' => 'Programming']);
        $owner->specialties()->attach($specialty);
        $details = ['title' => 'Course', 'description' => 'Description.', 'coach_id' => $owner->id, 'specialty_id' => $specialty->id, 'price' => 0];
        $this->postJson('/api/v1/courses', [...$details, 'status' => 'published'])->assertUnauthorized();
        $this->actingAs($owner);
        foreach ([null, 'invalid', []] as $status) {
            $this->postJson('/api/v1/courses', [...$details, 'status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->actingAs($this->user(UserRole::Student))->postJson('/api/v1/courses', [...$details, 'status' => 'published'])->assertForbidden();
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_published_covers_are_public_but_draft_or_invalid_course_covers_remain_private(): void
    {
        Storage::fake('local');
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\0\0\0\0")).$chunk('IEND', '');
        $course = $this->course(['cover_path' => 'courses/covers/public.png']);
        Storage::disk('local')->put($course->cover_path, $png);
        $path = '/api/v1/courses/'.$course->id.'/cover';
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('data.0.cover_url', $path);
        $image = $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($png, $image->streamedContent());
        $this->head($path)->assertOk();

        $course->status = 'draft';
        $course->save();
        $this->getJson($path)->assertUnauthorized();
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonCount(0, 'data');
        $course->status = 'published';
        $course->save();
        $course->coach->specialties()->detach();
        $this->getJson($path)->assertUnauthorized();
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user(UserRole::Student))->getJson($path)->assertForbidden();
        $this->actingAs($course->coach)->get($path)->assertOk();
    }
}
