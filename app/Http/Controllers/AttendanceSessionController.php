<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use App\Http\Controllers\Concerns\HandlesGroupedSessions;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class AttendanceSessionController extends Controller
{
    use AuthorizesDoctorResources;
    use HandlesGroupedSessions;

    public function index(Request $request)
    {
        $sessions = AttendanceSession::with('course')
            ->whereHas('course', function ($query) use ($request) {
                $query->where('doctor_id', $request->user()->id);
            })
            ->latest()
            ->get();

        $grouped = $sessions
            ->groupBy(fn (AttendanceSession $session) => $session->session_group_key ?: 'session:' . $session->id)
            ->map(fn ($group) => $this->groupedSessionPayload($group))
            ->sortByDesc('id')
            ->values();

        return response()->json([
            'data' => $grouped,
        ]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'method' => 'required|in:face,qr,both',
            'starts_at' => 'bail|required|date|after_or_equal:now',
            'ends_at' => 'required|date|after:starts_at',
            'session_group_key' => 'sometimes|nullable|string|max:255',
        ], [
            'starts_at.required' => 'Please enter a valid session date and time.',
            'starts_at.date' => 'Please enter a valid session date and time.',
            'starts_at.after_or_equal' => 'Please choose a valid future session date and time.',
            'ends_at.required' => 'Please enter a valid session date and time.',
            'ends_at.date' => 'Please enter a valid session date and time.',
            'ends_at.after' => 'Please enter a valid session date and time.',
        ]);

        $user = auth()->user();

        if ($user->role !== 'doctor') {
            return response()->json([
                'message' => 'Only doctors can create sessions'
            ], 403);
        }

        $course = $this->findDoctorCourseOrFail($request, $request->course_id);

        try {
            $session = AttendanceSession::create([
                'course_id'  => $request->course_id,
                'created_by' => $user->id,
                'session_group_key' => $request->input('session_group_key'),
                'method' => $request->input('method'),
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
                'status'     => 'scheduled',
            ]);
        } catch (QueryException) {
            return response()->json([
                'message' => 'Unable to create session. Please check session data and try again.',
            ], 422);
        }

        return response()->json([
            'message' => 'Session created successfully',
            'session' => $this->groupedSessionPayload(collect([$session->setRelation('course', $course)])),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $session = $this->findDoctorSessionOrFail($request, $id);

        if ($session->status !== 'scheduled') {
            return response()->json([
                'message' => 'Only scheduled sessions can be updated.',
            ], 409);
        }

        $sessions = $this->sessionGroupFor($request, $session);

        if ($sessions->contains(fn (AttendanceSession $child) => $child->status !== 'scheduled')) {
            return response()->json([
                'message' => 'Only scheduled sessions can be updated.',
            ], 409);
        }

        $request->validate([
            'method' => 'sometimes|required|in:face,qr,both',
            'starts_at' => 'bail|sometimes|required|date|after_or_equal:now',
            'ends_at' => 'sometimes|nullable|date|after:starts_at',
        ], [
            'starts_at.date' => 'Please enter a valid session date and time.',
            'starts_at.after_or_equal' => 'Please choose a valid future session date and time.',
            'ends_at.date' => 'Please enter a valid session date and time.',
            'ends_at.after' => 'Please enter a valid session date and time.',
        ]);

        foreach ($sessions as $child) {
            $child->fill($request->only(['method', 'starts_at', 'ends_at']));
            $child->save();
        }

        return response()->json([
            'message' => 'Session updated successfully',
            'session' => $this->groupedSessionPayload($this->sessionGroupFor($request, $session->refresh())),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $session = AttendanceSession::with('course')
            ->where('id', $id)
            ->firstOrFail();

        if ($session->course?->doctor_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not allowed to access this session.',
            ], 403);
        }

        $sessions = $this->sessionGroupFor($request, $session);

        if ($sessions->contains(fn (AttendanceSession $child) => $child->status !== 'scheduled')) {
            return response()->json([
                'message' => 'Only scheduled sessions can be deleted.',
            ], 409);
        }

        if (AttendanceRecord::whereIn('attendance_session_id', $sessions->pluck('id'))->exists()) {
            return response()->json([
                'message' => 'Sessions with attendance records cannot be deleted.',
            ], 409);
        }

        AttendanceSession::whereIn('id', $sessions->pluck('id'))->delete();

        return response()->json([
            'message' => 'Session deleted successfully',
        ]);
    }

    public function open(Request $request, $id)
    {
        $session = $this->findDoctorSessionOrFail($request, $id);
        $sessions = $this->sessionGroupFor($request, $session);

        foreach ($sessions as $child) {
            $child->status = 'open';
            $child->save();
        }

        return response()->json([
            'message' => 'Session opened successfully',
            'session' => $this->groupedSessionPayload($this->sessionGroupFor($request, $session->refresh())),
        ]);
    }

    public function close(Request $request, $id)
    {
        $session = $this->findDoctorSessionOrFail($request, $id);

        $sessions = $this->sessionGroupFor($request, $session);

        if ($sessions->contains(fn (AttendanceSession $child) => $child->status !== 'open')) {
            return response()->json([
                'message' => 'Open the session before closing it.',
            ], 409);
        }

        foreach ($sessions as $child) {
            $child->status = 'closed';
            $child->save();
            $this->markMissingStudentsAbsent($child->load('course.enrollments'));
        }

        return response()->json([
            'message' => 'Session closed successfully',
            'session' => $this->groupedSessionPayload($this->sessionGroupFor($request, $session->refresh())),
        ]);
    }

    private function markMissingStudentsAbsent(AttendanceSession $session): void
    {
        $method = $session->method === 'face' ? 'face' : 'qr';

        foreach ($session->course?->enrollments ?? [] as $enrollment) {
            AttendanceRecord::firstOrCreate(
                [
                    'user_id' => $enrollment->user_id,
                    'attendance_session_id' => $session->id,
                ],
                [
                    'method' => $method,
                    'status' => 'absent',
                    'attended_at' => null,
                ]
            );
        }
    }

}
