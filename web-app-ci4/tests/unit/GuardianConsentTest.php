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
}
