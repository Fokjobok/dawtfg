<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CourseOwnerSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        $user = new User(['name' => 'Test User', 'email' => 'owner'.User::count().'@example.test', 'password' => 'Test-only-password-123']);
        $user->role = $role;
        $user->save();

        return $user;
    }

    #[DataProvider('creators')]
    public function test_coach_and_administrator_can_choose_an_assigned_coach(UserRole $role): void
    {
        $actor = $this->user($role);
        $owner = $this->user(UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Programming']);
        $owner->specialties()->attach($specialty);
        if ($role === UserRole::Coach) {
            $actor->specialties()->attach($specialty);
        }

        $details = ['title' => 'Course', 'description' => 'Description', 'specialty_id' => $specialty->id, 'price' => 0, 'coach_id' => $owner->id];
        $this->actingAs($actor)->postJson('/api/v1/courses', $details)
            ->assertCreated()->assertJsonPath('data.coach_id', $owner->id);
        $this->assertDatabaseHas('courses', ['coach_id' => $owner->id, 'specialty_id' => $specialty->id]);
    }

    public static function creators(): array
    {
        return [[UserRole::Coach], [UserRole::Administrator]];
    }

    public function test_administrator_requires_a_responsible_coach_and_cannot_choose_a_student_or_unassigned_coach(): void
    {
        $actor = $this->user(UserRole::Administrator);
        $student = $this->user(UserRole::Student);
        $outsider = $this->user(UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Programming']);
        $student->specialties()->attach($specialty);
        $details = ['title' => 'Course', 'description' => 'Description', 'specialty_id' => $specialty->id, 'price' => 0];
        $this->actingAs($actor)->postJson('/api/v1/courses', $details)->assertUnprocessable()->assertJsonValidationErrors('coach_id');
        foreach ([$actor->id, $student->id, $outsider->id, 999999] as $id) {
            $this->postJson('/api/v1/courses', [...$details, 'coach_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('coach_id');
        }
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_a_coach_can_collaborate_when_another_coach_is_responsible_but_cannot_use_another_group(): void
    {
        $actor = $this->user(UserRole::Coach);
        $owner = $this->user(UserRole::Coach);
        $specialty = Specialty::create(['name' => 'Programming']);
        $owner->specialties()->attach($specialty);
        $details = ['title' => 'Course', 'description' => 'Description', 'specialty_id' => $specialty->id, 'price' => 0, 'coach_id' => $owner->id];
        $this->actingAs($actor)->postJson('/api/v1/courses', $details)->assertUnprocessable()->assertJsonValidationErrors('specialty_id');
        $actor->specialties()->attach($specialty);
        $this->postJson('/api/v1/courses', [...$details, 'additional_coach_ids' => [$owner->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('additional_coach_ids');
        $this->postJson('/api/v1/courses', [...$details, 'additional_coach_ids' => [$actor->id]])
            ->assertCreated()->assertJsonPath('data.additional_coaches.0.id', $actor->id);
    }
}
