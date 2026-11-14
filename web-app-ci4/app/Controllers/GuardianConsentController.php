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
        $rawDelivery = $this->request->getPost('delivery');
        $delivery = in_array($rawDelivery, ['manual', 'email'], true) ? $rawDelivery : 'sms';
        $manual = $delivery === 'manual';
        $byEmail = $delivery === 'email';
        $isLinkDelivery = $manual || $byEmail;
        $useVerified = $this->request->getPost('use_verified') === '1';
        $phone = GuardianConsent::phone($this->request->getPost('verified_phone'));
        $verifiedName = $this->request->getPost('verified_name');
        $rawVerifiedEmail = trim((string) ($this->request->getPost('verified_email') ?? ''));
        $verifiedEmail = $rawVerifiedEmail !== '' ? GuardianConsent::email($rawVerifiedEmail) : null;
        $notice = trim((string) env('GUARDIAN_CONSENT_NOTICE', ''));
        if (!$useVerified && ($phone === null || !is_string($verifiedName) || trim($verifiedName) === '' || mb_strlen(trim($verifiedName)) > 120
            || $this->request->getPost('checked_records') !== '1')) {
            return $back->with('error', 'Confirm the guardian and mobile number against current school records first.');
        }
        if (!$useVerified && $rawVerifiedEmail !== '' && $verifiedEmail === null) {
            return $back->with('error', 'Enter a valid email address for the parent or guardian, or leave that field blank.');
        }
        if (!$useVerified && $byEmail && $verifiedEmail === null) {
            return $back->with('error', 'Enter a valid guardian email address before sending the consent link by email.');
        }
        if ($notice === '' || mb_strlen($notice) > 10000 || ($delivery === 'sms' && !trim((string) env('IPROG_SMS_API_TOKEN', '')))) {
            return $back->with('error', 'Set up the school-approved guardian notice and SMS service before sending consent links.');
        }
        if ($byEmail && !$this->hasConfiguredEmail()) {
            return $back->with('error', 'Set up the school-approved guardian notice and SMTP email service before sending consent emails.');
        }

        $baseUrl = site_url();
        $url = null;
        $message = null;
        if ($isLinkDelivery) {
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
        $recipientEmail = $verifiedEmail;
        try {
            $user = GuardianConsent::lockStudent($db, $id);
            $consents = $db->table('guardian_consents');
            $prior = $consents->where('user_id', $id)->get()->getRowArray();
            $allowed = match ($delivery) {
                'manual' => $user && empty($prior['response']),
                'email' => $user && GuardianConsent::canSendEmail($prior, time()),
                default => $user && GuardianConsent::canSend($prior, time()),
            };
            if (!$allowed) {
                $db->transRollback();
                return $back->with('error', $byEmail
                    ? 'A consent email was recently sent or the registration has been decided. Wait 5 minutes before resending by email, or use SMS / replacement link.'
                    : 'A consent request is already active or the registration has been decided.');
            }
            if ($useVerified) {
                $phone = GuardianConsent::phone($prior['phone'] ?? null);
                $verifiedName = $prior['verified_name'] ?? '';
                if ($phone === null || trim($verifiedName) === '') {
                    $db->transRollback();
                    return $back->with('error', 'Verify the guardian contact first.');
                }
                if ($byEmail) {
                    $studentRow = $db->table('students')->select('parent_email')->where('user_id', $id)->get()->getRowArray();
                    $recipientEmail = GuardianConsent::email($studentRow['parent_email'] ?? null);
                    if ($recipientEmail === null) {
                        $db->transRollback();
                        return $back->with('error', 'Add a verified guardian email address first before sending the consent link by email.');
                    }
                }
            } elseif ($verifiedEmail !== null) {
                $db->table('students')->where('user_id', $id)->update(
                    ['parent_email' => $verifiedEmail]
                    + \App\Libraries\ManilaTime::stampFields($db, 'students', ['updated_at'])
                );
            }
            $nowTs = time();
            $initialState = match ($delivery) {
                'manual' => 'Manual',
                'email' => 'EmailPending',
                default => 'Unknown',
            };
            $data = [
                'user_id' => $id, 'verified_by' => (int) session()->get('user_id'), 'phone' => $phone,
                'verified_name' => trim($verifiedName),
                'token_hash' => $hash, 'notice' => $notice, 'requested_at' => \App\Libraries\ManilaTime::now('Y-m-d H:i:s', $nowTs),
                'expires_at' => \App\Libraries\ManilaTime::now('Y-m-d H:i:s', $nowTs + 72 * 3600), 'sms_state' => $initialState,
                'response' => null, 'guardian_name' => null, 'responded_at' => null,
            ];
            $db->table('guardian_consents')->upsert($data);
            if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Consent write failed');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Guardian consent request could not be saved.');
            return $back->with('error', 'The consent request could not be saved. Check that the database update is installed.');
        }

        GuardianConsent::syncTokenStore($id, (string) $phone, $hash, $data['expires_at'], $delivery, $prior);
        $this->logAudit('GUARDIAN_CONTACT_VERIFIED', 'user', $id);
        if ($manual) {
            $this->logAudit('GUARDIAN_CONSENT_LINK_GENERATED', 'user', $id);
            return $back
                ->with('manual_consent_link', $url)
                ->with('manual_consent_meta', [
                    'student_name' => (string) ($user['name'] ?? ''),
                    'guardian_name' => trim((string) $verifiedName),
                    'phone' => (string) $phone,
                    'expires_at' => \App\Libraries\ManilaTime::format($data['expires_at'], 'M j, Y g:i A \P\H\T'),
                ]);
        }
        if ($byEmail) {
            $expiresLabel = \App\Libraries\ManilaTime::format($data['expires_at'], 'M j, Y g:i A \P\H\T');
            $emailSent = $this->sendConsentEmail(
                (string) $recipientEmail,
                trim((string) $verifiedName),
                (string) $url,
                $expiresLabel,
                $id
            );
            $emailState = $emailSent ? 'EmailSent' : 'EmailFailed';
            $db->table('guardian_consents')->where('user_id', $id)->where('token_hash', $hash)->update(['sms_state' => $emailState]);
            \App\Libraries\AlertLog::record(
                'Email (Guardian Consent)',
                (string) $recipientEmail,
                'StudentShield: Guardian registration consent link emailed (consent URL redacted).',
                ['state' => $emailSent ? 'Sent' : 'Failed']
            );
            if ($emailSent) {
                $this->logAudit('GUARDIAN_CONSENT_EMAIL_SENT', 'user', $id);
                return $back->with(
                    'success',
                    'The guardian consent link was emailed to ' . $recipientEmail . '. Approval unlocks once the guardian submits their response (or send a follow-up SMS or replacement link if needed).'
                );
            }
            return $back->with(
                'error',
                'The consent email could not be delivered. Check the guardian email address or SMTP settings, or use SMS or a replacement link instead.'
            );
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

    protected function hasConfiguredEmail(): bool
    {
        $emailConfig = config('Email');
        $smtpUser = trim((string) env('email.SMTPUser', $emailConfig->SMTPUser ?? ''));
        $smtpPass = (string) env('email.SMTPPass', $emailConfig->SMTPPass ?? '');
        $smtpHost = trim((string) env('email.SMTPHost', $emailConfig->SMTPHost ?? 'smtp.gmail.com'));
        return $smtpUser !== '' && $smtpPass !== '' && $smtpHost !== '';
    }

    protected function sendConsentEmail(
        string $recipientEmail,
        string $verifiedName,
        string $url,
        string $expiresAt,
        int $studentUserId
    ): bool {
        $emailSent = false;
        try {
            $emailConfig = config('Email');
            $email = \Config\Services::email();
            $smtpUser = trim((string) env('email.SMTPUser', $emailConfig->SMTPUser ?? ''));
            $smtpPass = (string) env('email.SMTPPass', $emailConfig->SMTPPass ?? '');
            $smtpHost = trim((string) env('email.SMTPHost', $emailConfig->SMTPHost ?? 'smtp.gmail.com'));
            $smtpPort = (int) env('email.SMTPPort', $emailConfig->SMTPPort ?? 587);
            $smtpCrypto = (string) env('email.SMTPCrypto', $emailConfig->SMTPCrypto ?? 'tls');
            $fromEmail = trim((string) env('email.fromEmail', $emailConfig->fromEmail ?? $smtpUser)) ?: $smtpUser;
            $fromName = trim((string) env('email.fromName', $emailConfig->fromName ?? 'SVBSM Wellness Team')) ?: 'SVBSM Wellness Team';

            $email->initialize([
                'protocol'    => 'smtp',
                'SMTPHost'    => $smtpHost,
                'SMTPUser'    => $smtpUser,
                'SMTPPass'    => $smtpPass,
                'SMTPPort'    => $smtpPort,
                'SMTPCrypto'  => $smtpCrypto,
                'SMTPTimeout' => 10,
                'mailType'    => 'html',
                'charset'     => 'utf-8',
                'newline'     => "\r\n",
                'CRLF'        => "\r\n",
                'wordWrap'    => true,
            ]);

            $email->setFrom($fromEmail, $fromName);
            $email->setTo($recipientEmail);
            $email->setSubject('SVBSM StudentShield: Parent / Guardian Consent Request');

            $guardianEsc = esc($verifiedName);
            $urlEsc = esc($url, 'attr');
            $urlTextEsc = esc($url);
            $expiresEsc = esc($expiresAt);
            $html = "
            <div style='background:#f4f7f5; padding: 32px 16px; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif;'>
                <div style='max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #d7e4db;'>
                    <div style='background: linear-gradient(135deg, #166534, #083d1e); padding: 26px 24px; text-align: center;'>
                        <h1 style='color: #ffffff; margin: 0; font-size: 21px; font-weight: 700;'>Student<span style='color: #F2C300;'>Shield</span></h1>
                        <p style='color: rgba(255,255,255,0.85); margin: 6px 0 0 0; font-size: 13px;'>St. Vincent Blessed School of Manila &middot; Guidance Office</p>
                    </div>
                    <div style='padding: 28px 26px; color: #1e293b;'>
                        <h2 style='margin: 0 0 12px; font-size: 18px; font-weight: 700; color: #0f3d21;'>Parent / Guardian Consent Request</h2>
                        <p style='margin: 0 0 14px; font-size: 14px; line-height: 1.6; color: #334155;'>
                            Dear <strong>{$guardianEsc}</strong>,
                        </p>
                        <p style='margin: 0 0 18px; font-size: 14px; line-height: 1.6; color: #334155;'>
                            The SVBSM Guidance Office verified your contact details against school records for a pending StudentShield registration. Please open the private link below to review the guardian notice and record your response:
                        </p>
                        <div style='text-align: center; margin: 22px 0;'>
                            <a href='{$urlEsc}' style='display: inline-block; background: #166534; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px; padding: 13px 24px; border-radius: 10px;'>Review &amp; Respond to Consent Request</a>
                        </div>
                        <p style='margin: 0 0 12px; font-size: 12px; line-height: 1.5; color: #64748b; word-break: break-all;'>
                            If the button above does not open, copy and paste this link in your browser:<br>
                            <span style='color: #0f3d21;'>{$urlTextEsc}</span>
                        </p>
                        <p style='margin: 0 0 16px; font-size: 12px; font-weight: 600; color: #92400e;'>
                            This private consent link is valid for 72 hours (until {$expiresEsc}).
                        </p>
                        <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                        <p style='margin: 0; font-size: 12px; line-height: 1.5; color: #64748b;'>
                            If you did not expect this message or want to confirm with the school before responding, contact the SVBSM Guidance Office.
                        </p>
                    </div>
                </div>
            </div>";

            $email->setMessage($html);
            if ($email->send(false)) {
                $emailSent = true;
            } else {
                log_message('error', 'Guardian consent email sending failed: ' . $email->printDebugger(['headers', 'subject']));
            }
        } catch (\Throwable $e) {
            log_message('error', 'Guardian consent email exception: ' . $e->getMessage());
        }

        try {
            $db = Database::connect();
            \App\Libraries\ManilaTime::syncDatabaseSession($db);
            if ($db->tableExists('email_logs')) {
                $db->table('email_logs')->insert([
                    'student_id'      => $studentUserId,
                    'recipient_email' => $recipientEmail,
                    'alert_type'      => 'GUARDIAN_CONSENT',
                    'subject'         => 'SVBSM StudentShield: Parent / Guardian Consent Request',
                    'message_content' => 'Guardian registration consent link emailed (consent URL redacted).',
                    'sent_status'     => $emailSent ? 'sent' : 'failed',
                ] + \App\Libraries\ManilaTime::stampFields($db, 'email_logs', ['timestamp']));
            }
        } catch (\Throwable $e) {}

        return $emailSent;
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
            $nowStr = \App\Libraries\ManilaTime::now();
            $query = $db->table('guardian_consents')->where('user_id', $consent['user_id'])
                ->where('response', null)->where('expires_at >', $nowStr);
            $lockedQuery = $db->table('guardian_consents')->where('user_id', $consent['user_id'])
                ->where('response', null)->where('expires_at >', $nowStr);
            if ($token === null) {
                $lockedQuery->where('phone', $lookupPhone);
                $query->where('phone', $lookupPhone);
            }
            $lockedConsent = $lockedQuery->get()->getRowArray();
            $validToken = $lockedConsent && (
                hash_equals((string) ($lockedConsent['token_hash'] ?? ''), $tokenHash)
                || ($token !== null
                    ? GuardianConsent::hasActiveLinkToken((int) $consent['user_id'], (string) ($lockedConsent['phone'] ?? ''), $tokenHash, time())
                    : GuardianConsent::hasActiveSmsToken((int) $consent['user_id'], (string) $lookupPhone, $tokenHash, time()))
            );
            if (!$validToken) {
                $db->transRollback();
                return $this->page('The response could not be recorded. Check whether the consent request is still active.', 409);
            }
            $query->update([
                'token_hash' => null, 'guardian_name' => trim($name), 'response' => $choice,
                'responded_at' => $nowStr,
            ]);
            if ($db->affectedRows() !== 1 || !$db->transStatus() || !$db->transCommit()) {
                $db->transRollback();
                return $this->page('The response could not be recorded. Check whether the consent request is still active.', 409);
            }
            GuardianConsent::clearTokens((int) $consent['user_id']);
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
        $hash = hash('sha256', $token);
        $nowStr = \App\Libraries\ManilaTime::now();
        $db = Database::connect();
        $row = $db->table('guardian_consents g')->select('g.user_id, g.notice')
            ->join('users u', 'u.id = g.user_id')->where('u.role', 0)->where('u.registration_status', 'pending')
            ->where('g.token_hash', $hash)->where('g.response', null)
            ->where('g.expires_at >', $nowStr)->get()->getRowArray();
        if ($row) {
            return $row;
        }

        // Preserve unexpired emailed/manual links for the same pending student if a follow-up SMS updated token_hash.
        $candidates = $db->table('guardian_consents g')->select('g.user_id, g.phone, g.notice')
            ->join('users u', 'u.id = g.user_id')->where('u.role', 0)->where('u.registration_status', 'pending')
            ->where('g.response', null)->where('g.expires_at >', $nowStr)->get()->getResultArray();
        $now = time();
        foreach ($candidates as $candidate) {
            if (GuardianConsent::hasActiveLinkToken((int) $candidate['user_id'], (string) ($candidate['phone'] ?? ''), $hash, $now)) {
                return ['user_id' => $candidate['user_id'], 'notice' => $candidate['notice']];
            }
        }
        return null;
    }

    private function activeCodeRequest(string $phone, string $canonicalCode): ?array
    {
        $hash = hash('sha256', $canonicalCode);
        $nowStr = \App\Libraries\ManilaTime::now();
        $db = Database::connect();
        $row = $db->table('guardian_consents g')->select('g.user_id, g.notice')
            ->join('users u', 'u.id = g.user_id')->where('u.role', 0)->where('u.registration_status', 'pending')
            ->where('g.phone', $phone)
            ->where('g.token_hash', $hash)->where('g.response', null)
            ->where('g.expires_at >', $nowStr)->get()->getRowArray();
        if ($row) {
            return $row;
        }

        // Preserve unexpired prior SMS codes sent to the same verified phone if a resend or backup link updated token_hash.
        $candidates = $db->table('guardian_consents g')->select('g.user_id, g.notice')
            ->join('users u', 'u.id = g.user_id')->where('u.role', 0)->where('u.registration_status', 'pending')
            ->where('g.phone', $phone)->where('g.response', null)
            ->where('g.expires_at >', $nowStr)->get()->getResultArray();
        $now = time();
        foreach ($candidates as $candidate) {
            if (GuardianConsent::hasActiveSmsToken((int) $candidate['user_id'], $phone, $hash, $now)) {
                return $candidate;
            }
        }
        return null;
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
