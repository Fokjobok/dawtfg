<?php

namespace Tests\Feature;

use App\Enums\CourseLevel;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CourseLevelTest extends TestCase
{
    use RefreshDatabase;

    private function coach(): User
    {
        $coach = new User(['name' => 'Coach', 'email' => 'level'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $coach->role = UserRole::Coach;
        $coach->save();

        return $coach;
    }

    private function details(User $coach): array
    {
        $specialty = Specialty::create(['name' => 'Specialty '.Specialty::count()]);
        $coach->specialties()->attach($specialty);

        return ['title' => 'Course', 'description' => 'Description.', 'coach_id' => $coach->id, 'specialty_id' => $specialty->id, 'price' => 0];
    }

    #[DataProvider('levels')]
    public function test_both_authorized_roles_can_save_each_level(UserRole $role, string $level): void
    {
        $coach = $this->coach();
        $details = $this->details($coach);
        $actor = $role === UserRole::Coach ? $coach : $this->coach();
        $actor->role = $role;
        $actor->save();

        $response = $this->actingAs($actor)->postJson('/api/v1/courses', [...$details, 'level' => $level, 'status' => 'published'])
            ->assertCreated()->assertJsonPath('data.level', $level);
        $this->assertDatabaseHas('courses', ['id' => $response->json('data.id'), 'level' => $level]);
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('data.0.level', $level);
    }

    public static function levels(): array
    {
        $cases = [];
        foreach ([UserRole::Coach, UserRole::Administrator] as $role) {
            foreach (CourseLevel::cases() as $level) {
                $cases[] = [$role, $level->value];
            }
        }

        return $cases;
    }

    public function test_omitted_levels_stay_unknown_and_invalid_values_do_not_create_courses(): void
    {
        $coach = $this->coach();
        $details = $this->details($coach);
        $this->actingAs($coach);
        foreach ([null, '', 'intermediate', [], 1] as $level) {
            $this->postJson('/api/v1/courses', [...$details, 'level' => $level])->assertUnprocessable()->assertJsonValidationErrors('level');
        }
        $this->assertDatabaseCount('courses', 0);
        $response = $this->postJson('/api/v1/courses', $details)->assertCreated()->assertJsonPath('data.level', null);
        $this->assertDatabaseHas('courses', ['id' => $response->json('data.id'), 'level' => null]);
        $this->actingAs($this->coach());
        $this->postJson('/api/v1/courses', [...$details, 'level' => 'beginner'])->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $this->assertDatabaseCount('courses', 1);
    }

    public function test_public_filters_combine_before_pagination_and_preserve_visibility_rules(): void
    {
        $coach = $this->coach();
        $first = $this->details($coach);
        $second = $this->details($coach);
        foreach ([$first, $second] as $details) {
            foreach (CourseLevel::cases() as $level) {
                (new Course)->forceFill([...$details, 'level' => $level->value, 'status' => 'published'])->save();
            }
        }
        $old = Course::where('specialty_id', $first['specialty_id'])->where('level', 'beginner')->firstOrFail();
        $new = new Course;
        $new->forceFill([...$first, 'level' => 'beginner', 'status' => 'published'])->save();
        (new Course)->forceFill([...$first, 'level' => null, 'status' => 'published'])->save();
        (new Course)->forceFill([...$first, 'level' => 'beginner', 'status' => 'draft'])->save();
        (new Course)->forceFill([...$first, 'level' => 'beginner', 'status' => 'published', 'coach_id' => null])->save();

        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('meta.total', 8);
        $this->getJson('/api/v1/courses?filter[level]=beginner')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/courses?filter[specialty_id]='.$first['specialty_id'])->assertOk()->assertJsonPath('meta.total', 5);
        $path = '/api/v1/courses?filter[level]=beginner&filter[specialty_id]='.$first['specialty_id'].'&per_page=1';
        $response = $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $new->id);
        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $query);
        $this->assertSame(['level' => 'beginner', 'specialty_id' => (string) $first['specialty_id']], $query['filter']);
        $this->getJson($path.'&page=2')->assertOk()->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/courses?filter[specialty_id]=999999')->assertOk()->assertJsonPath('meta.total', 0);
        $this->head($path)->assertOk()->assertContent('');
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        foreach ([
            ['filter=beginner', 'filter'],
            ['filter[level]=invalid', 'filter.level'],
            ['filter[level][]=beginner', 'filter.level'],
            ['filter[specialty_id]=0', 'filter.specialty_id'],
            ['filter[specialty_id]=text', 'filter.specialty_id'],
            ['filter[specialty_id][]=1', 'filter.specialty_id'],
        ] as [$query, $field]) {
            $this->getJson('/api/v1/courses?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_catalog_specialty_options_are_public_distinct_and_hide_private_or_invalid_courses(): void
    {
        $coach = $this->coach();
        $first = $this->details($coach);
        (new Course)->forceFill([...$first, 'status' => 'published'])->save();
        (new Course)->forceFill([...$first, 'status' => 'published'])->save();
        $private = $this->details($coach);
        (new Course)->forceFill([...$private, 'status' => 'draft'])->save();
        $invalid = $this->details($coach);
        (new Course)->forceFill([...$invalid, 'status' => 'published', 'coach_id' => null])->save();
        Specialty::create(['name' => 'Empty']);
        $response = $this->getJson('/api/v1/catalog/specialties')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data', [['id' => $first['specialty_id'], 'name' => Specialty::findOrFail($first['specialty_id'])->name, 'category' => null]]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/catalog/specialties?page=0&per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
        $this->head('/api/v1/catalog/specialties')->assertOk()->assertContent('');
        $coach->role = UserRole::Student;
        $coach->save();
        $this->actingAs($coach)->getJson('/api/v1/catalog/specialties')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/specialties')->assertForbidden();
    }

    public function test_migration_preserves_existing_courses_without_inventing_levels(): void
    {
        $coach = $this->coach();
        $course = new Course;
        $course->forceFill([...$this->details($coach), 'status' => 'published'])->save();
        $migration = require database_path('migrations/2026_11_20_000001_add_level_to_courses_table.php');
        $migration->down();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'title' => 'Course', 'status' => 'published']);
        $migration->up();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'title' => 'Course', 'status' => 'published', 'level' => null]);
    }
}
