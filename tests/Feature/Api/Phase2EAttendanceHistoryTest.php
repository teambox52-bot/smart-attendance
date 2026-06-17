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

class Phase2EAttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_get_only_their_own_attendance_history(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['email' => 'student-one@example.com']);
        $otherStudent = $this->student(['email' => 'student-two@example.com']);
        $course = $this->courseFor($doctor, ['code' => 'CS401', 'name' => 'AI']);
        $session = $this->sessionFor($course, $doctor, [
            'method' => 'qr',
            'starts_at' => '2026-05-31T10:00:00Z',
        ]);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        Enrollment::create(['user_id' => $otherStudent->id, 'course_id' => $course->id]);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'user_id' => $otherStudent->id,
            'attendance_session_id' => $session->id,
            'method' => 'face',
            'status' => 'present',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/my-attendance');

        $response->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.course_code', 'CS401')
            ->assertJsonPath('records.0.course_name', 'AI')
            ->assertJsonPath('records.0.session_id', $session->id)
            ->assertJsonPath('records.0.date', '2026-05-31')
            ->assertJsonPath('records.0.time', '10:00')
            ->assertJsonPath('records.0.method', 'qr')
            ->assertJsonPath('records.0.status', 'present')
            ->assertJsonPath('records.0.attendance_rate', 100);
    }

    public function test_doctor_cannot_access_my_attendance(): void
    {
        Sanctum::actingAs($this->doctor());

        $this->getJson('/api/my-attendance')
            ->assertForbidden();
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
