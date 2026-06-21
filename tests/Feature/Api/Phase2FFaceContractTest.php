<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\User;
use App\Services\FacePlusPlusService;
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

    public function test_face_register_continues_when_duplicate_check_hits_provider_concurrency_limit(): void
    {
        $this->enableFacePlusPlusConfig();

        User::factory()->create([
            'role' => 'student',
            'face_token' => 'old-provider-token',
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'face_token' => null,
        ]);

        $this->app->instance(FacePlusPlusService::class, new class extends FacePlusPlusService {
            public function __construct()
            {
            }

            public function detectDetailed($imagePath, ?string $filename = null, ?string $mimeType = null): array
            {
                return [
                    'ok' => true,
                    'endpoint' => '/facepp/v3/detect',
                    'status' => 200,
                    'json' => [
                        'faces' => [
                            ['face_token' => 'new-face-token'],
                        ],
                    ],
                    'error_code' => null,
                    'error_message' => null,
                ];
            }

            public function compareFaceTokenDetailed($imagePath, $faceToken, ?string $filename = null, ?string $mimeType = null): array
            {
                return [
                    'ok' => false,
                    'endpoint' => '/facepp/v3/compare',
                    'status' => 403,
                    'json' => null,
                    'error_code' => 'CONCURRENCY_LIMIT_EXCEEDED',
                    'error_message' => 'CONCURRENCY_LIMIT_EXCEEDED',
                ];
            }
        });

        Sanctum::actingAs($student);

        $response = $this->post('/api/face/register', [
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Face registered successfully',
            ]);

        $this->assertSame('new-face-token', $student->fresh()->face_token);
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

    private function enableFacePlusPlusConfig(): void
    {
        putenv('FACEPP_API_KEY=test-key');
        putenv('FACEPP_API_SECRET=test-secret');
        putenv('FACEPP_BASE_URL=https://example.test');
        $_ENV['FACEPP_API_KEY'] = 'test-key';
        $_ENV['FACEPP_API_SECRET'] = 'test-secret';
        $_ENV['FACEPP_BASE_URL'] = 'https://example.test';
        $_SERVER['FACEPP_API_KEY'] = 'test-key';
        $_SERVER['FACEPP_API_SECRET'] = 'test-secret';
        $_SERVER['FACEPP_BASE_URL'] = 'https://example.test';
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
