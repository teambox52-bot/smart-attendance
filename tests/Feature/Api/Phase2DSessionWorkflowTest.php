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

class Phase2DSessionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_create_session_with_method_and_time(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/sessions', [
            'course_id' => $course->id,
            'method' => 'both',
            'starts_at' => $this->futureStart(),
            'ends_at' => $this->futureEnd(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('session.course_id', $course->id)
            ->assertJsonPath('session.course_name', $course->name)
            ->assertJsonPath('session.course_code', $course->code)
            ->assertJsonPath('session.method', 'both')
            ->assertJsonPath('session.status', 'scheduled')
            ->assertJsonPath('session.is_open', false)
            ->assertJsonPath('session.present_count', 0)
            ->assertJsonPath('session.total_count', 0);

        $this->assertDatabaseHas('attendance_sessions', [
            'course_id' => $course->id,
            'created_by' => $doctor->id,
            'method' => 'both',
            'status' => 'scheduled',
        ]);
    }

    public function test_create_session_requires_valid_date_time(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($doctor);

        $this->postJson('/api/sessions', [
            'course_id' => $course->id,
            'method' => 'qr',
            'starts_at' => 'not-a-date',
            'ends_at' => $this->futureEnd(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please enter a valid session date and time.');

        $this->assertSame(0, AttendanceSession::count());
    }

    public function test_create_session_requires_end_time_after_start_time(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($doctor);

        $this->postJson('/api/sessions', [
            'course_id' => $course->id,
            'method' => 'qr',
            'starts_at' => $this->futureEnd(),
            'ends_at' => $this->futureStart(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please enter a valid session date and time.');

        $this->assertSame(0, AttendanceSession::count());
    }

    public function test_create_session_rejects_past_start_time(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($doctor);

        $this->postJson('/api/sessions', [
            'course_id' => $course->id,
            'method' => 'qr',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->subDay()->addHour()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please choose a valid future session date and time.');

        $this->assertSame(0, AttendanceSession::count());
    }

    public function test_opening_scheduled_session_makes_it_live(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/open")
            ->assertOk()
            ->assertJsonPath('session.status', 'open')
            ->assertJsonPath('session.is_open', true);

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'status' => 'open',
        ]);
    }

    public function test_session_list_returns_scheduled_session_as_not_open(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);

        Sanctum::actingAs($doctor);

        $this->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.status', 'scheduled')
            ->assertJsonPath('data.0.is_open', false);
    }

    public function test_doctor_can_list_only_their_own_sessions(): void
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $course = $this->courseFor($doctor, ['code' => 'OWN101']);
        $otherCourse = $this->courseFor($otherDoctor, ['code' => 'OTH101']);
        $session = $this->sessionFor($course, $doctor, ['method' => 'qr']);
        $this->sessionFor($otherCourse, $otherDoctor, ['method' => 'face']);

        Sanctum::actingAs($doctor);

        $response = $this->getJson('/api/sessions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.course_code', 'OWN101')
            ->assertJsonPath('data.0.method', 'qr');
    }

    public function test_session_list_includes_simple_counts(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);
        $studentOne = $this->student(['email' => 'one@example.com', 'university_code' => 'S001']);
        $studentTwo = $this->student(['email' => 'two@example.com', 'university_code' => 'S002']);
        Enrollment::create(['user_id' => $studentOne->id, 'course_id' => $course->id]);
        Enrollment::create(['user_id' => $studentTwo->id, 'course_id' => $course->id]);
        AttendanceRecord::create([
            'user_id' => $studentOne->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.present_count', 1)
            ->assertJsonPath('data.0.total_count', 2);
    }

    public function test_doctor_can_update_own_scheduled_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);

        Sanctum::actingAs($doctor);

        $response = $this->patchJson("/api/sessions/{$session->id}", [
            'method' => 'qr',
            'starts_at' => now()->addDays(2)->setTime(12, 0)->toIso8601String(),
            'ends_at' => now()->addDays(2)->setTime(13, 0)->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('session.method', 'qr')
            ->assertJsonPath('session.course_name', $course->name);

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'method' => 'qr',
        ]);
    }

    public function test_doctor_cannot_update_scheduled_session_to_past_start_time(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);

        Sanctum::actingAs($doctor);

        $this->patchJson("/api/sessions/{$session->id}", [
            'method' => 'qr',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->subDay()->addHour()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please choose a valid future session date and time.');
    }

    public function test_doctor_cannot_update_open_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'open']);

        Sanctum::actingAs($doctor);

        $this->patchJson("/api/sessions/{$session->id}", [
            'method' => 'qr',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only scheduled sessions can be updated.');
    }

    public function test_doctor_cannot_update_closed_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'closed']);

        Sanctum::actingAs($doctor);

        $this->patchJson("/api/sessions/{$session->id}", [
            'method' => 'qr',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only scheduled sessions can be updated.');
    }

    public function test_doctor_can_open_their_own_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'closed']);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/open")
            ->assertOk()
            ->assertJsonPath('session.status', 'open')
            ->assertJsonPath('session.is_open', true);

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'status' => 'open',
        ]);
    }

    public function test_doctor_can_close_their_own_session_with_normalized_response(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertOk()
            ->assertJsonPath('session.status', 'closed')
            ->assertJsonPath('session.is_open', false)
            ->assertJsonPath('session.course_code', $course->code);
    }

    public function test_closing_scheduled_session_is_blocked_without_absences(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$session->id}/close")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Open the session before closing it.');

        $this->assertSame(0, AttendanceRecord::count());
        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_session_time_persists_through_create_response(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/sessions', [
            'course_id' => $course->id,
            'method' => 'qr',
            'starts_at' => '2030-06-03T09:18:00Z',
            'ends_at' => '2030-06-03T22:22:00Z',
        ]);

        $response->assertCreated()
            ->assertJsonPath('session.status', 'scheduled')
            ->assertJsonPath('session.is_open', false);

        $this->assertSame('2030-06-03T09:18:00', $response->json('session.starts_at'));
        $this->assertSame('2030-06-03T22:22:00', $response->json('session.ends_at'));
        $this->assertSame('2030-06-03', $response->json('session.display_date'));
        $this->assertSame('09:18', $response->json('session.display_start_time'));
        $this->assertSame('22:22', $response->json('session.display_end_time'));
    }

    public function test_session_time_round_trip_uses_local_wall_clock_response(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);

        Sanctum::actingAs($doctor);

        $response = $this->postJson('/api/sessions', [
            'course_id' => $course->id,
            'method' => 'qr',
            'starts_at' => '2030-06-06T10:00:00+03:00',
            'ends_at' => '2030-06-06T11:30:00+03:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('session.starts_at', '2030-06-06T10:00:00')
            ->assertJsonPath('session.ends_at', '2030-06-06T11:30:00')
            ->assertJsonPath('session.display_date', '2030-06-06')
            ->assertJsonPath('session.display_start_time', '10:00')
            ->assertJsonPath('session.display_end_time', '11:30')
            ->assertJsonMissingExact([
                'starts_at' => '2030-06-06T10:00:00.000000Z',
            ]);
    }

    public function test_doctor_cannot_update_open_or_close_another_doctors_session(): void
    {
        [$doctor, $otherSession] = $this->otherDoctorSession();

        Sanctum::actingAs($doctor);

        $this->patchJson("/api/sessions/{$otherSession->id}", [
            'method' => 'both',
        ])->assertNotFound();

        $this->postJson("/api/sessions/{$otherSession->id}/open")
            ->assertNotFound();

        $this->postJson("/api/sessions/{$otherSession->id}/close")
            ->assertNotFound();
    }

    public function test_doctor_can_delete_own_scheduled_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);

        Sanctum::actingAs($doctor);

        $this->deleteJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Session deleted successfully');

        $this->assertDatabaseMissing('attendance_sessions', [
            'id' => $session->id,
        ]);
    }

    public function test_doctor_cannot_delete_open_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'open']);

        Sanctum::actingAs($doctor);

        $this->deleteJson("/api/sessions/{$session->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only scheduled sessions can be deleted.');
    }

    public function test_doctor_cannot_delete_closed_session(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'closed']);

        Sanctum::actingAs($doctor);

        $this->deleteJson("/api/sessions/{$session->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only scheduled sessions can be deleted.');
    }

    public function test_doctor_cannot_delete_another_doctors_session(): void
    {
        [$doctor, $otherSession] = $this->otherDoctorSession(['status' => 'scheduled']);

        Sanctum::actingAs($doctor);

        $this->deleteJson("/api/sessions/{$otherSession->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to access this session.');
    }

    public function test_doctor_cannot_delete_session_with_attendance_records(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->deleteJson("/api/sessions/{$session->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sessions with attendance records cannot be deleted.');

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'attendance_session_id' => $session->id,
        ]);
    }

    public function test_deleting_one_session_does_not_delete_sibling_sessions(): void
    {
        $doctor = $this->doctor();
        $course = $this->courseFor($doctor);
        $sessionToDelete = $this->sessionFor($course, $doctor, ['status' => 'scheduled']);
        $siblingSession = $this->sessionFor($course, $doctor, [
            'status' => 'scheduled',
            'starts_at' => '2026-06-01T10:00:00Z',
            'ends_at' => '2026-06-01T11:30:00Z',
        ]);

        Sanctum::actingAs($doctor);

        $this->deleteJson("/api/sessions/{$sessionToDelete->id}")
            ->assertOk();

        $this->assertDatabaseMissing('attendance_sessions', [
            'id' => $sessionToDelete->id,
        ]);
        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $siblingSession->id,
        ]);
    }

    public function test_student_cannot_access_doctor_session_endpoints(): void
    {
        $doctor = $this->doctor();
        $student = $this->student();
        $course = $this->courseFor($doctor);
        $session = $this->sessionFor($course, $doctor);

        Sanctum::actingAs($student);

        $this->getJson('/api/sessions')->assertForbidden();
        $this->postJson('/api/sessions', ['course_id' => $course->id])->assertForbidden();
        $this->patchJson("/api/sessions/{$session->id}", ['method' => 'qr'])->assertForbidden();
        $this->deleteJson("/api/sessions/{$session->id}")->assertForbidden();
        $this->postJson("/api/sessions/{$session->id}/open")->assertForbidden();
        $this->postJson("/api/sessions/{$session->id}/close")->assertForbidden();
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
            'method' => 'face',
            'starts_at' => $this->futureStart(),
            'ends_at' => $this->futureEnd(),
            'status' => 'open',
        ], $attributes));
    }

    private function futureStart(): string
    {
        return now()->addDay()->setTime(10, 0)->toIso8601String();
    }

    private function futureEnd(): string
    {
        return now()->addDay()->setTime(11, 30)->toIso8601String();
    }

    private function otherDoctorSession(array $sessionAttributes = []): array
    {
        $doctor = $this->doctor(['email' => 'doctor-one@example.com']);
        $otherDoctor = $this->doctor(['email' => 'doctor-two@example.com']);
        $otherCourse = $this->courseFor($otherDoctor);
        $otherSession = $this->sessionFor($otherCourse, $otherDoctor, $sessionAttributes);

        return [$doctor, $otherSession];
    }
}
