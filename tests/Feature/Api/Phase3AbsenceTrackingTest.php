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

class Phase3AbsenceTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_session_creates_absent_record_for_missing_student(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['method' => 'qr']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertOk()
            ->assertJsonPath('session.status', 'closed')
            ->assertJsonPath('session.present_count', 0)
            ->assertJsonPath('session.total_count', 1);

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'status' => 'absent',
            'method' => 'qr',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/my-attendance')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.status', 'absent')
            ->assertJsonPath('records.0.session_id', $session->id)
            ->assertJsonPath('records.0.attendance_rate', 0);
    }

    public function test_closing_session_does_not_overwrite_present_record(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['method' => 'both']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'face',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertOk()
            ->assertJsonPath('session.present_count', 1);

        $this->assertSame(1, AttendanceRecord::where('user_id', $student->id)
            ->where('attendance_session_id', $session->id)
            ->count());

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'face',
            'status' => 'present',
        ]);
    }

    public function test_closing_session_twice_does_not_duplicate_absences(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")->assertOk();
        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Open the session before closing it.');

        $this->assertSame(1, AttendanceRecord::where('user_id', $student->id)
            ->where('attendance_session_id', $session->id)
            ->where('status', 'absent')
            ->count());
    }

    public function test_mixed_class_close_creates_absences_without_touching_present_students(): void
    {
        $doctor = $this->doctor();
        $studentOne = $this->student(['email' => 'one@example.com', 'university_code' => 'S001']);
        $studentTwo = $this->student(['email' => 'two@example.com', 'university_code' => 'S002']);
        $studentThree = $this->student(['email' => 'three@example.com', 'university_code' => 'S003']);
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['method' => 'both']);

        foreach ([$studentOne, $studentTwo, $studentThree] as $student) {
            Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        }

        AttendanceRecord::create([
            'user_id' => $studentOne->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertOk()
            ->assertJsonPath('session.present_count', 1)
            ->assertJsonPath('session.total_count', 3);

        $this->assertSame(1, AttendanceRecord::where('attendance_session_id', $session->id)
            ->where('status', 'present')
            ->count());
        $this->assertSame(2, AttendanceRecord::where('attendance_session_id', $session->id)
            ->where('status', 'absent')
            ->count());

        $this->getJson("/api/sessions/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('session.present_count', 1);
    }

    public function test_attendance_history_supports_two_present_three_absent_and_forty_percent_rate(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        for ($i = 1; $i <= 5; $i++) {
            $session = $this->sessionFor($course, $doctor, [
                'method' => 'qr',
                'starts_at' => "2026-05-0{$i}T10:00:00Z",
                'status' => 'closed',
            ]);

            AttendanceRecord::create([
                'user_id' => $student->id,
                'attendance_session_id' => $session->id,
                'method' => 'qr',
                'status' => $i <= 2 ? 'present' : 'absent',
            ]);
        }

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/my-attendance');

        $response->assertOk()
            ->assertJsonCount(5, 'records')
            ->assertJsonPath('records.0.attendance_rate', 40);

        $records = collect($response->json('records'));
        $this->assertSame(2, $records->where('status', 'present')->count());
        $this->assertSame(3, $records->where('status', 'absent')->count());
        $this->assertTrue($records->every(fn (array $record) => (float) $record['attendance_rate'] === 40.0));
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
