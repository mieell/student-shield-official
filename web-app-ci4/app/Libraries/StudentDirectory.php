<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class StudentDirectory
{
    public static function grade(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/\A(?:Grade\s+)?([7-9]|10)\z/i', trim($value), $match)) {
            return null;
        }
        return $match[1];
    }

    public static function search(BaseConnection $db, string $grade, string $section, string $search): array
    {
        return self::query($db, $grade, $section)
            ->select('u.id, u.name')->like('u.name', $search)
            ->orderBy('u.name')->orderBy('u.id')->limit(12)->get()->getResultArray();
    }

    public static function selected(BaseConnection $db, mixed $id, mixed $grade, mixed $section): ?array
    {
        $grade = self::grade($grade);
        if (!is_string($id) || !ctype_digit($id) || $grade === null || !is_string($section) || trim($section) === '') {
            return null;
        }
        return self::query($db, $grade, trim($section))->select('u.id, u.name')
            ->where('u.id', (int) $id)->get()->getRowArray();
    }

    private static function query(BaseConnection $db, string $grade, string $section)
    {
        return $db->table('users u')->join('students s', 's.user_id = u.id')
            ->where('u.role', 0)->where('u.is_active', 1)->where('u.access_disabled', 0)
            ->where('u.registration_status', 'approved')
            ->whereIn('s.grade_level', [$grade, 'Grade ' . $grade])
            ->where('s.section', $section);
    }
}
