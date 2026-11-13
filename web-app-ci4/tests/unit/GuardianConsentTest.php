<?php

namespace Tests\Unit;

use App\Libraries\GuardianConsent;
use CodeIgniter\Test\CIUnitTestCase;

final class GuardianConsentTest extends CIUnitTestCase
{
    public function testActiveManualLinkBlocksSmsResend(): void
    {
        $now = time();
        $request = [
            'response' => null,
            'sms_state' => 'Manual',
            'requested_at' => date('Y-m-d H:i:s', $now - 600),
            'expires_at' => date('Y-m-d H:i:s', $now + 600),
        ];

        self::assertFalse(GuardianConsent::canSend($request, $now));
        self::assertTrue(GuardianConsent::canSend($request, $now + 601));
    }

    public function testSmsHostFormatsDomainsWithBracketDotAndAvoidsUrls(): void
    {
        self::assertSame('studentshield [dot] app', GuardianConsent::smsHost(null, 'https://studentshield.app/'));
        self::assertSame(
            'studentshield-app [dot] vercel [dot] app',
            GuardianConsent::smsHost('https://studentshield-app.vercel.app/', 'https://studentshield.app/')
        );
        self::assertSame(
            'studentshield-app [dot] vercel [dot] app',
            GuardianConsent::smsHost('studentshield-app [dot] vercel [dot] app', 'https://studentshield.app/')
        );
        self::assertNull(GuardianConsent::smsHost('bad host name!', 'https://studentshield.app/'));

        $code = GuardianConsent::generateCode();
        self::assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}\z/', $code);
        self::assertSame(str_replace('-', '', $code), GuardianConsent::normalizeCode(strtolower(str_replace('-', ' ', $code))));
        self::assertNull(GuardianConsent::normalizeCode('0000-1111'));

        $msg = GuardianConsent::formatSmsMessage('studentshield-app [dot] vercel [dot] app', $code);
        self::assertStringNotContainsString('.', $msg);
        self::assertStringNotContainsString('/', $msg);
        self::assertStringNotContainsString('http', strtolower($msg));
        self::assertTrue(GuardianConsent::fitsSingleSms($msg));
    }

    public function testRateLimitFilterProtectsGuardianConsentLookup(): void
    {
        $rateDir = WRITEPATH . 'rate_limits/';
        if (is_dir($rateDir)) {
            foreach (glob($rateDir . '*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
        $filter = new \App\Filters\RateLimitFilter();
        $savedRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        service('superglobals')->setServer('REMOTE_ADDR', '127.0.0.22');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.22';
        $request = new \CodeIgniter\HTTP\IncomingRequest(
            new \Config\App(),
            new \CodeIgniter\HTTP\URI(base_url('guardian-consent')),
            null,
            new \CodeIgniter\HTTP\UserAgent()
        );
        $request->setMethod('POST');

        try {
            for ($i = 0; $i < 10; $i++) {
                self::assertNull($filter->before($request));
            }
            $blocked = $filter->before($request);
            self::assertNotNull($blocked);
            self::assertSame(302, $blocked->getStatusCode());
            self::assertStringContainsString('guardian-consent', (string) $blocked->getHeaderLine('Location'));

            // A successful consent lookup/submit clears the failure bucket.
            $ok = \Config\Services::response()->setStatusCode(200);
            $filter->after($request, $ok);
            self::assertNull($filter->before($request));
        } finally {
            if ($savedRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
                service('superglobals')->unsetServer('REMOTE_ADDR');
            } else {
                $_SERVER['REMOTE_ADDR'] = $savedRemoteAddr;
                service('superglobals')->setServer('REMOTE_ADDR', $savedRemoteAddr);
            }
            if (is_dir($rateDir)) {
                foreach (glob($rateDir . '*.json') ?: [] as $file) {
                    @unlink($file);
                }
            }
        }
    }

    public function testLoginLinksToGuardianConsentAndLookupViewRenders(): void
    {
        $loginHtml = view('login');
        self::assertStringContainsString('guardian-consent', $loginHtml);
        self::assertStringContainsString('Open Guardian Consent', $loginHtml);

        helper('form');
        $lookupHtml = view('guardian_consent', [
            'message' => '',
            'notice' => null,
            'token' => null,
            'showLookup' => true,
            'lookupPhone' => '',
            'lookupCode' => '',
        ]);
        self::assertStringContainsString('name="guardian_phone"', $lookupHtml);
        self::assertStringContainsString('name="consent_code"', $lookupHtml);
    }

    public function testQueuedSmsBlocksImmediateResendAndUnlocksAfterFiveMinutes(): void
    {
        $now = time();
        $recent = [
            'response' => null,
            'sms_state' => 'Queued',
            'requested_at' => date('Y-m-d H:i:s', $now - 60),
            'expires_at' => date('Y-m-d H:i:s', $now + 3600),
        ];
        self::assertFalse(GuardianConsent::canSend($recent, $now));

        $afterCooldown = [
            'response' => null,
            'sms_state' => 'Queued',
            'requested_at' => date('Y-m-d H:i:s', $now - 301),
            'expires_at' => date('Y-m-d H:i:s', $now + 3600),
        ];
        self::assertTrue(GuardianConsent::canSend($afterCooldown, $now));
    }

    public function testPendingRegistrationsViewShowsVerifiedPhoneAndCarrierSentStatus(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 2, 'user_id' => 2]);
        $html = view('admin_pending_registrations', [
            'registrationStatus' => 'pending',
            'canApproveRegistrations' => false,
            'canVerifyContact' => true,
            'pendingUsers' => [[
                'id' => 91,
                'name' => 'Student Sample',
                'email' => 'sample@svbsm.edu.ph',
                'student_id' => 'SS-2026-0091',
                'lrn' => '136465100328',
                'grade_level' => 'Grade 10',
                'section' => 'Rizal',
                'parent_phone' => null,
                'parent_email' => null,
                'created_at' => '2026-11-12 12:40:00',
                'verified_phone' => '+639188564586',
                'verified_name' => 'Maria Sample',
                'requested_at' => date('Y-m-d H:i:s', time() - 600),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'sms_state' => 'Queued',
                'response' => null,
                'guardian_name' => null,
                'responded_at' => null,
            ]],
        ]);
        self::assertStringContainsString('+639188564586', $html);
        self::assertStringContainsString('Verified', $html);
        self::assertStringContainsString('SMS sent to carrier · awaiting guardian', $html);
        self::assertStringNotContainsString('SMS queued · awaiting guardian', $html);
        session()->remove(['isLoggedIn', 'role', 'user_id']);
    }

    public function testPreCarrierFailuresAllowImmediateSmsRetryWhileUnknownRemainsBlocked(): void
    {
        $now = time();
        foreach (['NotConfigured', 'InvalidRequest', 'StorageUnavailable'] as $state) {
            self::assertTrue(GuardianConsent::canSend([
                'response' => null,
                'sms_state' => $state,
                'requested_at' => date('Y-m-d H:i:s', $now - 5),
                'expires_at' => date('Y-m-d H:i:s', $now + 600),
            ], $now), "{$state} should allow immediate retry");
        }

        $unknown = [
            'response' => null,
            'sms_state' => 'Unknown',
            'requested_at' => date('Y-m-d H:i:s', $now - 400),
            'expires_at' => date('Y-m-d H:i:s', $now + 600),
        ];
        self::assertFalse(GuardianConsent::canSend($unknown, $now));
        self::assertTrue(GuardianConsent::canSend($unknown, $now + 601));
    }

    public function testPendingRegistrationsViewRendersEnabledAndCooldownResendSmsStates(): void
    {
        helper('form');
        session()->set(['isLoggedIn' => true, 'role' => 2, 'user_id' => 2]);
        $now = time();
        $baseRow = [
            'user_id' => 91,
            'name' => 'Jc Ramos',
            'email' => 'jc.ramos@example.edu',
            'student_id' => 'SS-20261113-CCEE2',
            'lrn' => '348903528792',
            'grade_level' => '5',
            'section' => 'Rizal',
            'parent_phone' => null,
            'parent_email' => null,
            'verified_phone' => '+639669742070',
            'verified_name' => 'Ramos Ramos',
            'guardian_name' => null,
            'response' => null,
            'responded_at' => null,
            'expires_at' => date('Y-m-d H:i:s', $now + 86400),
            'created_at' => date('Y-m-d H:i:s', $now - 3600),
        ];

        try {
            $readyHtml = view('admin_pending_registrations', [
                'pendingUsers' => [$baseRow + ['sms_state' => 'Queued', 'requested_at' => date('Y-m-d H:i:s', $now - 400)]],
                'canVerifyContact' => true,
                'registrationStatus' => 'pending',
            ]);
            self::assertStringContainsString('<span class="whitespace-nowrap">+639669742070</span>', $readyHtml);
            self::assertStringContainsString('>Resend consent SMS</button>', $readyHtml);
            self::assertStringContainsString('Generate replacement link', $readyHtml);

            $cooldownHtml = view('admin_pending_registrations', [
                'pendingUsers' => [$baseRow + ['sms_state' => 'Queued', 'requested_at' => date('Y-m-d H:i:s', $now - 60)]],
                'canVerifyContact' => true,
                'registrationStatus' => 'pending',
            ]);
            self::assertStringContainsString('Resend SMS (wait 4m)', $cooldownHtml);
            self::assertStringNotContainsString('>Resend consent SMS</button>', $cooldownHtml);

            $retryHtml = view('admin_pending_registrations', [
                'pendingUsers' => [$baseRow + ['sms_state' => 'NotConfigured', 'requested_at' => date('Y-m-d H:i:s', $now - 10)]],
                'canVerifyContact' => true,
                'registrationStatus' => 'pending',
            ]);
            self::assertStringContainsString('>Resend consent SMS</button>', $retryHtml);

            $unknownHtml = view('admin_pending_registrations', [
                'pendingUsers' => [$baseRow + ['sms_state' => 'Unknown', 'requested_at' => date('Y-m-d H:i:s', $now - 400)]],
                'canVerifyContact' => true,
                'registrationStatus' => 'pending',
            ]);
            self::assertStringContainsString('Resend SMS unavailable', $unknownHtml);
            self::assertStringNotContainsString('>Resend consent SMS</button>', $unknownHtml);
        } finally {
            session()->remove(['isLoggedIn', 'role', 'user_id']);
        }
    }

    public function testPhoneAndCodeNormalizationHandlesMobileCopyPasteUnicodeCharacters(): void
    {
        self::assertSame('RAXBFRGS', GuardianConsent::normalizeCode("\u{200B} raxb\u{00A0}\u{2013}\u{00AD}frgs \u{200F}"));
        self::assertSame('RAXBFRGS', GuardianConsent::normalizeCode("RAXB\u{2014}FRGS"));
        self::assertSame('RAXBFRGS', GuardianConsent::normalizeCode("RAXB\u{2212}FRGS"));
        self::assertSame('+639669742070', GuardianConsent::phone("(+63) 966\u{00A0}974\u{2013}2070\u{200B}"));
        self::assertSame('+639669742070', GuardianConsent::phone('0966-974-2070'));

        $html = view('guardian_consent', [
            'message' => '',
            'notice' => null,
            'token' => null,
            'showLookup' => true,
            'lookupPhone' => '09669742070',
            'lookupCode' => 'RAXB-FRGS',
        ]);
        self::assertStringContainsString('id="consent-code"', $html);
        self::assertStringContainsString('maxlength="32"', $html);
        self::assertStringContainsString('autocapitalize="characters"', $html);
        self::assertStringContainsString('spellcheck="false"', $html);
    }

    public function testTokenStoreRetainsActiveSmsCodesAcrossResendAndBackupLinkAndClearsOnPhoneChangeOrClear(): void
    {
        $userId = 900123;
        $now = time();
        $expSoon = date('Y-m-d H:i:s', $now + 3600);
        $expLater = date('Y-m-d H:i:s', $now + 7200);
        $sms1 = hash('sha256', 'RAXBFRGS');
        $sms2 = hash('sha256', 'KLMN2345');
        $manual1 = hash('sha256', 'manual-link-1');
        $manual2 = hash('sha256', 'manual-link-2');
        $smsNewPhone = hash('sha256', 'PQRS6789');

        GuardianConsent::clearTokens($userId);
        try {
            // Generating a manual backup link preserves the prior active SMS code from DB state.
            GuardianConsent::syncTokenStore($userId, '+639669742070', $manual1, $expLater, 'manual', [
                'token_hash' => $sms1,
                'phone' => '09669742070',
                'sms_state' => 'Queued',
                'expires_at' => $expSoon,
                'response' => null,
            ], $now);
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms1, $now));
            self::assertFalse(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $manual1, $now));

            // Resending SMS keeps prior active SMS codes usable for the same phone until expiry.
            GuardianConsent::syncTokenStore($userId, '+639669742070', $sms2, $expLater, 'sms', [
                'token_hash' => $manual1,
                'phone' => '+639669742070',
                'sms_state' => 'Manual',
                'expires_at' => $expLater,
                'response' => null,
            ], $now);
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms1, $now));
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms2, $now));
            self::assertFalse(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms1, $now + 3601));
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms2, $now + 3601));

            // Replacement manual link leaves prior active SMS codes intact for that same phone.
            GuardianConsent::syncTokenStore($userId, '+639669742070', $manual2, $expLater, 'manual', null, $now);
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms1, $now));
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms2, $now));

            // Updating to a different verified phone purges all prior-phone SMS tokens immediately.
            GuardianConsent::syncTokenStore($userId, '+639171234567', $smsNewPhone, $expLater, 'sms', [
                'token_hash' => $sms2,
                'phone' => '+639669742070',
                'sms_state' => 'Queued',
                'expires_at' => $expLater,
                'response' => null,
            ], $now);
            self::assertFalse(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms1, $now));
            self::assertFalse(GuardianConsent::hasActiveSmsToken($userId, '+639669742070', $sms2, $now));
            self::assertFalse(GuardianConsent::hasActiveSmsToken($userId, '+639171234567', $sms1, $now));
            self::assertTrue(GuardianConsent::hasActiveSmsToken($userId, '+639171234567', $smsNewPhone, $now));

            GuardianConsent::clearTokens($userId);
            self::assertFalse(GuardianConsent::hasActiveSmsToken($userId, '+639171234567', $smsNewPhone, $now));
            self::assertFileDoesNotExist(WRITEPATH . 'consent-tokens' . DIRECTORY_SEPARATOR . $userId . '.json');
        } finally {
            GuardianConsent::clearTokens($userId);
        }
    }

    public function testConfigAppDetectsCloudflareHttpsAndIgnoresFalsyHttpsValues(): void
    {
        $savedServer = $_SERVER;
        try {
            $_SERVER['HTTP_HOST'] = 'studentshield.app';
            unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
            $_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}';
            self::assertSame('https://studentshield.app/', (new \Config\App())->baseURL);

            $_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"http"}';
            $_SERVER['HTTPS'] = '';
            self::assertSame('http://studentshield.app/', (new \Config\App())->baseURL);
        } finally {
            $_SERVER = $savedServer;
        }
    }

    public function testManageRegistrationDialogRendersFlatUnverifiedFormAndCollapsedUpdateForm(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 2, 'user_id' => 2]);
        $unverified = [
            'user_id' => 92,
            'name' => 'Jayzi',
            'email' => 'jayzi@example.edu',
            'student_id' => 'SS-20261113-00092',
            'lrn' => '123456789012',
            'grade_level' => '6',
            'section' => 'Mabini',
            'parent_phone' => null,
            'parent_email' => null,
            'verified_phone' => null,
            'verified_name' => null,
            'guardian_name' => null,
            'response' => null,
            'responded_at' => null,
            'requested_at' => null,
            'expires_at' => null,
            'sms_state' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $unverifiedHtml = view('admin_pending_registrations', [
                'pendingUsers' => [$unverified],
                'canVerifyContact' => true,
                'registrationStatus' => 'pending',
            ]);
            self::assertStringContainsString('id="registration-dialog-92"', $unverifiedHtml);
            self::assertStringContainsString('aria-label="Close modal"', $unverifiedHtml);
            self::assertStringContainsString('Contact needs verification', $unverifiedHtml);
            self::assertStringContainsString('class="guardian-actions"', $unverifiedHtml);
            self::assertStringContainsString('Send consent code by SMS', $unverifiedHtml);
            self::assertStringContainsString('Generate link for private sharing', $unverifiedHtml);
            self::assertStringContainsString('Archive registration', $unverifiedHtml);
            self::assertStringNotContainsString('Verify or update guardian contact', $unverifiedHtml);

            $verifiedHtml = view('admin_pending_registrations', [
                'pendingUsers' => [array_merge($unverified, [
                    'verified_phone' => '+639669742070',
                    'verified_name' => 'Ramos Ramos',
                    'sms_state' => 'Queued',
                    'requested_at' => date('Y-m-d H:i:s', time() - 400),
                    'expires_at' => date('Y-m-d H:i:s', time() + 86400),
                ])],
                'canVerifyContact' => true,
                'registrationStatus' => 'pending',
            ]);
            self::assertStringContainsString('Verify or update guardian contact', $verifiedHtml);
            self::assertStringNotContainsString('<details open', $verifiedHtml);
        } finally {
            session()->remove(['isLoggedIn', 'role', 'user_id']);
        }
    }
}
