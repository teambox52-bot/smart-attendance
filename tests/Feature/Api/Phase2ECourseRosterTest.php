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

class Phase2ECourseRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_get_students_for_own_course(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor, ['code' => 'AI101']);
        $student = $this->student([
            'name' => 'Student One',
            'email' => 'student-one@example.com',
            'university_code' => 'S001',
            'face_token' => 'face-token',
        ]);
        $sessionOne = $this->sessionFor($course, $doctor);
        $this->sessionFor($course, $doctor, ['starts_at' => '2026-06-01T12:00:00Z']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $sessionOne->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $response = $this->getJson("/api/courses/{$course->id}/students");

        $response->assertOk()
            ->assertJsonPath('course.id', $course->id)
            ->assertJsonPath('course.code', 'AI101')
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $student->id)
            ->assertJsonPath('students.0.full_name', 'Student One')
            ->assertJsonPath('students.0.email', 'student-one@example.com')
            ->assertJsonPath('students.0.university_code', 'S001')
            ->assertJsonPath('students.0.university_id', 'S001')
            ->assertJsonPath('students.0.face_enrolled', true)
            ->assertJsonPath('students.0.attendance_rate', 50);
    }

    public function test_doctor_cannot_get_students_for_another_doctors_course(): void
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $otherCourse = $this->courseFor($otherDoctor);

        Sanctum::actingAs($doctor);

        $this->getJson("/api/courses/{$otherCourse->id}/students")
            ->assertNotFound();
    }

    public function test_student_cannot_access_course_roster_endpoint(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($student);

        $this->getJson("/api/courses/{$course->id}/students")
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
