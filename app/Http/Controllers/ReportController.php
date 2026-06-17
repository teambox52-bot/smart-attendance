<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use Illuminate\Http\Request;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Exports\CourseReportExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SessionReportExport;

class ReportController extends Controller
{
    use AuthorizesDoctorResources;

    public function overview(Request $request)
    {
        $courses = Course::with('sessions')
            ->where('doctor_id', $request->user()->id)
            ->get();

        $courseIds = $courses->pluck('id');
        $sessions = AttendanceSession::with('course')
            ->whereIn('course_id', $courseIds)
            ->latest()
            ->get();

        $studentsCount = Enrollment::whereIn('course_id', $courseIds)
            ->distinct('user_id')
            ->count('user_id');

        $courseSummaries = $courses->map(function (Course $course) {
            $sessionIds = $course->sessions->pluck('id');
            $sessionsCount = $sessionIds->count();
            $totalStudents = $course->enrollments()->count();
            $possibleAttendances = $sessionsCount * $totalStudents;
            $presentCount = $possibleAttendances > 0
                ? AttendanceRecord::whereIn('attendance_session_id', $sessionIds)
                    ->where('status', 'present')
                    ->count()
                : 0;

            return [
                'id' => $course->id,
                'name' => $course->name,
                'code' => $course->code,
                'total_students' => $totalStudents,
                'sessions_count' => $sessionsCount,
                'attendance_rate' => $possibleAttendances > 0 ? round(($presentCount / $possibleAttendances) * 100, 2) : 0,
            ];
        })->values();

        $averageAttendanceRate = $courseSummaries->count() > 0
            ? round($courseSummaries->avg('attendance_rate'), 2)
            : 0;

        $sessionSummaries = $sessions->map(function (AttendanceSession $session) {
            $totalCount = $session->course ? $session->course->enrollments()->count() : 0;
            $presentCount = $session->records()->where('status', 'present')->count();

            return [
                'id' => $session->id,
                'course_id' => $session->course_id,
                'course_name' => $session->course?->name,
                'course_code' => $session->course?->code,
                'method' => $session->method,
                'status' => $session->status,
                'starts_at' => $session->scheduleStartsAt(),
                'ends_at' => $session->scheduleEndsAt(),
                'display_date' => $session->scheduleDate(),
                'display_start_time' => $session->scheduleStartTime(),
                'display_end_time' => $session->scheduleEndTime(),
                'present_count' => $presentCount,
                'total_count' => $totalCount,
            ];
        })->values();

        return response()->json([
            'totals' => [
                'average_attendance_rate' => $averageAttendanceRate,
                'courses_count' => $courses->count(),
                'sessions_count' => $sessions->count(),
                'students_count' => $studentsCount,
            ],
            'trend' => [],
            'courses' => $courseSummaries,
            'sessions' => $sessionSummaries,
        ]);
    }

    public function courseReport(Request $request, $id)
    {
        $course = $this->findDoctorCourseOrFail($request, $id)->load([
            'doctor',
            'sessions.records.user'
        ]);

        return response()->json($course);
    }

    public function studentReport(Request $request, $id)
    {
        if ((int) $id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $records = AttendanceRecord::with('session.course')
            ->where('user_id', $id)
            ->get();

        $present = $records->where('status', 'present')->count();
        $total = $records->count();
        $percentage = $total > 0 ? round(($present / $total) * 100, 2) : 0;

        return response()->json([
            'student_id' => $id,
            'total_sessions' => $total,
            'present_count' => $present,
            'absent_count' => $total - $present,
            'attendance_percentage' => $percentage . '%',
            'records' => $records
        ]);
    }

    public function myReport(Request $request)
    {
        $student = $request->user();

        if ($student->role !== 'student') {
            return response()->json([
                'message' => 'Only students can view this report'
            ], 403);
        }

        $enrollments = Enrollment::with('course.doctor')
            ->where('user_id', $student->id)
            ->get();

        $coursesReport = [];

        $overallTotalSessions = 0;
        $overallPresentCount = 0;

        foreach ($enrollments as $enrollment) {
            $course = $enrollment->course;

            if (!$course) {
                continue;
            }

            $sessions = $course->sessions;
            $totalSessions = $sessions->count();

            $sessionIds = $sessions->pluck('id');

            $presentCount = AttendanceRecord::where('user_id', $student->id)
                ->whereIn('attendance_session_id', $sessionIds)
                ->where('status', 'present')
                ->count();

            $absentCount = $totalSessions - $presentCount;

            $percentage = $totalSessions > 0
                ? round(($presentCount / $totalSessions) * 100, 2)
                : 0;

            $coursesReport[] = [
                'course_id' => $course->id,
                'course_name' => $course->name,
                'course_code' => $course->code,
                'semester' => $course->semester,
                'level' => $course->level,
                'doctor' => $course->doctor ? [
                    'id' => $course->doctor->id,
                    'name' => $course->doctor->name,
                    'email' => $course->doctor->email,
                ] : null,
                'total_sessions' => $totalSessions,
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'attendance_percentage' => $percentage . '%'
            ];

            $overallTotalSessions += $totalSessions;
            $overallPresentCount += $presentCount;
        }

        $overallAbsentCount = $overallTotalSessions - $overallPresentCount;

        $overallPercentage = $overallTotalSessions > 0
            ? round(($overallPresentCount / $overallTotalSessions) * 100, 2)
            : 0;

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'university_code' => $student->university_code,
                'major' => $student->major,
                'level' => $student->level,
            ],
            'courses' => $coursesReport,
            'overall' => [
                'total_sessions' => $overallTotalSessions,
                'present_count' => $overallPresentCount,
                'absent_count' => $overallAbsentCount,
                'attendance_percentage' => $overallPercentage . '%'
            ]
        ]);
    }

    public function exportCourseReport(Request $request, $id)
    {
        $course = $this->findDoctorCourseOrFail($request, $id);

        $fileName = str_replace(' ', '_', $course->name) . '_Report.xlsx';

        return Excel::download(
            new CourseReportExport($id),
            $fileName
        );
    }
    public function exportSessionReport(Request $request, $id)
    {
        $session = $this->findDoctorSessionOrFail($request, $id)->load('course');

        $fileName = str_replace(' ', '_', $session->course->name)
            . '_Session_' . $session->id . '_Report.xlsx';

        return Excel::download(
            new SessionReportExport($id),
            $fileName
        );
    }
}
