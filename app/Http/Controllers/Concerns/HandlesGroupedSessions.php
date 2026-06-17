<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait HandlesGroupedSessions
{
    protected function sessionGroupFor(Request $request, AttendanceSession $session): Collection
    {
        $session->loadMissing('course.enrollments');

        if (!filled($session->session_group_key)) {
            return collect([$session]);
        }

        return AttendanceSession::with('course.enrollments')
            ->where('session_group_key', $session->session_group_key)
            ->whereHas('course', function ($query) use ($request) {
                $query->where('doctor_id', $request->user()->id);
            })
            ->orderBy('id')
            ->get();
    }

    protected function matchingSessionForStudent(Collection $sessions, User $student): ?AttendanceSession
    {
        $courseIds = $sessions->pluck('course_id')->all();
        $enrollment = Enrollment::where('user_id', $student->id)
            ->whereIn('course_id', $courseIds)
            ->first();

        if (!$enrollment) {
            return null;
        }

        return $sessions->firstWhere('course_id', $enrollment->course_id);
    }

    protected function groupedSessionPayload(Collection $sessions): array
    {
        /** @var AttendanceSession $representative */
        $representative = $sessions->sortBy('id')->first();
        $sessions = $sessions->values();
        $sessionIds = $sessions->pluck('id')->values();
        $courses = $sessions->pluck('course')->filter()->values();
        $courseIds = $courses->pluck('id')->values();
        $presentCount = AttendanceRecord::whereIn('attendance_session_id', $sessionIds)
            ->where('status', 'present')
            ->count();
        $totalCount = $courses->sum(fn ($course) => $course->enrollments()->count());
        $status = $sessions->pluck('status')->unique()->count() === 1
            ? $representative->status
            : 'mixed';

        return [
            'id' => $representative->id,
            'session_ids' => $sessionIds->all(),
            'session_group_key' => $representative->session_group_key,
            'course_id' => $representative->course_id,
            'course_ids' => $courseIds->all(),
            'course_name' => $representative->course?->name,
            'course_code' => $this->displayCourseCode((string) $representative->course?->code),
            'departments' => $this->sessionDepartments($courses),
            'levels' => $this->sessionLevels($courses),
            'level_labels' => $this->sessionLevels($courses)
                ->map(fn ($level) => $this->levelLabel($level))
                ->values()
                ->all(),
            'method' => $representative->method,
            'status' => $status,
            'is_open' => $status === 'open',
            'starts_at' => $representative->scheduleStartsAt(),
            'ends_at' => $representative->scheduleEndsAt(),
            'display_date' => $representative->scheduleDate(),
            'display_start_time' => $representative->scheduleStartTime(),
            'display_end_time' => $representative->scheduleEndTime(),
            'present_count' => $presentCount,
            'total_count' => $totalCount,
        ];
    }

    protected function displayCourseCode(string $code): string
    {
        return preg_replace('/-(CS|IS)[1-4](?:-\d+)?$/i', '', $code) ?: $code;
    }

    protected function sessionDepartments(Collection $courses): array
    {
        return $courses
            ->flatMap(fn ($course) => preg_split('/\s*,\s*/', (string) ($course->semester ?: $course->department ?: '')))
            ->map(fn ($value) => strtoupper(trim($value)))
            ->filter(fn ($value) => in_array($value, ['CS', 'IS'], true))
            ->unique()
            ->values()
            ->all();
    }

    protected function sessionLevels(Collection $courses): Collection
    {
        return $courses
            ->pluck('level')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->sortBy(fn ($value) => (int) $value)
            ->unique()
            ->values();
    }

    protected function levelLabel(string $level): string
    {
        return match ((string) $level) {
            '1' => 'First Year',
            '2' => 'Second Year',
            '3' => 'Third Year',
            '4' => 'Fourth Year',
            default => $level,
        };
    }
}
