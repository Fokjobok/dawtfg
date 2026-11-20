<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CourseUpdateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class CourseEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => 'Coach '.User::count(), 'email' => 'editing'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function fixture(): array
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $other = $this->user();
        $stranger = $this->user();
        $admin = $this->user(UserRole::Administrator);
        $student = $this->user(UserRole::Student);
        $first = Specialty::create(['name' => 'First']);
        $second = Specialty::create(['name' => 'Second']);
        foreach ([$owner, $collaborator, $other, $stranger] as $coach) {
            $coach->specialties()->attach([$first->id, $second->id]);
        }
        $course = new Course;
        $course->forceFill(['title' => 'Original', 'description' => 'Original description.', 'price' => '12.50', 'status' => 'published', 'coach_id' => $owner->id, 'specialty_id' => $first->id])->save();
        $course->additionalCoaches()->attach([$collaborator->id, $other->id]);

        return compact('owner', 'collaborator', 'other', 'stranger', 'admin', 'student', 'first', 'second', 'course');
    }

    private function png(int $width = 1, int $height = 1): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width * 3), $height))).$chunk('IEND', '');
    }

    public function test_private_reads_and_updates_reject_guests_students_and_unrelated_coaches(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->getJson($path)->assertUnauthorized();
        $this->patchJson($path, ['level' => 'beginner'])->assertUnauthorized();
        $this->getJson('/api/v1/teaching/courses')->assertUnauthorized();
        foreach (['student', 'stranger'] as $actor) {
            $this->actingAs($f[$actor])->getJson($path)->assertForbidden();
            $this->patchJson($path, ['level' => 'beginner'])->assertForbidden();
        }
        $this->getJson('/api/v1/teaching/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($f['student'])->getJson('/api/v1/teaching/courses')->assertForbidden();
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id, 'level' => null, 'title' => 'Original']);
    }

    #[DataProvider('editors')]
    public function test_authorized_editors_can_classify_an_existing_course_without_changing_other_fields(string $actor): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f[$actor])->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.level', null)->assertJsonPath('data.coach.name', $f['owner']->name)
            ->assertJsonPath('data.can_change_responsible', $actor !== 'collaborator')->assertJsonMissingPath('data.coach.email');
        $this->head($path)->assertOk()->assertContent('');
        $this->patchJson($path, ['level' => 'advanced'])->assertOk()->assertJsonPath('data.level', 'advanced')
            ->assertJsonPath('data.title', 'Original')->assertJsonPath('data.price', '12.50')
            ->assertJsonCount(2, 'data.additional_coaches')->assertJsonPath('data.coach_id', $f['owner']->id);
        $this->getJson('/api/v1/courses?filter[level]=advanced')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id, 'level' => 'advanced', 'status' => 'published']);
    }

    public static function editors(): array
    {
        return [['owner'], ['collaborator'], ['admin']];
    }

    public function test_management_list_includes_assigned_drafts_but_not_other_coaches_courses(): void
    {
        $f = $this->fixture();
        $f['course']->forceFill(['status' => 'draft'])->save();
        $unrelated = new Course;
        $unrelated->forceFill(['title' => 'Other', 'description' => 'Other', 'coach_id' => $f['stranger']->id, 'specialty_id' => $f['first']->id, 'price' => 0])->save();
        foreach (['owner', 'collaborator'] as $actor) {
            $this->actingAs($f[$actor])->getJson('/api/v1/teaching/courses')->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $f['course']->id)->assertJsonPath('data.0.status', 'draft');
        }
        $this->actingAs($f['admin'])->getJson('/api/v1/teaching/courses?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $unrelated->id);
        $this->getJson('/api/v1/teaching/courses?page=0&per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
        $this->head('/api/v1/teaching/courses')->assertOk()->assertContent('');
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_collaborators_can_edit_all_course_details_and_other_teachers_but_cannot_replace_the_owner(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['collaborator'])->patchJson($path, [
            'title' => 'Updated', 'description' => 'Updated description.', 'price' => '35.00', 'level' => 'expert', 'status' => 'draft',
            'specialty_id' => $f['second']->id, 'additional_coach_ids' => [$f['collaborator']->id],
        ])->assertOk()->assertJsonPath('data.title', 'Updated')->assertJsonPath('data.price', '35.00')
            ->assertJsonPath('data.specialty.id', $f['second']->id)->assertJsonPath('data.coach_id', $f['owner']->id)
            ->assertJsonCount(1, 'data.additional_coaches');
        $this->patchJson($path, ['coach_id' => $f['stranger']->id, 'additional_coach_ids' => [$f['owner']->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('coach_id');
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id, 'coach_id' => $f['owner']->id, 'title' => 'Updated']);
        $this->deleteJson($path)->assertStatus(405);
        $this->assertDatabaseCount('courses', 1);
    }

    public function test_collaborators_may_remove_their_own_participation_but_the_responsible_coach_remains(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['collaborator'])->patchJson($path, ['additional_coach_ids' => []])->assertOk()
            ->assertJsonPath('data.coach_id', $f['owner']->id)->assertJsonCount(0, 'data.additional_coaches');
        $this->getJson($path)->assertForbidden();
        $this->getJson('/api/v1/teaching/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($f['owner'])->getJson($path)->assertOk();
    }

    public function test_owner_and_admin_can_reassign_the_responsible_coach(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['owner'])->patchJson($path, ['coach_id' => $f['stranger']->id])->assertOk()
            ->assertJsonPath('data.coach_id', $f['stranger']->id)->assertJsonPath('data.can_change_responsible', false);
        $this->getJson($path)->assertForbidden();
        $this->actingAs($f['admin'])->patchJson($path, ['coach_id' => $f['owner']->id])->assertOk()
            ->assertJsonPath('data.coach_id', $f['owner']->id)->assertJsonPath('data.can_change_responsible', true);
    }

    public function test_specialty_changes_require_valid_responsible_and_additional_teachers(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $f['owner']->specialties()->detach($f['second']->id);
        $this->actingAs($f['collaborator'])->patchJson($path, ['specialty_id' => $f['second']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('coach_id');
        $this->patchJson($path, ['additional_coach_ids' => [$f['student']->id]])->assertUnprocessable()->assertJsonValidationErrors('additional_coach_ids');
        $this->patchJson($path, ['additional_coach_ids' => [$f['owner']->id]])->assertUnprocessable()->assertJsonValidationErrors('additional_coach_ids');
        $unassigned = Specialty::create(['name' => 'Unassigned']);
        $this->patchJson($path, ['specialty_id' => $unassigned->id])->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id, 'specialty_id' => $f['first']->id]);
        $this->assertDatabaseCount('course_coach', 2);
    }

    public function test_invalid_patches_do_not_modify_the_course(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['owner']);
        foreach ([['level' => null], ['level' => 'invalid'], ['title' => ''], ['price' => -1], ['price' => '1.234'], ['status' => 'invalid'], ['cover_path' => 'outside.png'], ['additional_coach_ids' => '[]']] as $payload) {
            $this->patchJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors(array_keys($payload)[0]);
        }
        $this->patchJson($path, [])->assertUnprocessable()->assertJsonValidationErrors('course');
        $this->assertDatabaseHas('courses', ['id' => $f['course']->id, 'title' => 'Original', 'level' => null, 'price' => '12.50']);
        $this->actingAs($f['admin'])->getJson('/api/v1/courses/999999')->assertNotFound();
    }

    public function test_admin_can_read_and_repair_legacy_courses_without_assignments(): void
    {
        $f = $this->fixture();
        $f['course']->forceFill(['coach_id' => null, 'specialty_id' => null])->save();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['admin'])->getJson($path)->assertOk()->assertJsonPath('data.coach', null)->assertJsonPath('data.specialty', null);
        $this->patchJson($path, ['coach_id' => $f['owner']->id, 'specialty_id' => $f['first']->id, 'level' => 'beginner'])
            ->assertOk()->assertJsonPath('data.level', 'beginner');
    }

    public function test_json_and_multipart_updates_require_csrf_even_without_origin(): void
    {
        $f = $this->fixture();
        $path = '/api/v1/courses/'.$f['course']->id;
        $this->actingAs($f['owner']);
        $this->app->instance('env', 'local');
        $this->patchJson($path, ['level' => 'beginner'])->assertStatus(419);
        $this->post($path, ['_method' => 'PATCH', 'cover' => UploadedFile::fake()->createWithContent('cover.png', $this->png())], ['Accept' => 'application/json'])->assertStatus(419);
        $this->withSession(['_token' => 'editing-test-token'])->withHeader('X-CSRF-TOKEN', 'editing-test-token')
            ->patchJson($path, ['level' => 'beginner'])->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_cover_replacement_and_removal_delete_the_old_file_only_after_success(): void
    {
        $f = $this->fixture();
        $course = $f['course'];
        $course->forceFill(['cover_path' => 'courses/covers/old.png'])->save();
        Storage::disk('local')->put($course->cover_path, $this->png());
        $path = '/api/v1/courses/'.$course->id;
        $this->actingAs($f['collaborator'])->post($path, ['_method' => 'PATCH', 'additional_coach_ids' => '[]', 'cover' => UploadedFile::fake()->createWithContent('new.png', $this->png(2))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonMissingPath('data.cover_path')->assertJsonCount(0, 'data.additional_coaches');
        $newPath = $course->fresh()->cover_path;
        $this->assertNotSame('courses/covers/old.png', $newPath);
        Storage::disk('local')->assertMissing('courses/covers/old.png');
        Storage::disk('local')->assertExists($newPath);
        $this->actingAs($f['owner']);
        $this->post($path, ['_method' => 'PATCH', 'cover' => UploadedFile::fake()->createWithContent('tall.png', $this->png(1, 2049))], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('cover');
        $this->post($path, ['_method' => 'PATCH', 'remove_cover' => '1', 'cover' => UploadedFile::fake()->createWithContent('new.png', $this->png())], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('cover');
        $this->assertSame($newPath, $course->fresh()->cover_path);
        $this->patchJson($path, ['remove_cover' => true])->assertOk()->assertJsonPath('data.cover_url', null);
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_a_failed_update_preserves_the_original_file_and_cleans_up_the_replacement(): void
    {
        $f = $this->fixture();
        $course = $f['course'];
        $course->forceFill(['cover_path' => 'courses/covers/old.png'])->save();
        Storage::disk('local')->put($course->cover_path, $this->png());
        Log::spy();
        Course::updating(fn () => throw new RuntimeException('Test-only update failure.'));
        try {
            $this->actingAs($f['owner'])->post('/api/v1/courses/'.$course->id, ['_method' => 'PATCH', 'cover' => UploadedFile::fake()->createWithContent('new.png', $this->png(2))], ['Accept' => 'application/json'])
                ->assertStatus(500);
        } finally {
            Course::flushEventListeners();
        }
        $this->assertSame('courses/covers/old.png', $course->fresh()->cover_path);
        Storage::disk('local')->assertExists('courses/covers/old.png');
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_service_rechecks_the_editors_current_role(): void
    {
        $f = $this->fixture();
        User::whereKey($f['collaborator']->id)->update(['role' => UserRole::Student]);
        $this->expectException(AuthorizationException::class);
        app(CourseUpdateService::class)->update($f['collaborator'], $f['course'], ['level' => 'expert']);
    }
}
