<?php
namespace App\Libraries;

final class RoleAccess
{
    public const NAMES = [0 => 'Student', 1 => 'Counselor', 2 => 'Super Admin', 3 => 'Principal'];

    public static function home(int $role): string
    {
        return [0 => '/student/dashboard', 1 => '/admin/dashboard', 2 => '/admin/users', 3 => '/principal/dashboard'][$role] ?? '/login';
    }

    public static function enabled(array $user): bool
    {
        return isset(self::NAMES[(int) $user['role']]) && empty($user['access_disabled'])
            && ((int) $user['role'] !== 0 || (!empty($user['is_active']) && ($user['registration_status'] ?? 'approved') === 'approved'));
    }

    public static function allows(int $role, string $path): bool
    {
        $path = trim($path, '/');
        if (in_array($path, ['staff/profile', 'staff/settings'], true)) return in_array($role, [1, 2, 3], true);
        if ($path === 'admin/dashboard') return in_array($role, [1, 2, 3], true); // Dispatches to the role's home.
        if (preg_match('#^admin/(users(?:/(?:create|(?:update|delete|unlock|access)/[0-9]+))?|audit-logs)$#', $path)) return $role === 2;
        if (preg_match('#^principal/(dashboard|api/analytics|reports/export)$#', $path)) return $role === 3;
        if (str_starts_with($path, 'counselor/')) return $role === 1 && self::allows($role, 'admin/' . substr($path, 10));
        $counselor = [
            '#^admin/(vents|incidents|sessions|alerts|analytics|pending-registrations)$#',
            '#^admin/api/(pending-meetings|pending-registrations|vents|incidents|alerts|student-profile/[0-9]+|case-summary/[0-9]+(?:/meeting/pending)?)$#',
            '#^admin/evidence/[^/]+$#',
            '#^admin/vent/((resolve|delete|restore)/[0-9]+|(delete|restore)-multiple)$#',
            '#^admin/incident/((resolve|verify|reject|undo|delete|restore)/[0-9]+|(delete|restore)-multiple)$#',
            '#^admin/case-summary/([0-9]+(?:/(note|status|meeting/(start|cancel)))?|note/delete/[0-9]+)$#',
            '#^admin/(approve-student|reject-student)/[0-9]+$#',
        ];
        foreach ($counselor as $pattern) if (preg_match($pattern, $path)) return $role === 1;
        if (preg_match('#^student/(dashboard|vent(?:/submit)?|api/meeting/status|meeting/(request|cancel-request))$#', $path)
            || in_array($path, ['report-bullying', 'report-bullying/submit', 'report/bullying', 'report/bullying/submit'], true)) return $role === 0;
        if (preg_match('#^meeting/join/[0-9]+$#', $path)) return in_array($role, [0, 1], true);
        return false;
    }
}
