<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class GuardianConsent
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function phone(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $phone = preg_replace('/[\s()\-]/', '', $value);
        if (!preg_match('/\A(?:09|\+?639)\d{9}\z/D', $phone)) return null;
        return str_starts_with($phone, '09') ? '+63' . substr($phone, 1) : '+' . ltrim($phone, '+');
    }

    public static function generateCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    public static function normalizeCode(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $clean = strtoupper((string) preg_replace('/[\s\-]+/', '', trim($value)));
        return preg_match('/\A[' . self::CODE_ALPHABET . ']{8}\z/D', $clean) ? $clean : null;
    }

    public static function formatCode(string $canonical): string
    {
        return substr($canonical, 0, 4) . '-' . substr($canonical, 4, 4);
    }

    public static function smsHost(?string $override = null, ?string $siteUrl = null): ?string
    {
        $raw = trim((string) $override);
        if ($raw === '') {
            $raw = trim((string) ($siteUrl ?? site_url()));
        }
        if ($raw === '') return null;

        $normalized = preg_replace('/\s*\[\s*dot\s*\]\s*/i', '.', $raw) ?? $raw;
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\//', $normalized)) {
            $normalized = 'https://' . ltrim($normalized, '/');
        }
        $parts = parse_url($normalized);
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        if ($host === '' || !preg_match('/\A[A-Za-z0-9.-]+\z/D', $host) || str_contains($host, '..')) {
            return null;
        }
        $port = is_array($parts) && isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return str_replace('.', ' [dot] ', $host) . $port;
    }

    public static function formatSmsMessage(string $smsHost, string $displayCode): string
    {
        return 'StudentShield: Visit ' . $smsHost . ' in your browser, open Guardian Consent, and enter code ' . $displayCode;
    }

    public static function fitsSingleSms(string $message): bool
    {
        if ($message === '' || !preg_match('/\A[A-Za-z0-9 .,:;!?()\[\]\/+\-]+\z/D', $message)) {
            return false;
        }
        $septets = strlen($message) + substr_count($message, '[') + substr_count($message, ']');

        return $septets <= 160;
    }

    public static function canSend(?array $prior, int $now): bool
    {
        if (!$prior) return true;
        if (!empty($prior['response'])) return false;
        if (strtotime($prior['expires_at']) <= $now) return true;
        if (in_array($prior['sms_state'], ['Unknown', 'Manual'], true)) {
            return false;
        }
        return strtotime($prior['requested_at']) <= $now - 300;
    }

    public static function lockStudent(BaseConnection $db, int $id): ?array
    {
        $lock = $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
        return $db->query("SELECT id, name FROM users WHERE id = ? AND role = 0 AND registration_status = 'pending'" . $lock, [$id])
            ->getRowArray();
    }
}
