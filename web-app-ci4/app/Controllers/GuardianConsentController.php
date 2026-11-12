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

        $token = bin2hex(random_bytes(16));
        $url = site_url('guardian-consent/' . $token);
        $message = 'Please review the school consent form: ' . $url;
        if ((!$manual && (strlen($message) > 160 || !preg_match('/\A[A-Za-z0-9 .,:;!?()\/+\-]+\z/D', $message)))
            || (ENVIRONMENT === 'production' && !str_starts_with($url, 'https://'))) {
            return $back->with('error', 'Configure a public HTTPS site address that fits in the consent SMS.');
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
            $hash = hash('sha256', $token);
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
            return $back->with('manual_consent_link', $url);
        }
        $smsResult = $this->smsGateway()->send($phone, $message, 'guardian-consent:' . $hash);
        $state = $smsResult['state'];
        // Redact the bearer link so counselor-visible alert logs cannot expose consent tokens.
        \App\Libraries\AlertLog::record(
            'SMS (Guardian Consent)',
            $phone,
            'StudentShield: Guardian registration consent SMS dispatched (verification link redacted).',
            $smsResult
        );
        $db->table('guardian_consents')->where('user_id', $id)->where('token_hash', $hash)->update(['sms_state' => $state]);
        return $back->with($state === 'Queued' ? 'success' : 'error', match ($state) {
            'Queued' => 'The provider queued the consent SMS. Approval is available once the guardian accepts.',
            'Unknown' => 'SMS delivery is uncertain. Follow up with the verified guardian before sending another link.',
            default => 'The SMS was not queued. Check the number or SMS service and try again in a few minutes.',
        });
    }

    protected function smsGateway(): SmsGateway
    {
        return new SmsGateway();
    }

    public function form(string $token)
    {
        $consent = $this->activeRequest($token);
        if (!$consent) return $this->page('This link is unavailable. Contact the school guidance office for help.', 404);
        return $this->page('', 200, $consent['notice'], $token);
    }

    public function respond(string $token)
    {
        $consent = $this->activeRequest($token);
        if (!$consent) return $this->page('This link is unavailable. Contact the school guidance office for help.', 404);
        $name = $this->request->getPost('guardian_name');
        $choice = $this->request->getPost('choice');
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 120
            || !in_array($choice, ['accepted', 'declined'], true)) {
            return $this->page('Enter your full name and choose whether to give consent.', 422, $consent['notice'], $token);
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            if (!GuardianConsent::lockStudent($db, (int) $consent['user_id'])) {
                $db->transRollback();
                return $this->page('This registration is no longer pending.', 409);
            }
            $db->table('guardian_consents')->where('user_id', $consent['user_id'])
                ->where('token_hash', hash('sha256', $token))->where('response', null)
                ->where('expires_at >', date('Y-m-d H:i:s'))->update([
                    'token_hash' => null, 'guardian_name' => trim($name), 'response' => $choice,
                    'responded_at' => date('Y-m-d H:i:s'),
                ]);
            if ($db->affectedRows() !== 1 || !$db->transStatus() || !$db->transCommit()) {
                $db->transRollback();
                return $this->page('The response could not be recorded. Check whether the link is still active.', 409);
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

    private function page(string $message, int $status = 200, ?string $notice = null, ?string $token = null)
    {
        helper('form');
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Referrer-Policy', 'no-referrer')->setHeader('X-Robots-Tag', 'noindex, nofollow')
            ->setBody(view('guardian_consent', compact('message', 'notice', 'token')));
    }
}
