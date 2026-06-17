<?php

namespace App\Exports;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\AttendanceRecord;
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
        $rows = [];

        $course = Course::find($this->courseId);

        $rows[] = ['Course Name', $course->name];
        $rows[] = ['Course Code', $course->code];
        $rows[] = ['Semester', $course->semester];
        $rows[] = [];

        $rows[] = [
            'University code',
            'Full name',
            'Status'
        ];

        $students = Enrollment::with('user')
            ->where('course_id', $this->courseId)
            ->get();

        $presentStudents = [];
        $absentStudents = [];

        foreach ($students as $enrollment) {
            $student = $enrollment->user;

            $present = AttendanceRecord::where('user_id', $student->id)
                ->whereHas('session', function ($q) {
                    $q->where('course_id', $this->courseId);
                })
                ->exists();

            $studentRow = [
                $student->university_code,
                $student->name,
                $present ? 'Present' : 'Absent'
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
