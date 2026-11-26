<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class CourseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): int
    {
        return DB::table('courses')->insertGetId([
            'title' => 'Test course',
            'description' => 'Description for schema tests.',
            ...$attributes,
        ]);
    }

    public function test_existing_courses_survive_upgrade_and_rollback(): void
    {
        $details = require database_path('migrations/2026_11_15_000002_add_details_to_courses_table.php');
        $categories = require database_path('migrations/2026_11_15_000001_create_categories_table.php');
        $rename = require database_path('migrations/2026_11_15_000003_rename_categories_to_specialties.php');
        $memberships = require database_path('migrations/2026_11_15_000004_create_coach_specialty_table.php');
        $hierarchy = require database_path('migrations/2026_11_25_000001_create_category_hierarchy.php');
        $hierarchy->down();
        // Roll back the newer specialty dependency before recreating the historical schema.
        $medals = require database_path('migrations/2026_11_24_000001_create_medals_tables.php');
        $medals->down();
        $memberships->down();
        $rename->down();
        $details->down();
        $categories->down();
        $id = $this->createCourse();

        $categories->up();
        $details->up();
        $rename->up();
        $memberships->up();

        $this->assertDatabaseHas('courses', [
            'id' => $id,
            'title' => 'Test course',
            'description' => 'Description for schema tests.',
            'coach_id' => null,
            'specialty_id' => null,
            'status' => 'draft',
            'price' => 0,
        ]);

        $memberships->down();
        $rename->down();
        $details->down();
        $categories->down();
        $this->assertDatabaseHas('courses', ['id' => $id, 'title' => 'Test course']);
        $this->assertFalse(Schema::hasColumn('courses', 'coach_id'));
        $this->assertFalse(Schema::hasColumn('courses', 'price'));
        $this->assertFalse(Schema::hasTable('categories'));

        // Restore the schema before RefreshDatabase rolls back this test's transaction.
        $categories->up();
        $details->up();
        $rename->up();
        $memberships->up();
        $medals->up();
        $hierarchy->up();
    }

    public function test_a_course_can_reference_a_coach_and_specialty_with_a_price(): void
    {
        $coach = DB::table('users')->insertGetId([
            'name' => 'Test Coach', 'email' => 'coach@example.test',
            'password' => 'unused-schema-fixture', 'role' => 'Coach',
        ]);
        $specialty = DB::table('specialties')->insertGetId(['name' => 'Strategy']);
        $id = $this->createCourse([
            'coach_id' => $coach, 'specialty_id' => $specialty,
            'status' => 'published', 'price' => '49.90',
        ]);

        $this->assertDatabaseHas('courses', [
            'id' => $id, 'coach_id' => $coach, 'specialty_id' => $specialty,
            'status' => 'published', 'price' => '49.90',
        ]);
    }

    #[DataProvider('invalidCourseAttributes')]
    public function test_database_rejects_invalid_course_attributes(array $attributes): void
    {
        $this->expectException(QueryException::class);
        $this->createCourse($attributes);
    }

    public static function invalidCourseAttributes(): array
    {
        return [
            'negative price' => [['price' => '-0.01']],
            'unknown status' => [['status' => 'unknown']],
            'missing coach' => [['coach_id' => 999999]],
            'missing specialty' => [['specialty_id' => 999999]],
        ];
    }

    #[DataProvider('referencedTables')]
    public function test_referenced_coaches_and_specialties_cannot_be_deleted(string $table, string $foreignKey): void
    {
        $attributes = $table === 'users'
            ? ['name' => 'Test Coach', 'email' => 'coach@example.test', 'password' => 'unused-schema-fixture', 'role' => 'Coach']
            : ['name' => 'Strategy'];
        $id = DB::table($table)->insertGetId($attributes);
        $this->createCourse([$foreignKey => $id]);

        $this->expectException(QueryException::class);
        DB::table($table)->where('id', $id)->delete();
    }

    public static function referencedTables(): array
    {
        return [['users', 'coach_id'], ['specialties', 'specialty_id']];
    }
}
