<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class GuardianConsent
{
    public static function phone(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $phone = preg_replace('/[\s()\-]/', '', $value);
        if (!preg_match('/\A(?:09|\+?639)\d{9}\z/D', $phone)) return null;
        return str_starts_with($phone, '09') ? '+63' . substr($phone, 1) : '+' . ltrim($phone, '+');
    }

    public static function canSend(?array $prior, int $now): bool
    {
        if (!$prior) return true;
        if (!empty($prior['response'])) return false;
        if (strtotime($prior['expires_at']) <= $now) return true;
        return !in_array($prior['sms_state'], ['Queued', 'Unknown'], true)
            && strtotime($prior['requested_at']) <= $now - 300;
    }

    public static function lockStudent(BaseConnection $db, int $id): ?array
    {
        $lock = $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
        return $db->query("SELECT id FROM users WHERE id = ? AND role = 0 AND registration_status = 'pending'" . $lock, [$id])
            ->getRowArray();
    }
}
