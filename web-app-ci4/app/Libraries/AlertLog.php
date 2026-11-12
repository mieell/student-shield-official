<?php

namespace App\Libraries;

/** Atomic storage helper for the Alert Delivery Monitor log (logs/alerts.json). */
final class AlertLog
{
    private const MAX_ENTRIES = 500;

    public static function path(): string
    {
        return WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'alerts.json';
    }

    public static function record(string $type, string $recipient, string $message, array $status = []): void
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return;
        }

        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            rewind($fp);
            $raw = stream_get_contents($fp);
            $alerts = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($alerts)) {
                $alerts = [];
            }

            $messageId = is_string($status['message_id'] ?? null) ? trim($status['message_id']) : '';
            if ($messageId !== '') {
                foreach ($alerts as $existing) {
                    if (($existing['type'] ?? null) === $type
                        && ($existing['recipient'] ?? null) === $recipient
                        && ($existing['message_id'] ?? null) === $messageId) {
                        return;
                    }
                }
            }

            array_unshift($alerts, array_merge([
                'type' => $type,
                'recipient' => $recipient,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
            ], $status));

            if (count($alerts) > self::MAX_ENTRIES) {
                $alerts = array_slice($alerts, 0, self::MAX_ENTRIES);
            }

            $encoded = json_encode($alerts, JSON_PRETTY_PRINT);
            if ($encoded === false) {
                return;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
