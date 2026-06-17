<?php

namespace App\Support;

class AcademicLevel
{
    private const LABELS = [
        '1' => 'First Year',
        '2' => 'Second Year',
        '3' => 'Third Year',
        '4' => 'Fourth Year',
    ];

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = trim((string) $value);

        if (isset(self::LABELS[$clean])) {
            return $clean;
        }

        $lower = strtolower($clean);
        foreach (self::LABELS as $number => $label) {
            if ($lower === strtolower($label)) {
                return $number;
            }
        }

        return $clean;
    }

    public static function fromCourseValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = preg_split('/[,|]/', (string) $value) ?: [];
        $levels = [];

        foreach ($parts as $part) {
            $level = self::normalize($part);
            if ($level !== null) {
                $levels[] = $level;
            }
        }

        return array_values(array_unique($levels));
    }

    public static function matchingValues(mixed $value): array
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return [];
        }

        $values = [$normalized];

        if (isset(self::LABELS[$normalized])) {
            $values[] = self::LABELS[$normalized];
        }

        return array_values(array_unique($values));
    }

    public static function matches(mixed $courseValue, mixed $studentLevel): bool
    {
        $student = self::normalize($studentLevel);

        if ($student === null) {
            return false;
        }

        return in_array($student, self::fromCourseValue($courseValue), true);
    }
}
