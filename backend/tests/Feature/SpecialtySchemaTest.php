<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class SpecialtySchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email, UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => 'Test User', 'email' => $email, 'password' => 'Schema-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_rename_preserves_existing_categories_courses_and_references_in_both_directions(): void
    {
        $rename = require database_path('migrations/2026_11_15_000003_rename_categories_to_specialties.php');
        $memberships = require database_path('migrations/2026_11_15_000004_create_coach_specialty_table.php');
        $memberships->down();
        $rename->down();
        $category = DB::table('categories')->insertGetId(['name' => 'Programming']);
        $course = DB::table('courses')->insertGetId([
            'title' => 'Existing course', 'description' => 'Preserve this course.',
            'category_id' => $category, 'status' => 'published', 'price' => '19.95',
        ]);

        $rename->up();
        $memberships->up();
        $this->assertFalse(Schema::hasTable('categories'));
        $this->assertFalse(Schema::hasColumn('courses', 'category_id'));
        $this->assertDatabaseHas('specialties', ['id' => $category, 'name' => 'Programming']);
        $this->assertDatabaseHas('courses', [
            'id' => $course, 'title' => 'Existing course', 'description' => 'Preserve this course.',
            'specialty_id' => $category, 'status' => 'published', 'price' => '19.95',
        ]);

        $memberships->down();
        $rename->down();
        $this->assertDatabaseHas('categories', ['id' => $category, 'name' => 'Programming']);
        $this->assertDatabaseHas('courses', ['id' => $course, 'category_id' => $category, 'price' => '19.95']);
        $rename->up();
        $memberships->up();
    }

    public function test_relations_filter_coaches_by_specialty_and_support_multiple_specialties(): void
    {
        $programming = Specialty::create(['name' => 'Programming']);
        $counterStrike = Specialty::create(['name' => 'Counter-Strike']);
        $coach = $this->createUser('coach@example.test');
        $otherCoach = $this->createUser('other@example.test');
        $student = $this->createUser('student@example.test', UserRole::Student);
        $coach->specialties()->attach([$programming->id, $counterStrike->id]);
        $otherCoach->specialties()->attach($counterStrike->id);
        // The FK cannot enforce global roles; the relation must exclude non-Coaches.
        $student->specialties()->attach($programming->id);

        $this->assertSame([$coach->id], $programming->coaches()->pluck('users.id')->all());
        $this->assertSame([$coach->id, $otherCoach->id], $counterStrike->coaches()->orderBy('users.id')->pluck('users.id')->all());
        $this->assertSame([$programming->id, $counterStrike->id], $coach->specialties()->orderBy('specialties.id')->pluck('specialties.id')->all());

        $course = new Course;
        $course->title = 'Programming basics';
        $course->description = 'Course relation fixture.';
        $course->price = '49.90';
        $course->coach()->associate($coach);
        $course->specialty()->associate($programming);
        $course->save();

        $this->assertTrue($course->fresh()->coach->is($coach));
        $this->assertTrue($course->fresh()->specialty->is($programming));
        $this->assertTrue($programming->courses()->sole()->is($course));
        $this->assertSame('49.90', $course->fresh()->price);
    }

    public function test_membership_cannot_be_duplicated(): void
    {
        $coach = $this->createUser('coach@example.test');
        $specialty = Specialty::create(['name' => 'Programming']);
        $coach->specialties()->attach($specialty->id);

        $this->expectException(QueryException::class);
        $coach->specialties()->attach($specialty->id);
    }

    #[DataProvider('missingMembershipReferences')]
    public function test_memberships_require_existing_references(string $missingKey): void
    {
        $coach = $this->createUser('coach@example.test');
        $specialty = Specialty::create(['name' => 'Programming']);
        $attributes = ['coach_id' => $coach->id, 'specialty_id' => $specialty->id];
        $attributes[$missingKey] = 999999;

        $this->expectException(QueryException::class);
        DB::table('coach_specialty')->insert($attributes);
    }

    public static function missingMembershipReferences(): array
    {
        return [['coach_id'], ['specialty_id']];
    }

    public function test_deleting_unreferenced_coaches_or_specialties_removes_only_their_memberships(): void
    {
        $coach = $this->createUser('coach@example.test');
        $programming = Specialty::create(['name' => 'Programming']);
        $counterStrike = Specialty::create(['name' => 'Counter-Strike']);
        $coach->specialties()->attach([$programming->id, $counterStrike->id]);
        $programming->delete();

        $this->assertDatabaseCount('coach_specialty', 1);
        $this->assertDatabaseHas('users', ['id' => $coach->id]);
        $coach->delete();
        $this->assertDatabaseCount('coach_specialty', 0);
        $this->assertDatabaseHas('specialties', ['id' => $counterStrike->id]);
    }

    public function test_specialty_names_remain_unique_after_renaming(): void
    {
        Specialty::create(['name' => 'Programming']);

        $this->expectException(QueryException::class);
        Specialty::create(['name' => 'Programming']);
    }
}
