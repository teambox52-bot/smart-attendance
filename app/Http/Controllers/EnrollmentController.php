<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Enrollment;
use App\Models\Course;
use App\Support\AcademicLevel;
use App\Support\AcademicMajor;

class EnrollmentController extends Controller
{
    public function enroll(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $student = $request->user();

        if ($student->role != 'student') {
            return response()->json([
                'message' => 'Only students can enroll'
            ], 403);
        }

        $course = Course::findOrFail($request->course_id);

        if (!$this->courseMatchesStudentLevel($course, $student)) {
            return response()->json([
                'message' => 'Course is not available for your current major and level'
            ], 403);
        }

        $enrollment = Enrollment::firstOrCreate([
            'user_id' => $student->id,
            'course_id' => $course->id
        ]);

        return response()->json([
            'message' => 'Enrolled successfully',
            'data' => $enrollment
        ], 201);
    }

    public function myCourses(Request $request)
    {
        if ($request->user()->role != 'student') {
            return response()->json([
                'message' => 'Only students can view my courses'
            ], 403);
        }

        $courses = Course::with('doctor')
            ->whereHas('enrollments', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->get()
            ->filter(fn (Course $course) => $this->courseMatchesStudent($course, $request->user()))
            ->values();

        return response()->json($courses);
    }

    public function availableCourses(Request $request)
    {
        if ($request->user()->role != 'student') {
            return response()->json([
                'message' => 'Only students can view available courses'
            ], 403);
        }

        $student = $request->user();

        if ($student->level === null) {
            return response()->json([]);
        }

        $enrolledCourseIds = Enrollment::where('user_id', $student->id)
            ->pluck('course_id');

        $courses = Course::with('doctor')
            ->whereNotIn('id', $enrolledCourseIds)
            ->get()
            ->filter(fn (Course $course) => $this->courseMatchesStudent($course, $student))
            ->values();

        return response()->json($courses);
    }

    private function courseMatchesStudent(Course $course, $student): bool
    {
        if ($student->level === null || $course->level === null || $student->major === null) {
            return false;
        }

        return AcademicLevel::matches($course->level, $student->level)
            && AcademicMajor::matches($course->semester, $student->major);
    }

    private function courseMatchesStudentLevel(Course $course, $student): bool
    {
        return $this->courseMatchesStudent($course, $student);
    }
}
