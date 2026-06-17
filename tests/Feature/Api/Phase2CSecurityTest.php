<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase2CSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_face_verify_returns_controlled_response_when_service_is_not_configured(): void
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

    public function test_attendance_rejects_manual_method_cleanly(): void
    {
        $student = $this->student();
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/attendance', [
            'session_id' => $session->id,
            'method' => 'manual',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['method']);

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_student_cannot_mark_attendance_for_another_user(): void
    {
        $student = $this->student();
        $otherStudent = $this->student(['email' => 'other@example.com', 'university_code' => 'S999']);
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        Enrollment::create(['user_id' => $otherStudent->id, 'course_id' => $course->id]);

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/attendance', [
            'user_id' => $otherStudent->id,
            'session_id' => $session->id,
            'method' => 'qr',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_student_can_mark_own_attendance_with_valid_method(): void
    {
        $student = $this->student();
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/attendance', [
            'session_id' => $session->id,
            'method' => 'qr',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Attendance marked successfully',
            ]);

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);
    }

    public function test_student_cannot_read_another_students_report(): void
    {
        $student = $this->student();
        $otherStudent = $this->student(['email' => 'other@example.com', 'university_code' => 'S999']);

        Sanctum::actingAs($student);

        $this->getJson("/api/students/{$otherStudent->id}/report")
            ->assertForbidden();
    }

    public function test_doctor_cannot_close_another_doctors_session(): void
    {
        [$doctor, $otherDoctor, $session] = $this->otherDoctorSession();

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertNotFound();

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'status' => 'open',
        ]);
    }

    public function test_doctor_cannot_read_another_doctors_session_report(): void
    {
        [$doctor, $otherDoctor, $session] = $this->otherDoctorSession();

        Sanctum::actingAs($doctor);

        $this->getJson("/api/sessions/{$session->id}/report")
            ->assertNotFound();
    }

    public function test_doctor_cannot_read_or_export_another_doctors_course_or_session(): void
    {
        [$doctor, $otherDoctor, $session, $course] = $this->otherDoctorSessionWithCourse();

        Sanctum::actingAs($doctor);

        $this->getJson("/api/courses/{$course->id}/report")
            ->assertNotFound();

        $this->getJson("/api/courses/{$course->id}/export")
            ->assertNotFound();

        $this->getJson("/api/sessions/{$session->id}/export")
            ->assertNotFound();
    }

    public function test_doctor_cannot_mark_qr_attendance_for_another_doctors_session(): void
    {
        [$doctor, $otherDoctor, $session, $course] = $this->otherDoctorSessionWithCourse();
        $student = $this->student();
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/qr/scan', [
            'session_id' => $session->id,
            'qr_data' => json_encode(['id' => $student->id]),
        ]);

        $response->assertNotFound();
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_doctor_cannot_mark_face_attendance_for_another_doctors_session(): void
    {
        [$doctor, $otherDoctor, $session] = $this->otherDoctorSession();

        Sanctum::actingAs($doctor);

        $response = $this->post('/api/face/verify-and-mark', [
            'session_id' => $session->id,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertNotFound();
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_existing_doctor_session_close_happy_path_still_works(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertOk()
            ->assertJson([
                'message' => 'Session closed successfully',
            ]);

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'status' => 'closed',
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
            'status' => 'open',
        ], $attributes));
    }

    private function otherDoctorSession(): array
    {
        [$doctor, $otherDoctor, $session] = $this->otherDoctorSessionWithCourse();

        return [$doctor, $otherDoctor, $session];
    }

    private function otherDoctorSessionWithCourse(): array
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $course = $this->courseFor($otherDoctor);
        $session = $this->sessionFor($course, $otherDoctor);

        return [$doctor, $otherDoctor, $session, $course];
    }
}
