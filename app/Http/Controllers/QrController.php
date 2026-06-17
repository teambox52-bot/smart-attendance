<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use App\Http\Controllers\Concerns\HandlesGroupedSessions;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use Illuminate\Support\Carbon;

class QrController extends Controller
{
    use AuthorizesDoctorResources;
    use HandlesGroupedSessions;

    private function studentPayload(User $student, ?AttendanceRecord $attendance = null): array
    {
        return [
            'id' => $student->id,
            'full_name' => $student->name,
            'name' => $student->name,
            'email' => $student->email,
            'university_code' => $student->university_code,
            'status' => $attendance?->status,
            'attended_at' => $attendance?->attended_at,
            'method' => $attendance?->method,
            'match_score' => $attendance?->match_score,
            'face_enrolled' => !empty($student->face_token),
        ];
    }

    private function attendancePayload(AttendanceRecord $attendance): array
    {
        return [
            'id' => $attendance->id,
            'session_id' => $attendance->attendance_session_id,
            'method' => $attendance->method,
            'status' => $attendance->status,
            'attended_at' => $attendance->attended_at,
        ];
    }

    public function generate(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Only students can generate QR'
            ], 403);
        }

        $data = json_encode([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'university_code' => $user->university_code,
        ]);

        $qrUrl = 'https://quickchart.io/qr?size=300&text=' . urlencode($data);

        return response()->json([
            'message' => 'QR generated successfully',
            'qr_payload' => $data,
            'qr_url' => $qrUrl,
            'qr_data' => $data
        ]);
    }

    public function scan(Request $request)
    {
        $request->validate([
            'qr_payload' => 'required_without:qr_data',
            'qr_data' => 'required_without:qr_payload',
            'session_id' => 'required|exists:attendance_sessions,id',
        ]);

        if ($request->user()->role !== 'doctor') {
            return response()->json([
                'message' => 'Only doctors can scan QR'
            ], 403);
        }

        $qrPayload = $request->input('qr_payload', $request->input('qr_data'));
        $decoded = json_decode($qrPayload, true);

        if (!$decoded || !isset($decoded['id'])) {
            return response()->json([
                'message' => 'Invalid QR data'
            ], 422);
        }

        $student = User::where('id', $decoded['id'])
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found'
            ], 404);
        }

        $representativeSession = $this->findDoctorSessionOrFail($request, $request->session_id);
        $sessions = $this->sessionGroupFor($request, $representativeSession);
        $session = $this->matchingSessionForStudent($sessions, $student);

        if ($sessions->contains(fn ($child) => $child->status !== 'open')) {
            return response()->json([
                'message' => 'Session is closed'
            ], 400);
        }

        if (!$session) {
            return response()->json([
                'message' => 'Student not enrolled in this course'
            ], 403);
        }

        $exists = AttendanceRecord::where('user_id', $student->id)
            ->where('attendance_session_id', $session->id)
            ->exists();

        if ($exists) {
            $attendance = AttendanceRecord::where('user_id', $student->id)
                ->where('attendance_session_id', $session->id)
                ->first();

            return response()->json([
                'message' => 'Student already marked present',
                'student' => $this->studentPayload($student, $attendance),
                'attendance' => $attendance ? $this->attendancePayload($attendance) : null,
            ], 409);
        }

        $attendance = AttendanceRecord::create([
            'user_id' => $student->id,
            'attendance_session_id' => $session->id,
            'method' => 'qr',
            'status' => 'present',
            'attended_at' => Carbon::now(),
        ]);

        return response()->json([
            'message' => 'Attendance marked successfully by QR',
            'student' => $this->studentPayload($student, $attendance),
            'attendance' => $this->attendancePayload($attendance),
        ], 201);
    }
}
