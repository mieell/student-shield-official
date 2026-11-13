<?php

namespace App\Libraries;

final class ScreeningExplanation
{
    public static function encode(array $assessment): string
    {
        $data = [
            'source' => in_array($assessment['source'] ?? null, ['model', 'local'], true) ? $assessment['source'] : 'unknown',
            'reasoning' => mb_substr((string) ($assessment['reasoning'] ?? ''), 0, 2500),
        ];
        return bin2hex(\Config\Services::encrypter()->encrypt(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public static function decode(?string $value): ?array
    {
        if (!$value || !ctype_xdigit($value) || strlen($value) % 2 !== 0) return null;
        try {
            $data = json_decode(\Config\Services::encrypter()->decrypt(hex2bin($value)), true, 8, JSON_THROW_ON_ERROR);
            return is_array($data) && is_string($data['reasoning'] ?? null)
                ? ['source' => in_array($data['source'] ?? null, ['model', 'local'], true) ? $data['source'] : 'unknown', 'reasoning' => $data['reasoning']]
                : null;
        } catch (\Throwable $error) {
            return null;
        }
    }
}
