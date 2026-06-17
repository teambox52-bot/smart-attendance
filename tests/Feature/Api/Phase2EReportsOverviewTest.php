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

class Phase2EReportsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_get_reports_overview_for_own_courses_only(): void
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $course = $this->courseFor($doctor, ['code' => 'OWN101', 'name' => 'Own Course']);
        $otherCourse = $this->courseFor($otherDoctor, ['code' => 'OTH101', 'name' => 'Other Course']);
        $session = $this->sessionFor($course, $doctor);
        $this->sessionFor($otherCourse, $otherDoctor);
        $student = $this->student(['email' => 'student-one@example.com']);
        $otherStudent = $this->student(['email' => 'student-two@example.com']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        Enrollment::create(['user_id' => $otherStudent->id, 'course_id' => $otherCourse->id]);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $response = $this->getJson('/api/reports/overview');

        $response->assertOk()
            ->assertJsonPath('totals.courses_count', 1)
            ->assertJsonPath('totals.sessions_count', 1)
            ->assertJsonPath('totals.students_count', 1)
            ->assertJsonPath('totals.average_attendance_rate', 100)
            ->assertJsonPath('courses.0.code', 'OWN101')
            ->assertJsonPath('sessions.0.course_code', 'OWN101')
            ->assertJsonCount(0, 'trend');

        $this->assertStringNotContainsString('OTH101', $response->getContent());
    }

    public function test_student_cannot_access_reports_overview(): void
    {
        Sanctum::actingAs($this->student());

        $this->getJson('/api/reports/overview')
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
