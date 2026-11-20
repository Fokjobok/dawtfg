<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CourseCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

final class CourseCoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => 'Test User', 'email' => 'cover'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function details(User $owner): array
    {
        $specialty = Specialty::create(['name' => 'Programming']);
        $owner->specialties()->attach($specialty);

        return ['title' => 'Course', 'description' => 'Description', 'specialty_id' => $specialty->id, 'price' => '19.90'];
    }

    private function png(int $width = 1, int $height = 1): string
    {
        // Generate a real RGB PNG fixture without installing a GD dependency.
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width * 3), $height)))
            .$chunk('IEND', '');
    }

    public function test_multipart_creation_stores_a_private_cover_with_a_generated_name_and_serves_the_image(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        $bytes = $this->png(4096, 2048);
        $response = $this->actingAs($owner)->post('/api/v1/courses', [...$details, 'cover' => UploadedFile::fake()->createWithContent('original.png', $bytes)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonMissingPath('data.cover_path');
        $course = Course::findOrFail($response->json('data.id'));
        $this->assertStringStartsWith('courses/covers/', $course->cover_path);
        $this->assertNotSame('original.png', basename($course->cover_path));
        Storage::disk('local')->assertExists($course->cover_path);
        $url = '/api/v1/courses/'.$course->id.'/cover';
        $response->assertJsonPath('data.cover_url', $url);
        $image = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($bytes, $image->streamedContent());
        $this->head($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_cover_access_is_limited_to_course_coaches_and_administrator(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        $collaborator = $this->user();
        $collaborator->specialties()->attach($details['specialty_id']);
        $course = app(CourseCreationService::class)->create($owner, [...$details, 'additional_coach_ids' => [$collaborator->id], 'cover' => UploadedFile::fake()->createWithContent('cover.png', $this->png())]);
        $url = '/api/v1/courses/'.$course->id.'/cover';
        $this->getJson($url)->assertUnauthorized();
        foreach ([$owner, $collaborator, $this->user(UserRole::Administrator)] as $user) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $outsider = $this->user();
        $outsider->specialties()->attach($details['specialty_id']);
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($this->user(UserRole::Student))->getJson($url)->assertForbidden();
    }

    public function test_multipart_creation_requires_csrf_even_without_origin(): void
    {
        $owner = $this->user();
        $details = [...$this->details($owner), 'cover' => UploadedFile::fake()->createWithContent('cover.png', $this->png())];
        $this->actingAs($owner);
        $this->app->instance('env', 'local');
        $this->post('/api/v1/courses', $details, ['Accept' => 'application/json'])->assertStatus(419);
        $this->assertDatabaseCount('courses', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->withSession(['_token' => 'cover-test-token'])->withHeader('X-CSRF-TOKEN', 'cover-test-token')
            ->post('/api/v1/courses', $details, ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_invalid_types_sizes_and_dimensions_are_rejected_without_files_or_courses(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        $this->actingAs($owner);
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->createWithContent('large.png', $this->png().str_repeat('x', 2 * 1024 * 1024)),
            UploadedFile::fake()->createWithContent('wide.png', $this->png(4097)),
            UploadedFile::fake()->createWithContent('tall.png', $this->png(1, 2049)),
        ] as $cover) {
            $this->post('/api/v1/courses', [...$details, 'cover' => $cover], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('cover');
        }
        $this->assertDatabaseCount('courses', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_a_failed_database_write_cleans_up_the_cover(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        Log::spy();
        Course::creating(fn () => throw new RuntimeException('Test-only database failure.'));
        try {
            $this->actingAs($owner)->post('/api/v1/courses', [...$details, 'cover' => UploadedFile::fake()->createWithContent('cover.png', $this->png())], ['Accept' => 'application/json'])
                ->assertStatus(500)->assertJsonPath('code', 500);
        } finally {
            Course::flushEventListeners();
        }
        $this->assertDatabaseCount('courses', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_storage_failure_does_not_create_a_course(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        $cover = Mockery::mock(UploadedFile::class);
        $cover->shouldReceive('store')->once()->with('courses/covers', 'local')->andReturn(false);
        try {
            app(CourseCreationService::class)->create($owner, [...$details, 'cover' => $cover]);
            $this->fail('The failed upload was persisted as a course.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to store the course cover.', $exception->getMessage());
        }
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_missing_covers_and_courses_return_not_found(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        $course = app(CourseCreationService::class)->create($owner, $details);
        $this->actingAs($owner)->getJson('/api/v1/courses/'.$course->id.'/cover')->assertNotFound();
        $this->getJson('/api/v1/courses/999999/cover')->assertNotFound();
    }

    public function test_cover_migration_preserves_existing_courses(): void
    {
        $owner = $this->user();
        $details = $this->details($owner);
        $course = app(CourseCreationService::class)->create($owner, $details);
        $migration = require database_path('migrations/2026_11_18_000001_add_cover_path_to_courses_table.php');
        $migration->down();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'coach_id' => $owner->id]);
        $migration->up();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'cover_path' => null]);
    }
}
