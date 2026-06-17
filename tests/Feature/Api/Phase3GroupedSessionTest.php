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

class Phase3GroupedSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_session_creation_and_list_returns_one_logical_session(): void
    {
        [$doctor, $courses] = $this->groupedCourses();
        $groupKey = 'group-test-1';

        Sanctum::actingAs($doctor);

        foreach ($courses as $course) {
            $this->postJson('/api/sessions', [
                'course_id' => $course->id,
                'method' => 'qr',
                'starts_at' => '2026-03-31T10:00:00Z',
                'ends_at' => '2026-03-31T11:30:00Z',
                'session_group_key' => $groupKey,
            ])->assertCreated();
        }

        $response = $this->getJson('/api/sessions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.session_group_key', $groupKey)
            ->assertJsonPath('data.0.course_code', 'AQ')
            ->assertJsonPath('data.0.levels', ['1', '2', '3', '4'])
            ->assertJsonPath('data.0.level_labels', ['First Year', 'Second Year', 'Third Year', 'Fourth Year']);

        $this->assertSame(4, AttendanceSession::where('session_group_key', $groupKey)->count());
    }

    public function test_opening_grouped_session_opens_all_children(): void
    {
        [$doctor, $courses] = $this->groupedCourses();
        $sessions = $this->sessionsFor($doctor, $courses);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$sessions[0]->id}/open")
            ->assertOk()
            ->assertJsonPath('session.status', 'open');

        $this->assertSame(4, AttendanceSession::where('session_group_key', 'group-test')
            ->where('status', 'open')
            ->count());
    }

    public function test_qr_scan_with_grouped_session_marks_matching_child_session(): void
    {
        [$doctor, $courses] = $this->groupedCourses();
        $sessions = $this->sessionsFor($doctor, $courses, ['status' => 'open']);
        $student = $this->student(['level' => '3']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $courses[2]->id]);

        Sanctum::actingAs($doctor);

        $this->postJson('/api/qr/scan', [
            'session_id' => $sessions[0]->id,
            'qr_payload' => $this->qrPayloadFor($student),
        ])
            ->assertCreated()
            ->assertJsonPath('attendance.session_id', $sessions[2]->id);

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $student->id,
            'attendance_session_id' => $sessions[2]->id,
            'status' => 'present',
        ]);
    }

    public function test_closing_grouped_session_creates_absences_for_each_child_course(): void
    {
        [$doctor, $courses] = $this->groupedCourses();
        $sessions = $this->sessionsFor($doctor, $courses, ['status' => 'open']);
        $studentOne = $this->student(['level' => '1', 'university_code' => 'S001']);
        $studentTwo = $this->student(['level' => '2', 'university_code' => 'S002']);
        Enrollment::create(['user_id' => $studentOne->id, 'course_id' => $courses[0]->id]);
        Enrollment::create(['user_id' => $studentTwo->id, 'course_id' => $courses[1]->id]);
        AttendanceRecord::create([
            'user_id' => $studentOne->id,
            'attendance_session_id' => $sessions[0]->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->postJson("/api/sessions/{$sessions[0]->id}/close")
            ->assertOk()
            ->assertJsonPath('session.status', 'closed')
            ->assertJsonPath('session.present_count', 1)
            ->assertJsonPath('session.total_count', 2);

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $studentTwo->id,
            'attendance_session_id' => $sessions[1]->id,
            'status' => 'absent',
        ]);
        $this->assertSame(2, AttendanceRecord::count());
    }

    public function test_grouped_session_report_combines_child_sessions(): void
    {
        [$doctor, $courses] = $this->groupedCourses();
        $sessions = $this->sessionsFor($doctor, $courses, ['status' => 'closed']);
        $student = $this->student(['level' => '4']);
        Enrollment::create(['user_id' => $student->id, 'course_id' => $courses[3]->id]);
        AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $sessions[3]->id,
            'method' => 'qr',
            'status' => 'present',
        ]);

        Sanctum::actingAs($doctor);

        $this->getJson("/api/sessions/{$sessions[0]->id}/report")
            ->assertOk()
            ->assertJsonPath('session.course_code', 'AQ')
            ->assertJsonPath('students.0.course_code', 'AQ-CS4')
            ->assertJsonPath('students.0.session_id', $sessions[3]->id)
            ->assertJsonPath('students.0.status', 'present');
    }

    private function groupedCourses(): array
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $courses = collect([1, 2, 3, 4])->map(fn ($level) => Course::create([
            'name' => 'aq',
            'code' => "AQ-CS{$level}",
            'doctor_id' => $doctor->id,
            'semester' => 'CS',
            'level' => (string) $level,
            'is_active' => true,
        ]))->values();

        return [$doctor, $courses];
    }

    private function sessionsFor(User $doctor, $courses, array $attributes = [])
    {
        return $courses->map(fn (Course $course) => AttendanceSession::create(array_merge([
            'course_id' => $course->id,
            'created_by' => $doctor->id,
            'session_group_key' => 'group-test',
            'method' => 'qr',
            'starts_at' => '2026-03-31T10:00:00Z',
            'ends_at' => '2026-03-31T11:30:00Z',
            'status' => 'scheduled',
        ], $attributes)))->values();
    }

    private function student(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'student',
            'major' => 'CS',
            'level' => '1',
            'university_code' => fake()->unique()->numerify('S#####'),
        ], $attributes));
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
}
