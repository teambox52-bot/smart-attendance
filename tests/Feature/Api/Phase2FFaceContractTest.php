<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase2FFaceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_face_verify_returns_clean_response_when_facepp_config_is_missing(): void
    {
        $this->disableFacePlusPlusConfig();
        Sanctum::actingAs($this->doctor());

        $response = $this->post('/api/face/verify', [
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'Face verification service is not configured',
            ]);
    }

    public function test_face_verify_and_mark_returns_clean_response_when_facepp_config_is_missing(): void
    {
        $this->disableFacePlusPlusConfig();
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);

        Sanctum::actingAs($doctor);

        $response = $this->post('/api/face/verify-and-mark', [
            'session_id' => $session->id,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'Face verification service is not configured',
            ]);
    }

    private function disableFacePlusPlusConfig(): void
    {
        putenv('FACEPP_API_KEY=');
        putenv('FACEPP_API_SECRET=');
        putenv('FACEPP_BASE_URL=');
        $_ENV['FACEPP_API_KEY'] = '';
        $_ENV['FACEPP_API_SECRET'] = '';
        $_ENV['FACEPP_BASE_URL'] = '';
        $_SERVER['FACEPP_API_KEY'] = '';
        $_SERVER['FACEPP_API_SECRET'] = '';
        $_SERVER['FACEPP_BASE_URL'] = '';
    }

    private function doctor(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'doctor',
        ], $attributes));
    }

    private function courseFor(User $doctor, array $attributes = []): Course
    {
        return Course::create(array_merge([
            'name' => fake()->words(3, true),
            'code' => fake()->unique()->bothify('CS###'),
            'doctor_id' => $doctor->id,
            'semester' => 'Second Term',
            'level' => '4',
        ], $attributes));
    }

    private function sessionFor(Course $course, User $doctor, array $attributes = []): AttendanceSession
    {
        return AttendanceSession::create(array_merge([
            'course_id' => $course->id,
            'created_by' => $doctor->id,
            'method' => 'face',
            'starts_at' => '2026-05-31T10:00:00Z',
            'ends_at' => '2026-05-31T11:30:00Z',
            'status' => 'open',
        ], $attributes));
    }
}
