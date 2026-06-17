<?php

namespace App\Support;

class AcademicMajor
{
    private const MAJORS = ['CS', 'IS'];

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = strtoupper(trim((string) $value));

        return in_array($clean, self::MAJORS, true) ? $clean : null;
    }

    public static function fromCourseValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = preg_split('/[,|]/', (string) $value) ?: [];
        $majors = [];

        foreach ($parts as $part) {
            $major = self::normalize($part);
            if ($major !== null) {
                $majors[] = $major;
            }
        }

        return array_values(array_unique($majors));
    }

    public static function matches(mixed $courseValue, mixed $studentMajor): bool
    {
        $student = self::normalize($studentMajor);

        if ($student === null) {
            return false;
        }

        return in_array($student, self::fromCourseValue($courseValue), true);
    }
}
