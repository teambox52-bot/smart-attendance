<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase2GProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_student_can_update_own_profile(): void
    {
        $student = $this->student([
            'name' => 'Old Student',
            'email' => 'old-student@example.com',
            'university_code' => '202101234',
            'major' => 'CS',
            'level' => 3,
            'face_token' => 'face-token',
        ]);

        Sanctum::actingAs($student);

        $response = $this->patchJson('/api/me/profile', [
            'name' => 'Updated Student',
            'email' => 'updated-student@example.com',
            'major' => 'AI',
            'level' => 4,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully')
            ->assertJsonPath('user.id', $student->id)
            ->assertJsonPath('user.name', 'Updated Student')
            ->assertJsonPath('user.email', 'updated-student@example.com')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.university_code', '202101234')
            ->assertJsonPath('user.major', 'AI')
            ->assertJsonPath('user.level', 4)
            ->assertJsonPath('user.face_enrolled', true);

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Updated Student',
            'email' => 'updated-student@example.com',
            'role' => 'student',
            'major' => 'AI',
            'level' => 4,
        ]);
    }

    public function test_authenticated_doctor_can_update_own_profile(): void
    {
        $doctor = $this->doctor([
            'name' => 'Old Doctor',
            'email' => 'old-doctor@example.com',
        ]);

        Sanctum::actingAs($doctor);

        $response = $this->patchJson('/api/me/profile', [
            'name' => 'Updated Doctor',
            'email' => 'updated-doctor@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $doctor->id)
            ->assertJsonPath('user.name', 'Updated Doctor')
            ->assertJsonPath('user.email', 'updated-doctor@example.com')
            ->assertJsonPath('user.role', 'doctor')
            ->assertJsonPath('user.major', null)
            ->assertJsonPath('user.level', null);
    }

    public function test_user_cannot_change_role_through_profile_update(): void
    {
        $student = $this->student(['role' => 'student']);

        Sanctum::actingAs($student);

        $this->patchJson('/api/me/profile', [
            'name' => 'Still Student',
            'role' => 'doctor',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'role' => 'student',
        ]);
    }

    public function test_email_uniqueness_is_enforced_for_profile_update(): void
    {
        $student = $this->student(['email' => 'student@example.com']);
        $this->doctor(['email' => 'taken@example.com']);

        Sanctum::actingAs($student);

        $this->patchJson('/api/me/profile', [
            'email' => 'taken@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'email' => 'student@example.com',
        ]);
    }

    private function doctor(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'doctor',
        ], $attributes));
    }

    private function student(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'student',
            'university_code' => fake()->unique()->numerify('S#####'),
            'major' => 'CS',
            'level' => 4,
        ], $attributes));
    }
}
