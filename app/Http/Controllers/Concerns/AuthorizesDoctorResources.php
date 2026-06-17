<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AttendanceSession;
use App\Models\Course;
use Illuminate\Http\Request;

trait AuthorizesDoctorResources
{
    protected function findDoctorCourseOrFail(Request $request, int|string $courseId): Course
    {
        return Course::where('id', $courseId)
            ->where('doctor_id', $request->user()->id)
            ->firstOrFail();
    }

    protected function findDoctorSessionOrFail(Request $request, int|string $sessionId): AttendanceSession
    {
        return AttendanceSession::where('id', $sessionId)
            ->whereHas('course', function ($query) use ($request) {
                $query->where('doctor_id', $request->user()->id);
            })
            ->firstOrFail();
    }
}
