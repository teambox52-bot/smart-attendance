<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use App\Http\Controllers\Concerns\HandlesGroupedSessions;
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
    use HandlesGroupedSessions;

    public function overview(Request $request)
    {
        $courses = Course::with(['enrollments', 'sessions.records'])
            ->where('doctor_id', $request->user()->id)
            ->get();

        $courseIds = $courses->pluck('id');
        $sessions = AttendanceSession::with('course.enrollments')
            ->whereIn('course_id', $courseIds)
            ->latest()
            ->get();

        $studentsCount = Enrollment::whereIn('course_id', $courseIds)
            ->distinct('user_id')
            ->count('user_id');

        $courseSummaries = $courses
            ->groupBy(fn (Course $course) => $this->logicalCourseKey($course))
            ->map(function ($group) use ($sessions) {
                /** @var Course $course */
                $course = $group->sortBy('id')->first();
                $courseIds = $group->pluck('id');
                $courseSessions = $sessions->whereIn('course_id', $courseIds);
                $sessionGroups = $this->logicalSessionGroups($courseSessions);
                $sessionsCount = $sessionGroups->count();
                $totalStudents = Enrollment::whereIn('course_id', $courseIds)
                    ->distinct('user_id')
                    ->count('user_id');

                $possibleAttendances = $sessionGroups->sum(function ($sessionGroup) {
                    $groupCourseIds = $sessionGroup->pluck('course_id');

                    return Enrollment::whereIn('course_id', $groupCourseIds)
                        ->distinct('user_id')
                        ->count('user_id');
                });
                $presentCount = $sessionGroups->sum(function ($sessionGroup) {
                    return AttendanceRecord::whereIn('attendance_session_id', $sessionGroup->pluck('id'))
                        ->where('status', 'present')
                        ->count();
                });

                return [
                    'id' => $course->id,
                    'name' => $course->name,
                    'code' => $this->displayCourseCode($course->code),
                    'total_students' => $totalStudents,
                    'sessions_count' => $sessionsCount,
                    'attendance_rate' => $possibleAttendances > 0 ? round(($presentCount / $possibleAttendances) * 100, 2) : 0,
                ];
            })
            ->values();

        $averageAttendanceRate = $courseSummaries->count() > 0
            ? round($courseSummaries->avg('attendance_rate'), 2)
            : 0;

        $sessionSummaries = $this->logicalSessionGroups($sessions)
            ->map(fn ($group) => $this->groupedSessionPayload($group))
            ->sortByDesc('id')
            ->values();

        return response()->json([
            'totals' => [
                'average_attendance_rate' => $averageAttendanceRate,
                'courses_count' => $courseSummaries->count(),
                'sessions_count' => $sessionSummaries->count(),
                'students_count' => $studentsCount,
            ],
            'trend' => [],
            'courses' => $courseSummaries,
            'sessions' => $sessionSummaries,
        ]);
    }

    private function logicalCourseKey(Course $course): string
    {
        return strtolower(trim($course->name)) . '::' . strtolower($this->displayCourseCode($course->code));
    }

    private function logicalSessionGroups($sessions)
    {
        return $sessions->groupBy(fn (AttendanceSession $session) => $session->session_group_key ?: 'session:' . $session->id);
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
