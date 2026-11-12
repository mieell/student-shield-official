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
}
