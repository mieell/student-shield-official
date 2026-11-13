<?php

namespace App\Controllers;

use App\Libraries\GuardianConsent;
use App\Libraries\SmsGateway;
use Config\Database;

class GuardianConsentController extends BaseController
{
    public function send(int $id)
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        $back = redirect()->to(site_url('admin/pending-registrations'));
        $manual = $this->request->getPost('delivery') === 'manual';
        $useVerified = $manual && $this->request->getPost('use_verified') === '1';
        $phone = GuardianConsent::phone($this->request->getPost('verified_phone'));
        $verifiedName = $this->request->getPost('verified_name');
        $notice = trim((string) env('GUARDIAN_CONSENT_NOTICE', ''));
        if (!$useVerified && ($phone === null || !is_string($verifiedName) || trim($verifiedName) === '' || mb_strlen(trim($verifiedName)) > 120
            || $this->request->getPost('checked_records') !== '1')) {
            return $back->with('error', 'Confirm the guardian and mobile number against current school records first.');
        }
        if ($notice === '' || mb_strlen($notice) > 10000 || (!$manual && !trim((string) env('IPROG_SMS_API_TOKEN', '')))) {
            return $back->with('error', 'Set up the school-approved guardian notice and SMS service before sending consent links.');
        }

        $baseUrl = site_url();
        $url = null;
        $message = null;
        if ($manual) {
            $token = bin2hex(random_bytes(16));
            $hash = hash('sha256', $token);
            $url = site_url('guardian-consent/' . $token);
            if (ENVIRONMENT === 'production' && !str_starts_with($url, 'https://')) {
                return $back->with('error', 'Configure a public HTTPS site address that fits in the consent SMS.');
            }
        } else {
            $smsHostOverride = trim((string) env('GUARDIAN_CONSENT_SMS_HOST', ''));
            $smsHost = GuardianConsent::smsHost($smsHostOverride !== '' ? $smsHostOverride : null, $baseUrl);
            $insecureProd = ENVIRONMENT === 'production' && (
                ($smsHostOverride === '' && !str_starts_with($baseUrl, 'https://'))
                || ($smsHostOverride !== '' && str_starts_with(strtolower($smsHostOverride), 'http://'))
            );
            if ($smsHost === null || $insecureProd) {
                return $back->with('error', 'Configure a public HTTPS site address that fits in the consent SMS.');
            }
            $displayCode = GuardianConsent::generateCode();
            $canonicalCode = GuardianConsent::normalizeCode($displayCode);
            $hash = hash('sha256', (string) $canonicalCode);
            $message = GuardianConsent::formatSmsMessage($smsHost, $displayCode);
            if (!GuardianConsent::fitsSingleSms($message)) {
                return $back->with('error', 'Configure a public HTTPS site address that fits in the consent SMS.');
            }
        }

        $db = Database::connect();
        $db->transBegin();
        try {
            $user = GuardianConsent::lockStudent($db, $id);
            $consents = $db->table('guardian_consents');
            $prior = $consents->where('user_id', $id)->get()->getRowArray();
            if (!$user || ($manual ? !empty($prior['response']) : !GuardianConsent::canSend($prior, time()))) {
                $db->transRollback();
                return $back->with('error', 'A consent request is already active or the registration has been decided.');
            }
            if ($useVerified) {
                $phone = GuardianConsent::phone($prior['phone'] ?? null);
                $verifiedName = $prior['verified_name'] ?? '';
                if ($phone === null || trim($verifiedName) === '') {
                    $db->transRollback();
                    return $back->with('error', 'Verify the guardian contact first.');
                }
            }
            $data = [
                'user_id' => $id, 'verified_by' => (int) session()->get('user_id'), 'phone' => $phone,
                'verified_name' => trim($verifiedName),
                'token_hash' => $hash, 'notice' => $notice, 'requested_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + 72 * 3600), 'sms_state' => $manual ? 'Manual' : 'Unknown',
                'response' => null, 'guardian_name' => null, 'responded_at' => null,
            ];
            $db->table('guardian_consents')->upsert($data);
            if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Consent write failed');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Guardian consent request could not be saved.');
            return $back->with('error', 'The consent request could not be saved. Check that the database update is installed.');
        }

        $this->logAudit('GUARDIAN_CONTACT_VERIFIED', 'user', $id);
        if ($manual) {
            $this->logAudit('GUARDIAN_CONSENT_LINK_GENERATED', 'user', $id);
            return $back
                ->with('manual_consent_link', $url)
                ->with('manual_consent_meta', [
                    'student_name' => (string) ($user['name'] ?? ''),
                    'guardian_name' => trim((string) $verifiedName),
                    'phone' => (string) $phone,
                    'expires_at' => date('M j, Y g:i A', strtotime($data['expires_at'])),
                ]);
        }
        $smsResult = $this->smsGateway()->send($phone, (string) $message, 'guardian-consent:' . $hash);
        $state = $smsResult['state'];
        // Redact the verification code so counselor-visible alert logs cannot expose consent credentials.
        \App\Libraries\AlertLog::record(
            'SMS (Guardian Consent)',
            $phone,
            'StudentShield: Guardian registration consent SMS dispatched (verification code redacted).',
            $smsResult
        );
        $db->table('guardian_consents')->where('user_id', $id)->where('token_hash', $hash)->update(['sms_state' => $state]);
        return $back->with($state === 'Queued' ? 'success' : 'error', match ($state) {
            'Queued' => 'The carrier accepted the consent SMS for delivery. Approval unlocks once the guardian responds (you can resend after 5 minutes or generate a link).',
            'Unknown' => 'SMS delivery is uncertain. Follow up with the verified guardian before sending another code.',
            default => 'The SMS was not queued. Check the number or SMS service and try again in a few minutes.',
        });
    }

    protected function smsGateway(): SmsGateway
    {
        return new SmsGateway();
    }

    public function index()
    {
        return $this->page('', 200, null, null, true);
    }

    public function lookup()
    {
        $rawPhone = (string) ($this->request->getPost('guardian_phone') ?? '');
        $rawCode = (string) ($this->request->getPost('consent_code') ?? '');
        $phone = GuardianConsent::phone($rawPhone);
        $canonicalCode = GuardianConsent::normalizeCode($rawCode);
        if ($phone === null || $canonicalCode === null) {
            return $this->page(
                'Enter the verified guardian mobile number and 8-character consent code from your SMS.',
                422,
                null,
                null,
                true,
                $rawPhone,
                $rawCode
            );
        }
        $consent = $this->activeCodeRequest($phone, $canonicalCode);
        if (!$consent) {
            return $this->page(
                'No active consent request matched that mobile number and consent code. Check both details or contact the school guidance office.',
                404,
                null,
                null,
                true,
                $rawPhone,
                $rawCode
            );
        }

        return $this->page(
            '',
            200,
            $consent['notice'],
            null,
            false,
            $phone,
            GuardianConsent::formatCode($canonicalCode)
        );
    }

    public function form(string $token)
    {
        $consent = $this->activeRequest($token);
        if (!$consent) return $this->page('This link is unavailable. Contact the school guidance office for help.', 404);
        return $this->page('', 200, $consent['notice'], $token);
    }

    public function respond(?string $token = null)
    {
        $lookupPhone = null;
        $lookupCode = null;
        if ($token !== null) {
            $consent = $this->activeRequest($token);
            if (!$consent) return $this->page('This link is unavailable. Contact the school guidance office for help.', 404);
            $tokenHash = hash('sha256', $token);
        } else {
            $rawPhone = (string) ($this->request->getPost('guardian_phone') ?? '');
            $rawCode = (string) ($this->request->getPost('consent_code') ?? '');
            $lookupPhone = GuardianConsent::phone($rawPhone);
            $canonicalCode = GuardianConsent::normalizeCode($rawCode);
            if ($lookupPhone === null || $canonicalCode === null) {
                return $this->page('Enter your mobile number and consent code to open the consent form.', 404, null, null, true);
            }
            $lookupCode = GuardianConsent::formatCode($canonicalCode);
            $consent = $this->activeCodeRequest($lookupPhone, $canonicalCode);
            if (!$consent) {
                return $this->page(
                    'No active consent request matched that mobile number and consent code. Contact the school guidance office for help.',
                    404,
                    null,
                    null,
                    true,
                    $rawPhone,
                    $rawCode
                );
            }
            $tokenHash = hash('sha256', $canonicalCode);
        }

        $name = $this->request->getPost('guardian_name');
        $choice = $this->request->getPost('choice');
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 120
            || !in_array($choice, ['accepted', 'declined'], true)) {
            return $this->page(
                'Enter your full name and choose whether to give consent.',
                422,
                $consent['notice'],
                $token,
                false,
                $lookupPhone ?? '',
                $lookupCode ?? ''
            );
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            if (!GuardianConsent::lockStudent($db, (int) $consent['user_id'])) {
                $db->transRollback();
                return $this->page('This registration is no longer pending.', 409);
            }
            $query = $db->table('guardian_consents')->where('user_id', $consent['user_id'])
                ->where('token_hash', $tokenHash)->where('response', null)
                ->where('expires_at >', date('Y-m-d H:i:s'));
            if ($token === null && $lookupPhone !== null) {
                $query->where('phone', $lookupPhone);
            }
            $query->update([
                'token_hash' => null, 'guardian_name' => trim($name), 'response' => $choice,
                'responded_at' => date('Y-m-d H:i:s'),
            ]);
            if ($db->affectedRows() !== 1 || !$db->transStatus() || !$db->transCommit()) {
                $db->transRollback();
                return $this->page('The response could not be recorded. Check whether the consent request is still active.', 409);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Guardian consent response could not be saved.');
            return $this->page('The response could not be recorded. Try again later.', 503);
        }
        return $this->page('Your response was recorded. The school guidance office will review the registration.');
    }

    private function activeRequest(string $token): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $token)) return null;
        return Database::connect()->table('guardian_consents g')->select('g.user_id, g.notice')
            ->join('users u', 'u.id = g.user_id')->where('u.role', 0)->where('u.registration_status', 'pending')
            ->where('g.token_hash', hash('sha256', $token))->where('g.response', null)
            ->where('g.expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
    }

    private function activeCodeRequest(string $phone, string $canonicalCode): ?array
    {
        return Database::connect()->table('guardian_consents g')->select('g.user_id, g.notice')
            ->join('users u', 'u.id = g.user_id')->where('u.role', 0)->where('u.registration_status', 'pending')
            ->where('g.phone', $phone)
            ->where('g.token_hash', hash('sha256', $canonicalCode))->where('g.response', null)
            ->where('g.expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
    }

    private function page(
        string $message,
        int $status = 200,
        ?string $notice = null,
        ?string $token = null,
        bool $showLookup = false,
        string $lookupPhone = '',
        string $lookupCode = ''
    ) {
        helper('form');
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Referrer-Policy', 'no-referrer')->setHeader('X-Robots-Tag', 'noindex, nofollow')
            ->setBody(view('guardian_consent', compact(
                'message',
                'notice',
                'token',
                'showLookup',
                'lookupPhone',
                'lookupCode'
            )));
    }
}
