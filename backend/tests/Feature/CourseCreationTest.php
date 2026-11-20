<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Specialty;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CourseCreationTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Coach): User
    {
        $user = new User(['name' => 'Test User', 'email' => 'user'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function details(User $coach): array
    {
        $specialty = Specialty::create(['name' => 'Programming']);
        $coach->specialties()->attach($specialty);

        return ['title' => 'Introduction to JavaScript', 'description' => 'Learn functions and objects.', 'specialty_id' => $specialty->id, 'price' => '19.90'];
    }

    public function test_guests_are_rejected_with_json_even_without_accept_header(): void
    {
        $this->post('/api/v1/courses')->assertUnauthorized()->assertExactJson(['error' => 'Unauthorized', 'code' => 401]);
        $this->assertDatabaseCount('courses', 0);
    }

    #[DataProvider('otherRoles')]
    public function test_students_cannot_create_courses(UserRole $role): void
    {
        $this->actingAs($this->user($role))->postJson('/api/v1/courses', [])->assertForbidden();
        $this->assertDatabaseCount('courses', 0);
    }

    public static function otherRoles(): array
    {
        return [[UserRole::Student]];
    }

    public function test_coach_creates_a_draft_owned_by_the_session_user(): void
    {
        $coach = $this->user();
        $details = $this->details($coach);

        $response = $this->actingAs($coach)->postJson('/api/v1/courses', $details)
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Location', '/api/v1/courses/'.$response->json('data.id'));
        $response->assertExactJson(['data' => ['id' => $response->json('data.id'), ...$details, 'coach_id' => $coach->id, 'status' => 'draft', 'level' => null, 'additional_coaches' => [], 'cover_url' => null]]);
        $this->assertDatabaseHas('courses', ['id' => $response->json('data.id'), 'coach_id' => $coach->id, 'status' => 'draft', 'title' => $details['title'], 'specialty_id' => $details['specialty_id']]);
        $this->assertSame('19.90', Course::firstOrFail()->price);

        // Once a course exists, administrative edits must preserve its assignments.
        $admin = $this->user(UserRole::Administrator);
        foreach ([['role' => 'Student'], ['specialty_ids' => []]] as $change) {
            try {
                app(UserAdministrationService::class)->update($admin, $coach, $change);
                $this->fail('An administrative update invalidated an existing course.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(isset($change['role']) ? 'role' : 'specialty_ids', $exception->errors());
            }
        }
    }

    public function test_free_courses_are_allowed(): void
    {
        $coach = $this->user();
        $details = $this->details($coach);
        $this->actingAs($coach)->postJson('/api/v1/courses', [...$details, 'price' => 0])
            ->assertCreated()->assertJsonPath('data.price', '0.00');
    }

    public function test_required_fields_and_limits_are_validated(): void
    {
        $coach = $this->user();
        $this->actingAs($coach)->postJson('/api/v1/courses', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'specialty_id', 'price']);
        $details = $this->details($coach);
        $this->postJson('/api/v1/courses', [...$details, 'title' => str_repeat('x', 256), 'description' => str_repeat('x', 10001), 'specialty_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'specialty_id']);
        $this->postJson('/api/v1/courses', [...$details, 'title' => '   ', 'description' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description']);
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_field_limits_and_maximum_price_are_accepted_without_rounding(): void
    {
        $coach = $this->user();
        $details = $this->details($coach);
        $this->actingAs($coach)->postJson('/api/v1/courses', [...$details, 'title' => str_repeat('x', 255), 'description' => str_repeat('x', 10000), 'price' => '99999999.99'])
            ->assertCreated()->assertJsonPath('data.price', '99999999.99');
        $this->assertSame('99999999.99', Course::firstOrFail()->price);
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_prices_do_not_create_courses(mixed $price): void
    {
        $coach = $this->user();
        $details = $this->details($coach);
        $this->actingAs($coach)->postJson('/api/v1/courses', [...$details, 'price' => $price])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->assertDatabaseCount('courses', 0);
    }

    public static function invalidPrices(): array
    {
        return [[-1], ['0.001'], ['100000000'], ['1e2'], ['19,90'], ['not a number'], [null], [[]]];
    }

    public function test_a_coach_cannot_use_unassigned_specialties_or_invalid_owners(): void
    {
        $coach = $this->user();
        $details = $this->details($coach);
        $otherCoach = $this->user();
        $otherSpecialty = Specialty::create(['name' => 'Valorant']);
        $otherCoach->specialties()->attach($otherSpecialty);
        $this->actingAs($coach)->postJson('/api/v1/courses', [...$details, 'specialty_id' => $otherSpecialty->id])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $this->postJson('/api/v1/courses', [...$details, 'coach_id' => $otherCoach->id, 'status' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/courses', [...$details, 'coach_id' => $otherCoach->id])
            ->assertUnprocessable()->assertJsonValidationErrors('coach_id');
        $coach->specialties()->detach();
        $this->postJson('/api/v1/courses', $details)->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_the_role_is_rechecked_before_inserting_even_if_the_session_user_is_stale(): void
    {
        $coach = $this->user();
        $details = $this->details($coach);
        $this->actingAs($coach);
        User::whereKey($coach->id)->update(['role' => UserRole::Student->value]);
        $this->postJson('/api/v1/courses', $details)->assertForbidden();
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_creation_requires_csrf_even_without_origin(): void
    {
        $coach = $this->user();
        $details = $this->details($coach);
        $this->actingAs($coach);
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/courses', $details)->assertStatus(419);
        $this->assertDatabaseCount('courses', 0);
        $this->withSession(['_token' => 'course-test-token'])->withHeader('X-CSRF-TOKEN', 'course-test-token')
            ->postJson('/api/v1/courses', $details)->assertCreated();
    }
}
