<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase2GPasswordWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_password_succeeds_with_correct_current_password(): void
    {
        $user = $this->student([
            'email' => 'student@example.com',
            'password' => Hash::make('old-password'),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/me/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('message', 'Password changed successfully');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $user = $this->student([
            'password' => Hash::make('old-password'),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/me/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_after_password_change_login_with_new_password_works_and_old_password_fails(): void
    {
        $user = $this->student([
            'email' => 'student@example.com',
            'password' => Hash::make('old-password'),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/me/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'student@example.com',
            'password' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('message', 'Login successful');

        $this->postJson('/api/login', [
            'email' => 'student@example.com',
            'password' => 'old-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
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
