<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class GuardianConsent
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function phone(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $phone = preg_replace('/[\s\p{Z}\p{Cf}\p{Pd}\x{2212}\x{2043}()\-]+/u', '', $value);
        if (!is_string($phone) || !preg_match('/\A(?:09|\+?639)\d{9}\z/D', $phone)) return null;
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
        $clean = strtoupper((string) preg_replace('/[\s\p{Z}\p{Cf}\p{Pd}\x{2212}\x{2043}\-]+/u', '', trim($value)));
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
        if ($prior['sms_state'] !== 'Queued') {
            return true;
        }
        return strtotime($prior['requested_at']) <= $now - 300;
    }

    public static function lockStudent(BaseConnection $db, int $id): ?array
    {
        $lock = $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
        return $db->query("SELECT id, name FROM users WHERE id = ? AND role = 0 AND registration_status = 'pending'" . $lock, [$id])
            ->getRowArray();
    }

    private static function tokenFile(int $userId): string
    {
        return WRITEPATH . 'consent-tokens' . DIRECTORY_SEPARATOR . $userId . '.json';
    }

    /**
     * Retain active SMS code hashes for the same student and verified phone so
     * delayed carrier delivery or generating a backup link does not break an
     * unexpired SMS code that was already sent. Changing phone purges all prior
     * tokens; generating a new manual link replaces older manual links.
     */
    public static function syncTokenStore(
        int $userId,
        string $phone,
        string $newHash,
        string $newExpiresAt,
        string $newKind,
        ?array $prior = null,
        ?int $now = null
    ): void {
        if ($userId <= 0) {
            return;
        }
        $now ??= time();
        $dir = WRITEPATH . 'consent-tokens';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $file = @fopen(self::tokenFile($userId), 'c+');
        if (!$file || !flock($file, LOCK_EX)) {
            if ($file) {
                fclose($file);
            }
            return;
        }
        try {
            rewind($file);
            $raw = stream_get_contents($file);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $entries = is_array($decoded) ? $decoded : [];

            $kept = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $h = (string) ($entry['hash'] ?? '');
                $p = (string) ($entry['phone'] ?? '');
                $k = (string) ($entry['kind'] ?? '');
                $exp = (string) ($entry['expires_at'] ?? '');
                if ($h === '' || $p !== $phone || !in_array($k, ['sms', 'manual'], true)) {
                    continue;
                }
                $expTs = strtotime($exp);
                if ($expTs === false || $expTs <= $now) {
                    continue;
                }
                if ($newKind === 'manual' && $k === 'manual') {
                    continue;
                }
                $kept[$h] = ['hash' => $h, 'phone' => $phone, 'kind' => $k, 'expires_at' => $exp];
            }

            if (is_array($prior) && empty($prior['response']) && !empty($prior['token_hash'])
                && self::phone($prior['phone'] ?? null) === $phone
                && ($prior['sms_state'] ?? '') !== 'Manual') {
                $priorExp = (string) ($prior['expires_at'] ?? '');
                $priorExpTs = strtotime($priorExp);
                $priorHash = (string) $prior['token_hash'];
                if ($priorExpTs !== false && $priorExpTs > $now) {
                    $kept[$priorHash] = [
                        'hash' => $priorHash,
                        'phone' => $phone,
                        'kind' => 'sms',
                        'expires_at' => $priorExp,
                    ];
                }
            }

            unset($kept[$newHash]);
            $kept[$newHash] = [
                'hash' => $newHash,
                'phone' => $phone,
                'kind' => $newKind === 'manual' ? 'manual' : 'sms',
                'expires_at' => $newExpiresAt,
            ];
            $values = array_values($kept);
            if (count($values) > 5) {
                $values = array_slice($values, -5);
            }

            rewind($file);
            if (ftruncate($file, 0)) {
                fwrite($file, json_encode($values, JSON_THROW_ON_ERROR));
                fflush($file);
            }
        } catch (\Throwable $e) {
            // DB token_hash retains the latest token if local storage is unavailable.
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public static function hasActiveSmsToken(int $userId, string $phone, string $tokenHash, int $now): bool
    {
        if ($userId <= 0 || $tokenHash === '') {
            return false;
        }
        $path = self::tokenFile($userId);
        if (!is_file($path)) {
            return false;
        }
        $file = @fopen($path, 'rb');
        if (!$file || !flock($file, LOCK_SH)) {
            if ($file) {
                fclose($file);
            }
            return false;
        }
        try {
            $raw = stream_get_contents($file);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($decoded)) {
                return false;
            }
            foreach ($decoded as $entry) {
                if (!is_array($entry) || ($entry['kind'] ?? '') !== 'sms' || ($entry['phone'] ?? '') !== $phone) {
                    continue;
                }
                $h = (string) ($entry['hash'] ?? '');
                $expTs = strtotime((string) ($entry['expires_at'] ?? ''));
                if ($h !== '' && $expTs !== false && $expTs > $now && hash_equals($h, $tokenHash)) {
                    return true;
                }
            }
            return false;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public static function clearTokens(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $path = self::tokenFile($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
