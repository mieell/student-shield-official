<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

/** Ensures consistent Philippine Standard Time (Asia/Manila, UTC+8) across PHP, DB, and views. */
final class ManilaTime
{
    public const TIMEZONE = 'Asia/Manila';
    private const MYSQL_OFFSET = '+08:00';

    private static ?DateTimeZone $zone = null;
    /** @var array<int, true> */
    private static array $syncedConnections = [];

    public static function zone(): DateTimeZone
    {
        return self::$zone ??= new DateTimeZone(self::TIMEZONE);
    }

    public static function ensurePhpTimezone(): void
    {
        if (date_default_timezone_get() !== self::TIMEZONE) {
            date_default_timezone_set(self::TIMEZONE);
        }
    }

    public static function syncDatabaseSession($db): void
    {
        if (!is_object($db) || ($db->DBDriver ?? null) !== 'MySQLi') {
            return;
        }
        $connId = is_object($db->connID ?? null) ? spl_object_id($db->connID) : null;
        if ($connId !== null && isset(self::$syncedConnections[$connId])) {
            return;
        }
        try {
            $db->query("SET time_zone = '" . self::MYSQL_OFFSET . "'");
            $syncedId = is_object($db->connID ?? null) ? spl_object_id($db->connID) : spl_object_id($db);
            self::$syncedConnections[$syncedId] = true;
        } catch (\Throwable $e) {
            log_message('warning', 'Unable to sync MySQL session timezone: ' . $e->getMessage());
        }
    }

    public static function now(string $format = 'Y-m-d H:i:s', ?int $unixTimestamp = null): string
    {
        $dt = $unixTimestamp !== null
            ? (new DateTimeImmutable('@' . $unixTimestamp))->setTimezone(self::zone())
            : new DateTimeImmutable('now', self::zone());
        return $dt->format($format);
    }

    public static function parse(?string $value): ?DateTimeImmutable
    {
        $raw = trim((string) $value);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }
        try {
            // Naive DB timestamps represent Asia/Manila; explicit offsets convert cleanly to Asia/Manila.
            return (new DateTimeImmutable($raw, self::zone()))->setTimezone(self::zone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function format(?string $value, string $format = 'M d, Y g:i:s A', string $fallback = '—'): string
    {
        $dt = self::parse($value);
        return $dt ? $dt->format($format) : $fallback;
    }

    public static function unix(?string $value): ?int
    {
        $dt = self::parse($value);
        return $dt ? $dt->getTimestamp() : null;
    }

    /** Return timestamp values only for columns present on the table (safe for lean test schemas). */
    public static function stampFields($db, string $table, array $columns): array
    {
        try {
            $present = array_flip($db->getFieldNames($table));
        } catch (\Throwable $e) {
            return [];
        }
        $now = self::now();
        $stamps = [];
        foreach ($columns as $column) {
            if (isset($present[$column])) {
                $stamps[$column] = $now;
            }
        }
        return $stamps;
    }
}
