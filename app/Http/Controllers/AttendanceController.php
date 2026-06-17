<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use App\Http\Controllers\Concerns\HandlesGroupedSessions;
use Illuminate\Http\Request;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;

class AttendanceController extends Controller
{
    use AuthorizesDoctorResources;
    use HandlesGroupedSessions;

    public function mark(Request $request)
    {
        $request->validate([
            'user_id' => 'prohibited',
            'session_id' => 'required|exists:attendance_sessions,id',
            'method' => 'required|in:face,qr'
        ]);

        $user = $request->user();
        $session = AttendanceSession::find($request->session_id);

        if ($session->status !== 'open') {
            return response()->json([
                'message' => 'Session is closed'
            ], 400);
        }

        $enrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $session->course_id)
            ->exists();

        if (!$enrolled) {
            return response()->json([
                'message' => 'Student not enrolled in this course'
            ], 403);
        }

        $exists = AttendanceRecord::where('user_id', $user->id)
            ->where('attendance_session_id', $request->session_id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Student already marked present'
            ], 409);
        }

        $attendance = AttendanceRecord::create([
            'user_id' => $user->id,
            'attendance_session_id' => $request->session_id,
            'method' => $request->input('method'),
            'status' => 'present'
        ]);

        return response()->json([
            'message' => 'Attendance marked successfully',
            'data' => $attendance
        ]);
    }

    public function report(Request $request, $session_id)
    {
        $session = $this->findDoctorSessionOrFail($request, $session_id)->load('course');
        $sessions = $this->sessionGroupFor($request, $session);
        $sessionIds = $sessions->pluck('id');
        $courseIds = $sessions->pluck('course_id');

        $students = Enrollment::whereIn('course_id', $courseIds)
            ->with('user')
            ->with('course')
            ->get();

        $report = [];

        foreach ($students as $enrollment) {
            $user = $enrollment->user;
            $childSession = $sessions->firstWhere('course_id', $enrollment->course_id);
            $record = $childSession
                ? AttendanceRecord::where('attendance_session_id', $childSession->id)
                ->where('user_id', $user->id)
                ->first()
                : null;

            $report[] = [
                'id' => $user->id,
                'university_code' => $user->university_code,
                'full_name' => $user->name,
                'name' => $user->name,
                'email' => $user->email,
                'major' => $user->major,
                'level' => $user->level,
                'course_id' => $enrollment->course_id,
                'course_code' => $enrollment->course?->code,
                'session_id' => $childSession?->id,
                'status' => $record?->status ?: 'absent',
                'attended_at' => $record?->attended_at ?: $record?->created_at,
                'method' => $record?->method,
                'match_score' => $record?->match_score,
                'face_enrolled' => !empty($user->face_token),
            ];
        }

        $presentCount = AttendanceRecord::whereIn('attendance_session_id', $sessionIds)
            ->where('status', 'present')
            ->count();
        $totalCount = $students->count();

        return response()->json([
            'session' => array_merge($this->groupedSessionPayload($sessions), [
                'present_count' => $presentCount,
                'total_count' => $totalCount,
            ]),
            'students' => $report,
        ]);
    }

    public function myAttendance(Request $request)
    {
        $student = $request->user();

        $records = AttendanceRecord::with('session.course')
            ->where('user_id', $student->id)
            ->latest()
            ->get()
            ->map(function (AttendanceRecord $record) use ($student) {
                $session = $record->session;
                $course = $session?->course;
                $sessionDate = $session?->starts_at ?: $record->created_at;

                $courseSessionIds = $course
                    ? $course->sessions()->pluck('id')
                    : collect();
                $totalRecords = $courseSessionIds->count() > 0
                    ? AttendanceRecord::where('user_id', $student->id)
                        ->whereIn('attendance_session_id', $courseSessionIds)
                        ->count()
                    : 0;
                $presentCount = $totalRecords > 0
                    ? AttendanceRecord::where('user_id', $student->id)
                        ->whereIn('attendance_session_id', $courseSessionIds)
                        ->where('status', 'present')
                        ->count()
                    : 0;

                return [
                    'id' => $record->id,
                    'course_id' => $course?->id,
                    'course_code' => $course?->code,
                    'course_name' => $course?->name,
                    'session_id' => $session?->id,
                    'date' => $sessionDate?->format('Y-m-d'),
                    'time' => $sessionDate?->format('H:i'),
                    'status' => $record->status,
                    'method' => $record->method,
                    'attendance_rate' => $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 2) : 0,
                    'attended_at' => $record->attended_at ?: $record->created_at,
                ];
            })
            ->values();

        return response()->json([
            'records' => $records,
        ]);
    }
}
