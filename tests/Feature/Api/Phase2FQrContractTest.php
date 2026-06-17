<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase2FQrContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_qr_returns_qr_payload_and_qr_data(): void
    {
        $student = $this->student([
            'name' => 'Student Name',
            'email' => 'student@test.com',
            'university_code' => '202101234',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/my-qr');

        $response->assertOk()
            ->assertJsonPath('message', 'QR generated successfully')
            ->assertJsonStructure(['qr_payload', 'qr_data', 'qr_url']);

        $this->assertSame(
            $response->json('qr_payload'),
            $response->json('qr_data')
        );

        $this->assertSame([
            'id' => $student->id,
            'name' => 'Student Name',
            'email' => 'student@test.com',
            'university_code' => '202101234',
        ], json_decode($response->json('qr_payload'), true));
    }

    public function test_qr_scan_accepts_qr_payload(): void
    {
        [$doctor, $student, $session] = $this->attendanceSetup();

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/qr/scan', [
            'session_id' => $session->id,
            'qr_payload' => $this->qrPayloadFor($student),
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Attendance marked successfully by QR')
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.full_name', $student->name)
            ->assertJsonPath('student.status', 'present')
            ->assertJsonPath('student.method', 'qr')
            ->assertJsonPath('attendance.session_id', $session->id)
            ->assertJsonPath('attendance.method', 'qr')
            ->assertJsonPath('attendance.status', 'present');

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);
    }

    public function test_qr_scan_accepts_qr_data_for_compatibility(): void
    {
        [$doctor, $student, $session] = $this->attendanceSetup();

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/qr/scan', [
            'session_id' => $session->id,
            'qr_data' => $this->qrPayloadFor($student),
        ]);

        $response->assertCreated()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('attendance.method', 'qr');
    }

    public function test_doctor_cannot_scan_qr_for_another_doctors_session(): void
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $otherCourse = $this->courseFor($otherDoctor);
        $otherSession = $this->sessionFor($otherCourse, $otherDoctor);
        $student = $this->student();
        Enrollment::create(['user_id' => $student->id, 'course_id' => $otherCourse->id]);

        Sanctum::actingAs($doctor);

        $this->postJson('/api/qr/scan', [
            'session_id' => $otherSession->id,
            'qr_payload' => $this->qrPayloadFor($student),
        ])->assertNotFound();

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_duplicate_qr_attendance_returns_clean_conflict_response(): void
    {
        [$doctor, $student, $session] = $this->attendanceSetup();
        $attendance = AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
            'attended_at' => now(),
        ]);

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/qr/scan', [
            'session_id' => $session->id,
            'qr_payload' => $this->qrPayloadFor($student),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Student already marked present')
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.status', 'present')
            ->assertJsonPath('attendance.id', $attendance->id)
            ->assertJsonPath('attendance.method', 'qr');

        $this->assertSame(1, AttendanceRecord::count());
    }

    private function attendanceSetup(): array
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        return [$doctor, $student, $session];
    }

    private function qrPayloadFor(User $student): string
    {
        return json_encode([
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'university_code' => $student->university_code,
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
            'method' => 'qr',
            'starts_at' => '2026-05-31T10:00:00Z',
            'ends_at' => '2026-05-31T11:30:00Z',
            'status' => 'open',
        ], $attributes));
    }
}
