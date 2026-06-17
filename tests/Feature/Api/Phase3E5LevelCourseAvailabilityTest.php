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

class Phase3E5LevelCourseAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_level_three_sees_only_available_level_three_courses(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['level' => 3]);
        $levelThree = $this->courseFor($doctor, ['code' => 'L3A', 'level' => '3']);
        $levelFour = $this->courseFor($doctor, ['code' => 'L4A', 'level' => '4']);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/available-courses');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $levelThree->id)
            ->assertJsonPath('0.code', 'L3A');

        $this->assertStringNotContainsString('L4A', $response->getContent());
    }

    public function test_student_level_four_sees_only_available_level_four_courses(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['level' => 4]);
        $levelThree = $this->courseFor($doctor, ['code' => 'L3B', 'level' => '3']);
        $levelFour = $this->courseFor($doctor, ['code' => 'L4B', 'level' => '4']);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/available-courses');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $levelFour->id)
            ->assertJsonPath('0.code', 'L4B');

        $this->assertStringNotContainsString('L3B', $response->getContent());
    }

    public function test_student_does_not_see_already_enrolled_courses_in_available_courses(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['level' => 3]);
        $enrolled = $this->courseFor($doctor, ['code' => 'ENR3', 'level' => '3']);
        $available = $this->courseFor($doctor, ['code' => 'AVL3', 'level' => '3']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $enrolled->id]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/available-courses');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $available->id)
            ->assertJsonPath('0.code', 'AVL3');

        $this->assertStringNotContainsString('ENR3', $response->getContent());
    }

    public function test_doctor_created_level_three_course_appears_only_for_level_three_students(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor, ['code' => 'DOC3', 'level' => '3']);
        $levelThreeStudent = $this->student(['level' => 3, 'email' => 'level-three@example.com']);
        $levelFourStudent = $this->student(['level' => 4, 'email' => 'level-four@example.com']);

        Sanctum::actingAs($levelThreeStudent);

        $this->getJson('/api/available-courses')
            ->assertOk()
            ->assertJsonPath('0.id', $course->id)
            ->assertJsonPath('0.code', 'DOC3');

        Sanctum::actingAs($levelFourStudent);

        $response = $this->getJson('/api/available-courses');

        $response->assertOk()
            ->assertJsonCount(0);
        $this->assertStringNotContainsString('DOC3', $response->getContent());
    }

    public function test_level_label_course_is_visible_to_matching_numeric_student_level(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['level' => 3]);
        $course = $this->courseFor($doctor, [
            'code' => 'LBL3',
            'level' => 'Third Year',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/available-courses')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $course->id)
            ->assertJsonPath('0.code', 'LBL3');
    }

    public function test_doctor_course_creation_normalizes_level_label(): void
    {
        $doctor = $this->doctor();

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/courses', [
            'name' => 'Doctor Level Label Course',
            'code' => 'DLC3',
            'departments' => ['CS'],
            'levels' => ['Third Year'],
            'semester' => 'CS',
            'level' => 'Third Year',
        ]);

        $response->assertCreated()
            ->assertJsonPath('course.semester', 'CS')
            ->assertJsonPath('course.level', '3');

        $this->assertDatabaseHas('courses', [
            'code' => 'DLC3',
            'semester' => 'CS',
            'level' => '3',
        ]);
    }

    public function test_cs_third_year_student_does_not_see_is_third_year_course(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['major' => 'CS', 'level' => 3]);
        $csCourse = $this->courseFor($doctor, ['code' => 'CS3', 'semester' => 'CS', 'level' => '3']);
        $isCourse = $this->courseFor($doctor, ['code' => 'IS3', 'semester' => 'IS', 'level' => '3']);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/available-courses');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $csCourse->id)
            ->assertJsonPath('0.code', 'CS3');

        $this->assertStringNotContainsString('IS3', $response->getContent());
        $this->assertDatabaseHas('courses', ['id' => $isCourse->id]);
    }

    public function test_is_third_year_student_sees_is_third_year_course(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['major' => 'IS', 'level' => 3]);
        $course = $this->courseFor($doctor, ['code' => 'IS33', 'semester' => 'IS', 'level' => '3']);

        Sanctum::actingAs($student);

        $this->getJson('/api/available-courses')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $course->id)
            ->assertJsonPath('0.code', 'IS33');
    }

    public function test_course_without_supported_department_does_not_appear_as_available(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['major' => 'CS', 'level' => 4]);
        $this->courseFor($doctor, ['code' => 'OLD4', 'semester' => 'Spring 2026', 'level' => '4']);

        Sanctum::actingAs($student);

        $this->getJson('/api/available-courses')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_multi_select_department_and_level_course_creation_expands_to_matching_courses(): void
    {
        $doctor = $this->doctor();

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/courses', [
            'name' => 'Shared Course',
            'code' => 'SHARED',
            'departments' => ['CS', 'IS'],
            'levels' => ['First Year', 'Second Year', 'Third Year'],
        ]);

        $response->assertCreated()
            ->assertJsonCount(6, 'courses');

        foreach (['SHARED-CS1', 'SHARED-CS2', 'SHARED-CS3', 'SHARED-IS1', 'SHARED-IS2', 'SHARED-IS3'] as $code) {
            $this->assertDatabaseHas('courses', ['code' => $code]);
        }

        $this->assertDatabaseMissing('courses', ['code' => 'SHARED-CS4']);
        $this->assertDatabaseMissing('courses', ['code' => 'SHARED-IS4']);

        $this->assertStudentSeesCourse('CS', 1, 'SHARED-CS1');
        $this->assertStudentSeesCourse('CS', 2, 'SHARED-CS2');
        $this->assertStudentSeesCourse('CS', 3, 'SHARED-CS3');
        $this->assertStudentSeesCourse('IS', 1, 'SHARED-IS1');
        $this->assertStudentSeesCourse('IS', 2, 'SHARED-IS2');
        $this->assertStudentSeesCourse('IS', 3, 'SHARED-IS3');

        $this->assertStudentDoesNotSeeCourse('CS', 4, 'SHARED-CS3');
        $this->assertStudentDoesNotSeeCourse('IS', 4, 'SHARED-IS3');
    }

    public function test_student_cannot_enroll_in_course_for_another_level(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['level' => 4]);
        $levelThreeCourse = $this->courseFor($doctor, ['level' => '3']);

        Sanctum::actingAs($student);

        $this->postJson('/api/enroll', [
            'course_id' => $levelThreeCourse->id,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Course is not available for your current major and level');

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $student->id,
            'course_id' => $levelThreeCourse->id,
        ]);
    }

    public function test_level_change_removes_old_level_enrollments_but_preserves_attendance_history(): void
    {
        $doctor = $this->doctor();
        $student = $this->student(['level' => 3]);
        $oldCourse = $this->courseFor($doctor, ['code' => 'OLD3', 'name' => 'Old Level Course', 'level' => '3']);
        $newCourse = $this->courseFor($doctor, ['code' => 'NEW4', 'name' => 'New Level Course', 'level' => '4']);
        $oldSession = $this->sessionFor($oldCourse, $doctor, [
            'starts_at' => '2026-05-31T10:00:00Z',
        ]);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $oldCourse->id]);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $oldSession->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($student);

        $this->patchJson('/api/me/profile', [
            'level' => 4,
        ])->assertOk()
            ->assertJsonPath('user.level', 4);

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $student->id,
            'course_id' => $oldCourse->id,
        ]);

        $this->getJson('/api/my-courses')
            ->assertOk()
            ->assertJsonCount(0);

        $this->getJson('/api/available-courses')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $newCourse->id)
            ->assertJsonPath('0.code', 'NEW4');

        $this->getJson('/api/my-attendance')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.course_code', 'OLD3')
            ->assertJsonPath('records.0.course_name', 'Old Level Course')
            ->assertJsonPath('records.0.status', 'present');
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
            'semester' => 'CS',
            'level' => '4',
        ], $attributes));
    }

    private function assertStudentSeesCourse(string $major, int $level, string $code): void
    {
        $student = $this->student([
            'major' => $major,
            'level' => $level,
            'email' => fake()->unique()->safeEmail(),
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/available-courses')
            ->assertOk()
            ->assertJsonFragment(['code' => $code]);
    }

    private function assertStudentDoesNotSeeCourse(string $major, int $level, string $code): void
    {
        $student = $this->student([
            'major' => $major,
            'level' => $level,
            'email' => fake()->unique()->safeEmail(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/available-courses');

        $response->assertOk();
        $this->assertStringNotContainsString($code, $response->getContent());
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
