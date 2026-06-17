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

class Phase2ICourseScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_sees_only_their_own_courses_from_courses_endpoint(): void
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $ownCourse = $this->courseFor($doctor, ['code' => 'OWN101', 'name' => 'Own Course']);
        $otherCourse = $this->courseFor($otherDoctor, ['code' => 'OTH101', 'name' => 'Other Course']);

        Sanctum::actingAs($doctor);

        $response = $this->getJson('/api/courses');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownCourse->id)
            ->assertJsonPath('0.code', 'OWN101');

        $this->assertStringNotContainsString((string) $otherCourse->id, $response->getContent());
        $this->assertStringNotContainsString('OTH101', $response->getContent());
    }

    public function test_courses_endpoint_returns_simple_counts_for_doctor_courses(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor, ['code' => 'CS401']);
        $student = $this->student();
        $session = $this->sessionFor($course, $doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->getJson('/api/courses')
            ->assertOk()
            ->assertJsonPath('0.id', $course->id)
            ->assertJsonPath('0.name', $course->name)
            ->assertJsonPath('0.code', 'CS401')
            ->assertJsonPath('0.enrolled_count', 1)
            ->assertJsonPath('0.enrollments_count', 1)
            ->assertJsonPath('0.sessions_count', 1)
            ->assertJsonPath('0.attendance_rate', 100);
    }

    public function test_student_cannot_access_courses_endpoint(): void
    {
        Sanctum::actingAs($this->student());

        $this->getJson('/api/courses')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only doctors can view managed courses');
    }

    public function test_doctor_can_still_create_course(): void
    {
        $doctor = $this->doctor();

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/courses', [
            'name' => 'New Course',
            'code' => 'NEW101',
            'semester' => 'Second Term',
            'level' => '4',
            'departments' => ['CS'],
            'levels' => ['Fourth Year'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Course created successfully')
            ->assertJsonPath('course.name', 'New Course')
            ->assertJsonPath('course.code', 'NEW101');

        $this->assertDatabaseHas('courses', [
            'name' => 'New Course',
            'code' => 'NEW101',
            'doctor_id' => $doctor->id,
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
