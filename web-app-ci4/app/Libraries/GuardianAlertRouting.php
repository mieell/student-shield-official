<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

final class GuardianAlertRouting
{
    public static function settings(BaseConnection $db, int $userId): ?array
    {
        try {
            if (!$db->tableExists('guardian_alert_routes')) return null;
            $settings = $db->table('guardian_alert_routes')->where('user_id', $userId)->get()->getRowArray() ?? [
                'unsafe_guardian' => 0, 'alternate_name' => '', 'alternate_phone' => '',
                'alternate_email' => '', 'verified_by' => null, 'verified_at' => null,
            ];
            $verifiedContact = $db->tableExists('guardian_consents')
                ? $db->table('guardian_consents')->select('phone')->where('user_id', $userId)->get()->getRowArray() : null;
            $settings['verified_guardian_phone'] = $verifiedContact['phone'] ?? null;
            return $settings;
        } catch (\Throwable $error) {
            log_message('error', 'Guardian alert routing could not be read. Counselor outreach is required.');
            return null;
        }
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
