<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Support\AcademicLevel;
use App\Support\AcademicMajor;

class CourseController extends Controller
{
    use AuthorizesDoctorResources;

    // عرض كورسات الدكتور الحالي فقط
    public function getAll(Request $request)
    {
        if ($request->user()->role !== 'doctor') {
            return response()->json([
                'message' => 'Only doctors can view managed courses'
            ], 403);
        }

        $courses = Course::withCount(['enrollments', 'sessions'])
            ->where('doctor_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Course $course) => $this->coursePayload($course))
            ->values();

        return response()->json($courses);
    }

    // إنشاء كورس بواسطة دكتور
    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'code' => 'required',
            'semester' => 'nullable',
            'level' => 'nullable',
            'departments' => 'nullable|array',
            'departments.*' => 'in:CS,IS',
            'levels' => 'nullable|array',
            'levels.*' => 'string',
        ]);

        if ($request->user()->role != 'doctor') {
            return response()->json([
                'message' => 'Only doctors can create courses'
            ], 403);
        }

        $departments = $this->normalizeDepartments($request->input('departments', []), $request->semester);
        $levels = $this->normalizeLevels($request->input('levels', []), $request->level);

        if (empty($departments) || empty($levels)) {
            return response()->json([
                'message' => 'Course departments and levels are required',
            ], 422);
        }

        $combinations = [];

        foreach ($departments as $department) {
            foreach ($levels as $level) {
                $combinations[] = [$department, $level];
            }
        }

        $courses = collect($combinations)->map(function (array $combination, int $index) use ($request, $combinations) {
            [$department, $level] = $combination;
            $code = $this->courseCodeForAudience($request->code, $department, $level, count($combinations) > 1);

            return Course::create([
                'name' => $request->name,
                'code' => $code,
                'doctor_id' => $request->user()->id,
                'semester' => $department,
                'level' => $level,
            ]);
        })->values();

        $course = $courses->first();

        return response()->json([
            'message' => 'Course created successfully',
            'course' => $course,
            'courses' => $courses,
        ], 201);
    }

    public function students(Request $request, $id)
    {
        $course = $this->findDoctorCourseOrFail($request, $id)->load('enrollments.user');
        $sessionIds = $course->sessions()->pluck('id');
        $totalSessions = $sessionIds->count();

        $students = $course->enrollments->map(function ($enrollment) use ($sessionIds, $totalSessions) {
            $student = $enrollment->user;
            $presentCount = $totalSessions > 0
                ? AttendanceRecord::where('user_id', $student->id)
                    ->whereIn('attendance_session_id', $sessionIds)
                    ->where('status', 'present')
                    ->count()
                : 0;

            return [
                'id' => $student->id,
                'full_name' => $student->name,
                'name' => $student->name,
                'email' => $student->email,
                'university_code' => $student->university_code,
                'university_id' => $student->university_code,
                'major' => $student->major,
                'level' => $student->level,
                'face_enrolled' => !empty($student->face_token),
                'attendance_rate' => $totalSessions > 0 ? round(($presentCount / $totalSessions) * 100, 2) : 0,
            ];
        })->values();

        return response()->json([
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
                'code' => $course->code,
                'semester' => $course->semester,
                'level' => $course->level,
                'department' => $course->semester,
                'enrolled_count' => $students->count(),
            ],
            'students' => $students,
        ]);
    }

    private function coursePayload(Course $course): array
    {
        $sessionIds = $course->sessions()->pluck('id');
        $totalStudents = (int) ($course->enrollments_count ?? $course->enrollments()->count());
        $sessionCount = (int) ($course->sessions_count ?? $sessionIds->count());
        $possibleAttendances = $totalStudents * $sessionCount;
        $presentCount = $possibleAttendances > 0
            ? AttendanceRecord::whereIn('attendance_session_id', $sessionIds)
                ->where('status', 'present')
                ->count()
            : 0;

        return [
            'id' => $course->id,
            'name' => $course->name,
            'code' => $course->code,
            'semester' => $course->semester,
            'level' => $course->level,
            'department' => $course->semester,
            'departments' => AcademicMajor::fromCourseValue($course->semester),
            'levels' => AcademicLevel::fromCourseValue($course->level),
            'enrolled_count' => $totalStudents,
            'enrollments_count' => $totalStudents,
            'sessions_count' => $sessionCount,
            'attendance_rate' => $possibleAttendances > 0 ? round(($presentCount / $possibleAttendances) * 100, 2) : 0,
        ];
    }

    private function normalizeDepartments(array $departments, mixed $fallback): array
    {
        $values = $departments ?: AcademicMajor::fromCourseValue($fallback);
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($value) => AcademicMajor::normalize($value),
            $values
        ))));

        return $normalized;
    }

    private function normalizeLevels(array $levels, mixed $fallback): array
    {
        $values = $levels ?: AcademicLevel::fromCourseValue($fallback);
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($value) => AcademicLevel::normalize($value),
            $values
        ))));

        return $normalized;
    }

    private function courseCodeForAudience(string $baseCode, string $department, string $level, bool $needsSuffix): string
    {
        $base = strtoupper(trim($baseCode));
        $code = $needsSuffix ? "{$base}-{$department}{$level}" : $base;

        if (!Course::where('code', $code)->exists()) {
            return $code;
        }

        $suffix = 2;
        while (Course::where('code', "{$code}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$code}-{$suffix}";
    }
}
