<?php

namespace App\Exports;

use App\Models\Course;
use App\Models\Enrollment;
use Maatwebsite\Excel\Concerns\FromArray;

class CourseReportExport implements FromArray
{
    protected $courseId;

    public function __construct($courseId)
    {
        $this->courseId = $courseId;
    }

    public function array(): array
    {
        $course = Course::find($this->courseId);

        if (!$course) {
            return [];
        }

        $courses = $this->logicalCourseRows($course);
        $courseIds = $courses->pluck('id');
        $sessionIds = $courses->flatMap(fn (Course $item) => $item->sessions()->pluck('id'));

        $students = Enrollment::with('user')
            ->whereIn('course_id', $courseIds)
            ->get()
            ->unique('user_id')
            ->sortBy(fn ($enrollment) => $enrollment->user?->university_code ?? '')
            ->values();

        $rows = [
            ['Course Name', $course->name],
            ['Course Code', $this->displayCourseCode($course->code)],
            ['Total sessions', $sessionIds->count()],
            [],
            ['University Code', 'Full Name'],
        ];

        foreach ($students as $enrollment) {
            $student = $enrollment->user;

            if (!$student) {
                continue;
            }

            $rows[] = [
                $student->university_code,
                $student->name,
            ];
        }

        return $rows;
    }

    private function logicalCourseRows(Course $course)
    {
        $baseCode = $this->displayCourseCode($course->code);

        return Course::with('sessions')
            ->where('doctor_id', $course->doctor_id)
            ->where('name', $course->name)
            ->where(function ($query) use ($baseCode) {
                $query->where('code', $baseCode)
                    ->orWhere('code', 'like', "{$baseCode}-%");
            })
            ->orderBy('id')
            ->get();
    }

    private function displayCourseCode(string $code): string
    {
        return preg_replace('/-(CS|IS)[1-4](?:-\d+)?$/i', '', $code) ?: $code;
    }
}
