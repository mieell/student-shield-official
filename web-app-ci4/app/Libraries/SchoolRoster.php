<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class SchoolRoster
{
    public static function grade(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        return preg_match('/\A(?:Grade\s+)?(7|8|9|10)\z/i', trim($value), $matches) ? $matches[1] : null;
    }

    public static function sections(?BaseConnection $db = null): array
    {
        $db ??= \Config\Database::connect();
        $rows = [];
        foreach (config(\Config\SchoolRoster::class)->sectionsByGrade as $grade => $sections) {
            foreach ($sections as $section) $rows[] = ['grade_level' => (string) $grade, 'section' => $section];
        }
        $rows = array_merge($rows, $db->table('students')->select('grade_level, section')->distinct()->get()->getResultArray());
        return self::fromRows($rows);
    }

    public static function fromRows(array $rows): array
    {
        $result = ['7' => [], '8' => [], '9' => [], '10' => []];
        foreach ($rows as $row) {
            $grade = self::grade($row['grade_level'] ?? null);
            $section = $row['section'] ?? null;
            if ($grade !== null && is_string($section) && trim($section) !== '' && mb_strlen(trim($section)) <= 80) {
                $result[$grade][] = trim($section);
            }
        }
        foreach ($result as &$sections) {
            $sections = array_values(array_unique($sections));
            natcasesort($sections);
            $sections = array_values($sections);
        }
        return $result;
    }

    public static function validPlacement(mixed $grade, mixed $section, array $sections): bool
    {
        return is_string($grade) && in_array($grade, ['7', '8', '9', '10'], true)
            && is_string($section) && in_array($section, $sections[$grade] ?? [], true);
    }
}
