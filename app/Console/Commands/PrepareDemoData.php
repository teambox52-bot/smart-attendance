<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class PrepareDemoData extends Command
{
    protected $signature = 'demo:prepare';

    protected $description = 'Creates or updates safe graduation-demo users, courses, sessions, and attendance data.';

    public function handle(): int
    {
        $password = 'Password123!';

        $doctor = $this->upsertUser([
            'name' => 'Dr. Demo',
            'email' => 'demo.doctor@smart-attendance.local',
            'role' => 'doctor',
            'password' => Hash::make($password),
            'university_code' => null,
            'major' => null,
            'level' => null,
        ]);

        $cs3 = $this->upsertUser([
            'name' => 'CS Level 3 Student',
            'email' => 'demo.student.cs3@smart-attendance.local',
            'role' => 'student',
            'password' => Hash::make($password),
            'university_code' => 'DEMO-CS3',
            'major' => 'CS',
            'level' => 3,
        ]);

        $is3 = $this->upsertUser([
            'name' => 'IS Level 3 Student',
            'email' => 'demo.student.is3@smart-attendance.local',
            'role' => 'student',
            'password' => Hash::make($password),
            'university_code' => 'DEMO-IS3',
            'major' => 'IS',
            'level' => 3,
        ]);

        $cs4 = $this->upsertUser([
            'name' => 'CS Level 4 Student',
            'email' => 'demo.student.cs4@smart-attendance.local',
            'role' => 'student',
            'password' => Hash::make($password),
            'university_code' => 'DEMO-CS4',
            'major' => 'CS',
            'level' => 4,
        ]);

        $csCourse = $this->upsertCourse([
            'name' => 'Demo CS Third Year Attendance',
            'code' => 'DEMO-CS3-ATT',
            'doctor_id' => $doctor->id,
            'semester' => 'CS',
            'level' => '3',
            'is_active' => true,
        ]);

        $isCourse = $this->upsertCourse([
            'name' => 'Demo IS Third Year Attendance',
            'code' => 'DEMO-IS3-ATT',
            'doctor_id' => $doctor->id,
            'semester' => 'IS',
            'level' => '3',
            'is_active' => true,
        ]);

        $sharedCourse = $this->upsertCourse([
            'name' => 'Demo Shared CS IS Third Year',
            'code' => 'DEMO-SHARED-CSIS3',
            'doctor_id' => $doctor->id,
            'semester' => 'CS,IS',
            'level' => '3',
            'is_active' => true,
        ]);

        $cs4Course = $this->upsertCourse([
            'name' => 'Demo CS Fourth Year Attendance',
            'code' => 'DEMO-CS4-ATT',
            'doctor_id' => $doctor->id,
            'semester' => 'CS',
            'level' => '4',
            'is_active' => true,
        ]);

        $this->enroll($cs3, $csCourse);
        $this->enroll($is3, $isCourse);
        $this->enroll($cs3, $sharedCourse);
        $this->enroll($is3, $sharedCourse);
        $this->enroll($cs4, $cs4Course);

        $scheduledSession = $this->upsertSession([
            'course_id' => $csCourse->id,
            'created_by' => $doctor->id,
            'method' => 'both',
            'status' => 'scheduled',
            'starts_at' => '2026-06-06 10:00:00',
            'ends_at' => '2026-06-06 11:30:00',
        ]);

        $openSession = $this->upsertSession([
            'course_id' => $csCourse->id,
            'created_by' => $doctor->id,
            'method' => 'both',
            'status' => 'open',
            'starts_at' => '2026-06-06 12:00:00',
            'ends_at' => '2026-06-06 13:30:00',
        ]);

        $closedSession = $this->upsertSession([
            'course_id' => $csCourse->id,
            'created_by' => $doctor->id,
            'method' => 'both',
            'status' => 'closed',
            'starts_at' => '2026-06-05 10:00:00',
            'ends_at' => '2026-06-05 11:30:00',
        ]);

        $closedAbsentSession = $this->upsertSession([
            'course_id' => $csCourse->id,
            'created_by' => $doctor->id,
            'method' => 'qr',
            'status' => 'closed',
            'starts_at' => '2026-06-04 10:00:00',
            'ends_at' => '2026-06-04 11:30:00',
        ]);

        $this->upsertAttendance($cs3, $closedSession, [
            'method' => 'qr',
            'status' => 'present',
            'attended_at' => '2026-06-05 10:07:00',
            'match_score' => null,
        ]);

        $this->upsertAttendance($is3, $closedSession, [
            'method' => 'qr',
            'status' => 'absent',
            'attended_at' => null,
            'match_score' => null,
        ]);

        $this->upsertAttendance($cs3, $closedAbsentSession, [
            'method' => 'qr',
            'status' => 'absent',
            'attended_at' => null,
            'match_score' => null,
        ]);

        $this->line('Demo data prepared safely.');
        $this->line('Doctor: demo.doctor@smart-attendance.local / Password123!');
        $this->line('Student CS3: demo.student.cs3@smart-attendance.local / Password123!');
        $this->line('Student IS3: demo.student.is3@smart-attendance.local / Password123!');
        $this->line('Student CS4: demo.student.cs4@smart-attendance.local / Password123!');
        $this->line('Courses: DEMO-CS3-ATT, DEMO-IS3-ATT, DEMO-SHARED-CSIS3, DEMO-CS4-ATT');
        $this->line('Sessions: scheduled #' . $scheduledSession->id . ', open #' . $openSession->id . ', closed #' . $closedSession->id . ', absent-demo #' . $closedAbsentSession->id);

        return self::SUCCESS;
    }

    private function upsertUser(array $data): User
    {
        $universityCode = $data['university_code'] ?? null;
        $email = $data['email'];
        unset($data['university_code']);

        if ($universityCode) {
            User::where('university_code', $universityCode)
                ->where('email', '!=', $email)
                ->update(['university_code' => null]);
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            $data,
        );

        if ($universityCode) {
            $user->forceFill(['university_code' => $universityCode])->save();
        }

        return $user->fresh();
    }

    private function upsertCourse(array $data): Course
    {
        return Course::updateOrCreate(
            ['code' => $data['code']],
            $data,
        );
    }

    private function enroll(User $student, Course $course): void
    {
        Enrollment::firstOrCreate([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
    }

    private function upsertSession(array $data): AttendanceSession
    {
        return AttendanceSession::updateOrCreate(
            [
                'course_id' => $data['course_id'],
                'starts_at' => $data['starts_at'],
            ],
            $data,
        );
    }

    private function upsertAttendance(User $student, AttendanceSession $session, array $data): void
    {
        AttendanceRecord::updateOrCreate(
            [
                'user_id' => $student->id,
                'attendance_session_id' => $session->id,
            ],
            $data,
        );
    }
}
