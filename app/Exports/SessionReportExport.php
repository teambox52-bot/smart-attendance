<?php

namespace App\Exports;

use App\Models\Enrollment;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
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
        $rows = [];

        $session = AttendanceSession::with('course')->find($this->sessionId);
        $sessions = filled($session?->session_group_key)
            ? AttendanceSession::with('course')
                ->where('session_group_key', $session->session_group_key)
                ->whereHas('course', function ($query) use ($session) {
                    $query->where('doctor_id', $session->course?->doctor_id);
                })
                ->orderBy('id')
                ->get()
            : collect([$session]);
        $sessionIds = $sessions->pluck('id');
        $courseIds = $sessions->pluck('course_id');

        $rows[] = ['Course Name', $session->course->name];
        $rows[] = ['Course Code', preg_replace('/-(CS|IS)[1-4](?:-\d+)?$/i', '', $session->course->code) ?: $session->course->code];
        $rows[] = ['Session ID', $session->id];
        $rows[] = ['Status', $session->status];
        $rows[] = [];

        $rows[] = [
            'University Code',
            'Full Name',
            'Email',
            'Major',
            'Level',
            'Course Code',
            'Status',
            'Method',
            'Attended At',
            'Match Score',
        ];

        $students = Enrollment::with('user')
            ->with('course')
            ->whereIn('course_id', $courseIds)
            ->get();

        $presentStudents = [];
        $absentStudents = [];

        foreach ($students as $enrollment) {
            $student = $enrollment->user;
            $childSession = $sessions->firstWhere('course_id', $enrollment->course_id);
            $record = $childSession
                ? AttendanceRecord::where('user_id', $student->id)
                    ->where('attendance_session_id', $childSession->id)
                    ->first()
                : null;

            $present = $record?->status === 'present';

            $studentRow = [
                $student->university_code,
                $student->name,
                $student->email,
                $student->major,
                $student->level,
                $enrollment->course?->code,
                $record?->status ?: 'absent',
                $record?->method,
                $record?->attended_at ?: $record?->created_at,
                $record?->match_score,
            ];

            if ($present) {
                $presentStudents[] = $studentRow;
            } else {
                $absentStudents[] = $studentRow;
            }
        }

        foreach ($presentStudents as $student) {
            $rows[] = $student;
        }

        foreach ($absentStudents as $student) {
            $rows[] = $student;
        }

        return $rows;
    }
}
