<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CategoryAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Administrator): User
    {
        $user = new User(['name' => 'Category '.User::count(), 'email' => 'category'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function course(?Category $category = null, array $attributes = []): Course
    {
        $specialty = Specialty::create(['name' => 'Specialty '.Specialty::count(), 'category_id' => $category?->id]);
        $coach = $this->user(UserRole::Coach);
        $coach->specialties()->attach($specialty->id);
        $course = new Course;
        $course->forceFill(['title' => 'Course '.Course::count(), 'description' => 'Description', 'specialty_id' => $specialty->id, 'coach_id' => $coach->id, 'status' => 'published', 'price' => 0, 'level' => 'beginner', ...$attributes])->save();

        return $course;
    }

    public function test_visitors_can_read_catalog_options_but_cannot_manage_categories(): void
    {
        $specialty = Specialty::create(['name' => 'Game']);
        $this->getJson('/api/v1/categories')->assertUnauthorized();
        $this->postJson('/api/v1/categories', ['name' => 'Games'])->assertUnauthorized();
        $this->patchJson('/api/v1/specialties/'.$specialty->id, ['category_id' => null])->assertUnauthorized();
        $this->getJson('/api/v1/catalog/categories')->assertOk()->assertJsonPath('data', []);
        $this->head('/api/v1/catalog/categories')->assertOk()->assertContent('');
    }

    #[DataProvider('otherRoles')]
    public function test_only_administrators_can_create_and_classify(UserRole $role): void
    {
        $specialty = Specialty::create(['name' => 'Game']);
        $this->actingAs($this->user($role), 'web');
        $this->getJson('/api/v1/categories')->assertForbidden();
        $this->postJson('/api/v1/categories', ['name' => 'Games'])->assertForbidden();
        $this->patchJson('/api/v1/specialties/'.$specialty->id, ['category_id' => null])->assertForbidden();
        $this->assertDatabaseCount('specialty_categories', 0);
    }

    public static function otherRoles(): array
    {
        return [[UserRole::Coach], [UserRole::Student]];
    }

    public function test_admin_can_create_trimmed_unique_names_and_list_all_categories(): void
    {
        $this->actingAs($this->user(), 'web')->postJson('/api/v1/categories', ['name' => '  Videojuegos  '])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.name', 'Videojuegos');
        $this->postJson('/api/v1/categories', ['name' => 'Videojuegos'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/categories', ['name' => ' '])->assertUnprocessable();
        $this->postJson('/api/v1/categories', ['name' => str_repeat('a', 101)])->assertUnprocessable();
        $this->postJson('/api/v1/categories', ['name' => 'Asignaturas'])->assertCreated();
        $this->getJson('/api/v1/categories?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.name', 'Asignaturas');
        $this->getJson('/api/v1/categories?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/catalog/categories')->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('specialties', 0);
    }

    public function test_classification_preserves_the_specialty_courses_memberships_and_profile_save_limits(): void
    {
        $course = $this->course();
        $category = Category::create(['name' => 'Videojuegos']);
        $actor = $this->user();
        $path = '/api/v1/specialties/'.$course->specialty_id;
        $this->actingAs($actor, 'web')->patchJson($path, ['category_id' => $category->id])
            ->assertOk()->assertJsonPath('data.id', $course->specialty_id)->assertJsonPath('data.category.id', $category->id);
        $this->getJson('/api/v1/specialties')->assertOk()->assertJsonPath('data.0.category.name', 'Videojuegos');
        $updatedAt = $course->specialty->fresh()->updated_at->toISOString();
        $this->travel(5)->seconds();
        $this->patchJson($path, ['category_id' => $category->id])->assertOk();
        $this->assertSame($updatedAt, $course->specialty->fresh()->updated_at->toISOString());
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'specialty_id' => $course->specialty_id, 'coach_id' => $course->coach_id]);
        $this->assertDatabaseHas('coach_specialty', ['coach_id' => $course->coach_id, 'specialty_id' => $course->specialty_id]);
        $this->assertSame(0, $actor->fresh()->profile_save_count);
        $this->patchJson($path, ['category_id' => null])->assertOk()->assertJsonPath('data.category', null);
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_a_new_specialty_can_be_classified_without_assigning_a_coach(): void
    {
        $category = Category::create(['name' => 'Asignaturas']);
        $this->actingAs($this->user(), 'web')->postJson('/api/v1/specialties', ['name' => 'Matemáticas', 'category_id' => $category->id])
            ->assertCreated()->assertJsonPath('data.category.id', $category->id);
        $this->assertDatabaseCount('coach_specialty', 0);
        $this->postJson('/api/v1/specialties', ['name' => 'Invalid', 'category_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_public_categories_and_subjects_only_derive_from_visible_courses(): void
    {
        $games = Category::create(['name' => 'Games']);
        $private = Category::create(['name' => 'Private category']);
        Category::create(['name' => 'Empty']);
        $public = $this->course($games);
        $unclassified = $this->course();
        $this->course($private, ['status' => 'draft']);
        $invalid = $this->course($private);
        $invalid->coach->specialties()->detach();
        $this->getJson('/api/v1/catalog/categories')->assertOk()
            ->assertJsonPath('data', [['id' => $games->id, 'name' => 'Games']]);
        $this->getJson('/api/v1/catalog/specialties?filter[category_id]='.$games->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $public->specialty_id)
            ->assertJsonPath('data.0.category.id', $games->id);
        $this->getJson('/api/v1/catalog/specialties')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/courses?filter[specialty_id]='.$unclassified->specialty_id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/catalog/specialties?filter[category_id]=999999')->assertOk()->assertJsonPath('data', []);
    }

    public function test_category_specialty_and_level_filters_intersect_before_pagination(): void
    {
        $games = Category::create(['name' => 'Games']);
        $subjects = Category::create(['name' => 'Subjects']);
        $first = $this->course($games, ['level' => 'advanced']);
        $second = $this->course($games, ['level' => 'advanced']);
        $this->course($games, ['level' => 'expert']);
        $other = $this->course($subjects, ['level' => 'advanced']);
        $this->course(null, ['level' => 'advanced']);
        $query = '/api/v1/courses?filter[category_id]='.$games->id.'&filter[level]=advanced&per_page=1';
        $this->getJson($query)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $second->id);
        $this->getJson($query.'&page=2')->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->getJson($query.'&filter[specialty_id]='.$other->specialty_id)->assertOk()->assertJsonPath('data', []);
        $this->getJson($query.'&filter[specialty_id]='.$first->specialty_id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/courses?filter[category_id]=999999')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('meta.total', 5);
    }

    public function test_classification_and_query_validation_do_not_change_data(): void
    {
        $specialty = Specialty::create(['name' => 'Original']);
        $this->actingAs($this->user(), 'web');
        $path = '/api/v1/specialties/'.$specialty->id;
        foreach ([[], ['category_id' => 999999], ['category_id' => 'bad'], ['category_id' => null, 'name' => 'Changed']] as $input) {
            $this->patchJson($path, $input)->assertUnprocessable();
        }
        $this->patchJson('/api/v1/specialties/999999', ['category_id' => null])->assertNotFound();
        $this->getJson('/api/v1/courses?filter[category_id]=-1')->assertUnprocessable();
        $this->getJson('/api/v1/catalog/specialties?filter[category_id]=bad')->assertUnprocessable();
        $this->assertSame('Original', $specialty->fresh()->name);
        $this->assertNull($specialty->fresh()->category_id);
    }

    public function test_category_creation_and_assignment_require_csrf_without_origin(): void
    {
        $specialty = Specialty::create(['name' => 'Game']);
        $this->actingAs($this->user(), 'web');
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/categories', ['name' => 'Games'])->assertStatus(419);
        $this->patchJson('/api/v1/specialties/'.$specialty->id, ['category_id' => null])->assertStatus(419);
        $this->withSession(['_token' => 'category-test-token'])->withHeader('X-CSRF-TOKEN', 'category-test-token')
            ->postJson('/api/v1/categories', ['name' => 'Games'])->assertCreated();
        $this->patchJson('/api/v1/specialties/'.$specialty->id, ['category_id' => Category::firstOrFail()->id])->assertOk();
    }

    public function test_stale_administrator_identity_cannot_classify(): void
    {
        $actor = $this->user();
        $specialty = Specialty::create(['name' => 'Game']);
        User::whereKey($actor->id)->update(['role' => UserRole::Student->value]);
        $this->expectException(AuthorizationException::class);
        app(CategoryAdministrationService::class)->classify($actor, $specialty, null);
    }

    public function test_stale_administrator_identity_cannot_create_categories(): void
    {
        $actor = $this->user();
        User::whereKey($actor->id)->update(['role' => UserRole::Student->value]);
        $this->expectException(AuthorizationException::class);
        app(CategoryAdministrationService::class)->create($actor, ['name' => 'Games']);
    }

    public function test_database_restricts_removing_a_referenced_category(): void
    {
        $category = Category::create(['name' => 'Games']);
        Specialty::create(['name' => 'Game', 'category_id' => $category->id]);
        $this->expectException(QueryException::class);
        $category->delete();
    }

    public function test_database_rejects_duplicate_category_names(): void
    {
        Category::create(['name' => 'Games']);
        $this->expectException(QueryException::class);
        Category::create(['name' => 'Games']);
    }

    public function test_database_rejects_a_missing_category_reference(): void
    {
        $this->expectException(QueryException::class);
        Specialty::create(['name' => 'Missing parent', 'category_id' => 999999]);
    }

    public function test_migration_preserves_existing_courses_and_specialties_on_upgrade_and_rollback(): void
    {
        $course = $this->course();
        $migration = require database_path('migrations/2026_11_25_000001_create_category_hierarchy.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('specialties', ['id' => $course->specialty_id, 'category_id' => null]);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'specialty_id' => $course->specialty_id, 'coach_id' => $course->coach_id]);
        $this->assertDatabaseCount('specialty_categories', 0);
    }
}
