<?php

namespace Tests\Unit;

use App\Libraries\SmsGateway;
use CodeIgniter\Test\CIUnitTestCase;

final class SmsGatewayTest extends CIUnitTestCase
{
    private array $saved;

    protected function setUp(): void
    {
        parent::setUp();
        $key = 'IPROG_SMS_API_TOKEN';
        $this->saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = 'test-token';
        putenv($key . '=test-token');
    }

    protected function tearDown(): void
    {
        [$env, $server, $process] = $this->saved;
        unset($_ENV['IPROG_SMS_API_TOKEN'], $_SERVER['IPROG_SMS_API_TOKEN']);
        if ($env !== null) $_ENV['IPROG_SMS_API_TOKEN'] = $env;
        if ($server !== null) $_SERVER['IPROG_SMS_API_TOKEN'] = $server;
        putenv($process === false ? 'IPROG_SMS_API_TOKEN' : 'IPROG_SMS_API_TOKEN=' . $process);
        parent::tearDown();
    }

    private function client(array $response): SmsGateway
    {
        return new class($response) extends SmsGateway {
            public array $payloads = [];
            public function __construct(private array $response) {}
            protected function request(array $payload): array
            {
                $this->payloads[] = $payload;
                return $this->response;
            }
        };
    }

    public function testQueuesAndNormalizesAndDeduplicates(): void
    {
        $client = $this->client(['status' => 200, 'body' => '{"status":200,"message_id":"iSms-test123"}']);
        $event = 'test:' . bin2hex(random_bytes(12));
        $first = $client->send('09171234567', 'Please contact the guidance office.', $event);
        $second = $client->send('+639171234567', 'Please contact the guidance office.', $event);
        $this->assertSame($first, $second);
        $this->assertSame('Queued', $first['state']);
        $this->assertTrue($first['accepted']);
        $this->assertSame('iSms-test123', $first['message_id']);
        $this->assertCount(1, $client->payloads);
        $this->assertSame('639171234567', $client->payloads[0]['phone_number']);
    }

    public function testAmbiguousTimeoutDoesNotDuplicate(): void
    {
        $client = $this->client(['status' => 0, 'body' => '']);
        $event = 'test:' . bin2hex(random_bytes(12));
        $this->assertSame('Unknown', $client->send('09171234567', 'Test notification.', $event)['state']);
        $this->assertFalse($client->send('09171234567', 'Test notification.', $event)['accepted']);
        $this->assertCount(1, $client->payloads);
    }

    public function testInvalidAndUnconfiguredFailClosed(): void
    {
        $client = $this->client(['status' => 200, 'body' => '{"status":200,"message_id":"iSms-test"}']);
        $this->assertSame('InvalidRequest', $client->send('1234', 'test', 'event')['state']);
        $_ENV['IPROG_SMS_API_TOKEN'] = $_SERVER['IPROG_SMS_API_TOKEN'] = '';
        putenv('IPROG_SMS_API_TOKEN=');
        $this->assertSame('NotConfigured', $client->send('09171234567', 'test', 'event')['state']);
        $this->assertCount(0, $client->payloads);
    }

    public function testProviderErrorAndMalformedResponseAreNotAccepted(): void
    {
        foreach ([
            ['status' => 200, 'body' => '{"status":500,"message":"invalid token"}'],
            ['status' => 200, 'body' => '{"status":200}'],
            ['status' => 503, 'body' => ''],
        ] as $response) {
            $client = $this->client($response);
            $result = $client->send('09171234567', 'Test notification.', 'test:' . bin2hex(random_bytes(12)));
            $this->assertFalse($result['accepted']);
            $this->assertSame('Unknown', $result['state']);
            $this->assertNull($result['message_id']);
        }
    }

    public function testAcceptsObfuscatedConsentMessageWithBracketDot(): void
    {
        $client = $this->client(['status' => 200, 'body' => '{"status":200,"message_id":"iSms-dot1"}']);
        $msg = 'StudentShield: Visit studentshield-app [dot] vercel [dot] app in your browser, open Guardian Consent, and enter code ABCD-2345';
        $this->assertLessThanOrEqual(160, strlen($msg));
        $res = $client->send('09171234567', $msg, 'consent-dot:' . bin2hex(random_bytes(8)));
        $this->assertTrue($res['accepted']);
        $this->assertSame('Queued', $res['state']);
        $this->assertSame($msg, $client->payloads[0]['message']);
    }
}
