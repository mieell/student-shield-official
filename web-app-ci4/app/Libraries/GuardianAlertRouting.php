<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class GuardianAlertRouting
{
    public static function settings(BaseConnection $db, int $userId): ?array
    {
        return self::settingsForUsers($db, [$userId])[$userId] ?? null;
    }

    /**
     * Batch-load guardian safety settings using single whereIn queries (avoids N+1 lookups).
     * Missing optional table defaults safely to unflagged parent routing; DB query failures fail closed (null).
     *
     * @param list<int|string> $userIds
     * @return array<int, array<string, mixed>|null>
     */
    public static function settingsForUsers(BaseConnection $db, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        try {
            $hasRoutes = $db->tableExists('guardian_alert_routes');
            if (!$hasRoutes) {
                log_message('warning', 'guardian_alert_routes table missing; defaulting to standard guardian routing.');
            }

            $routesById = [];
            if ($hasRoutes) {
                $routeRows = $db->table('guardian_alert_routes')->whereIn('user_id', $ids)->get()->getResultArray();
                foreach ($routeRows as $row) {
                    $routesById[(int) $row['user_id']] = $row;
                }
            }

            $consentsById = [];
            if ($db->tableExists('guardian_consents')) {
                $consentRows = $db->table('guardian_consents')
                    ->select('user_id, phone')
                    ->whereIn('user_id', $ids)
                    ->get()->getResultArray();
                foreach ($consentRows as $row) {
                    $consentsById[(int) $row['user_id']] = $row['phone'] ?? null;
                }
            }

            $result = [];
            foreach ($ids as $id) {
                $settings = $routesById[$id] ?? self::defaultSettings();
                $settings['verified_guardian_phone'] = $consentsById[$id] ?? null;
                $result[$id] = $settings;
            }
            return $result;
        } catch (\Throwable $error) {
            log_message('error', 'Guardian alert routing could not be read. Counselor outreach is required.');
            return array_fill_keys($ids, null);
        }
    }

    /** Default unflagged routing structure when no unsafe guardian record is configured. */
    private static function defaultSettings(): array
    {
        return [
            'unsafe_guardian' => 0,
            'alternate_name' => '',
            'alternate_phone' => '',
            'alternate_email' => '',
            'verified_by' => null,
            'verified_at' => null,
        ];
    }

    public static function resolve(array $student, ?array $settings): array
    {
        if ($settings === null) {
            return ['phone' => null, 'email' => null, 'blocked' => true,
                'note' => 'Guardian alert routing is unavailable. Arrange safe outreach immediately.'];
        }
        if (!empty($settings['unsafe_guardian'])) {
            $approved = !empty($settings['verified_by']) && !empty($settings['verified_at']);
            $phone = $approved ? GuardianConsent::phone($settings['alternate_phone'] ?? null) : null;
            $email = $approved && filter_var($settings['alternate_email'] ?? '', FILTER_VALIDATE_EMAIL)
                ? $settings['alternate_email'] : null;
            if ($phone && in_array($phone, [
                GuardianConsent::phone($student['parent_phone'] ?? null),
                GuardianConsent::phone($settings['verified_guardian_phone'] ?? null),
            ], true)) $phone = null;
            if ($email && strcasecmp($email, (string) ($student['parent_email'] ?? '')) === 0) $email = null;
            return ['phone' => $phone, 'email' => $email, 'blocked' => !$phone && !$email,
                'note' => $phone || $email
                    ? 'Guardian contact is marked unsafe. Outreach is routed to the school-approved alternate contact.'
                    : 'Guardian contact is marked unsafe and no approved alternate is available. Arrange safe outreach immediately.'];
        }
        $phone = GuardianConsent::phone($settings['verified_guardian_phone'] ?? null)
            ?? GuardianConsent::phone($student['parent_phone'] ?? null);
        $email = filter_var($student['parent_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $student['parent_email'] : null;
        return ['phone' => $phone, 'email' => $email, 'blocked' => !$phone && !$email,
            'note' => $phone || $email ? '' : 'No guardian contact is available. Arrange safe outreach immediately.'];
    }

    public static function validate(array $input): array
    {
        foreach (['unsafe_guardian', 'alternate_name', 'alternate_phone', 'alternate_email', 'school_approved'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                throw new \InvalidArgumentException('The contact settings contain an invalid field. Reopen the student profile and try again.');
            }
        }
        if (!in_array($input['unsafe_guardian'] ?? '', ['', '0', '1'], true)
            || !in_array($input['school_approved'] ?? '', ['', '0', '1'], true)) {
            throw new \InvalidArgumentException('Choose a valid guardian safety setting.');
        }
        $unsafe = ($input['unsafe_guardian'] ?? '') === '1';
        $name = trim(is_string($input['alternate_name'] ?? null) ? $input['alternate_name'] : '');
        $phoneInput = trim(is_string($input['alternate_phone'] ?? null) ? $input['alternate_phone'] : '');
        $email = trim(is_string($input['alternate_email'] ?? null) ? $input['alternate_email'] : '');
        if (!$unsafe) return ['unsafe_guardian' => 0, 'alternate_name' => null,
            'alternate_phone' => null, 'alternate_email' => null];
        if ($name === '' && $phoneInput === '' && $email === '') {
            return ['unsafe_guardian' => 1, 'alternate_name' => null,
                'alternate_phone' => null, 'alternate_email' => null];
        }
        $phone = GuardianConsent::phone($phoneInput);
        if ($name === '' || mb_strlen($name) > 120 || ($phoneInput !== '' && !$phone)
            || ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)))
            || (!$phone && $email === '') || ($input['school_approved'] ?? '') !== '1') {
            throw new \InvalidArgumentException('Enter the alternate contact name and a valid mobile number or email, then confirm school approval. Leave all alternate fields blank to flag the guardian as unsafe while arranging a safe contact.');
        }
        return ['unsafe_guardian' => 1, 'alternate_name' => $name,
            'alternate_phone' => $phone, 'alternate_email' => $email ?: null];
    }
}
