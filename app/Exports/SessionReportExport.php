<?php

namespace App\Exports;

use App\Models\AttendanceSession;
use App\Models\Enrollment;
use Maatwebsite\Excel\Concerns\FromArray;

class SessionReportExport implements FromArray
{
    protected $sessionId;

    public function __construct($sessionId)
    {
        $this->sessionId = $sessionId;
    }

    public function array(): array
    {
        $session = AttendanceSession::with('course')->find($this->sessionId);

        if (!$session || !$session->course) {
            return [];
        }

        $sessions = $this->logicalSessionRows($session);
        $courseIds = $sessions->pluck('course_id');

        $students = Enrollment::with('user')
            ->with('course')
            ->whereIn('course_id', $courseIds)
            ->get()
            ->unique('user_id')
            ->sortBy(fn ($enrollment) => $enrollment->user?->university_code ?? '')
            ->values();

        $rows = [
            ['Course Name', $session->course->name],
            ['Course Code', $this->displayCourseCode($session->course->code)],
            ['Session ID', $this->displaySessionIds($sessions)],
            ['Status', $this->displaySessionStatus($sessions)],
            ['Open Date', $this->displayDateTime($session->starts_at)],
            ['Close Date', $this->displayDateTime($session->ends_at)],
            [],
            ['University Code', 'Full Name', 'Status'],
        ];

        foreach ($students as $enrollment) {
            $student = $enrollment->user;

            if (!$student) {
                continue;
            }

            $rows[] = [
                $student->university_code,
                $student->name,
                $this->studentAttendanceStatus($sessions, $enrollment->course_id, $student->id),
            ];
        }

        return $rows;
    }

    private function logicalSessionRows(AttendanceSession $session)
    {
        if (!filled($session->session_group_key)) {
            return collect([$session]);
        }

        return AttendanceSession::with('course')
            ->where('session_group_key', $session->session_group_key)
            ->whereHas('course', function ($query) use ($session) {
                $query->where('doctor_id', $session->course?->doctor_id);
            })
            ->orderBy('id')
            ->get();
    }

    private function displayCourseCode(string $code): string
    {
        return preg_replace('/-(CS|IS)[1-4](?:-\d+)?$/i', '', $code) ?: $code;
    }

    private function displaySessionIds($sessions): string
    {
        return $sessions->pluck('id')->join(', ');
    }

    private function displaySessionStatus($sessions): string
    {
        $statuses = $sessions->pluck('status')->unique()->values();

        return $statuses->count() === 1 ? (string) $statuses->first() : $statuses->join(', ');
    }

    private function displayDateTime($value): string
    {
        return $value ? $value->format('Y-m-d H:i') : '';
    }

    private function studentAttendanceStatus($sessions, $courseId, $studentId): string
    {
        $childSession = $sessions->firstWhere('course_id', $courseId);
        $record = $childSession
            ? $childSession->records()
                ->where('user_id', $studentId)
                ->first()
            : null;

        return in_array($record?->status, ['present', 'late'], true) ? 'Present' : 'Absent';
    }
}
