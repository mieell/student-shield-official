<?php

namespace Tests\Unit;

use App\Libraries\SmsGateway;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SmsGatewayTest extends CIUnitTestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys($this->defaults()) as $key) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->settings();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) $_ENV[$key] = $env;
            if ($server !== null) $_SERVER[$key] = $server;
            putenv($process === false ? $key : "$key=$process");
        }
        parent::tearDown();
    }

    private function defaults(): array
    {
        return [
            'sms.GatewayUrl' => 'https://gateway.example.test/messages',
            'sms.GatewayUsername' => 'synthetic-user',
            'sms.GatewayPassword' => 'synthetic-secret',
            'sms.GatewayDeviceId' => 'synthetic-device',
            'sms.GatewaySimNumber' => '',
            'app.baseURL' => 'https://website.example.test/',
        ];
    }

    private function settings(array $overrides = []): void
    {
        foreach (array_replace($this->defaults(), $overrides) as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }

    private function client(array $responses = []): SmsGateway
    {
        return new class($responses) extends SmsGateway {
            public array $calls = [];
            public function __construct(private array $responses) {}
            protected function request(string $method, string $url, string $username, string $password, ?array $payload = null): array
            {
                $this->calls[] = compact('method', 'url', 'username', 'password', 'payload');
                $response = array_shift($this->responses) ?? ['status' => 202, 'state' => 'Pending'];
                if (isset($response['exception'])) throw new \RuntimeException('synthetic-secret: private provider response');
                return [
                    'status' => $response['status'],
                    'body' => $response['body'] ?? json_encode([
                        'id' => $response['id'] ?? ($payload['id'] ?? basename($url)),
                        'state' => $response['state'] ?? 'Pending',
                    ]),
                ];
            }
        };
    }

    public function testAuthenticatedRequestTargetsOnlyConfiguredDeviceAndRecipient(): void
    {
        $client = $this->client();
        $result = $client->send('0917 123 4567', 'StudentShield DEMO: Test alert.', 'test:123:counselor');
        $this->assertTrue($result['accepted']);
        $this->assertSame('Pending', $result['state']);
        $this->assertSame(202, $result['http_status']);
        $this->assertCount(1, $client->calls);
        $call = $client->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('synthetic-user', $call['username']);
        $this->assertSame('synthetic-secret', $call['password']);
        $this->assertSame([
            'id' => $result['message_id'], 'deviceId' => 'synthetic-device',
            'textMessage' => ['text' => 'StudentShield DEMO: Test alert.'],
            'phoneNumbers' => ['+639171234567'], 'withDeliveryReport' => true, 'ttl' => 300,
        ], $call['payload']);
        $this->assertMatchesRegularExpression('/^ss-[a-f0-9]{32}$/', $result['message_id']);
        $this->assertLessThanOrEqual(36, strlen($result['message_id']));
        $this->assertStringNotContainsString('synthetic-secret', json_encode($result));
    }

    public function testRetriesReuseAnIdButDifferentNotificationsAndRecipientsDoNot(): void
    {
        $client = $this->client();
        $first = $client->send('09171234567', 'Test alert.', 'vent:7:parent');
        $retry = $client->send('+63-917-123-4567', 'Test alert.', 'vent:7:parent');
        $this->assertSame($first['message_id'], $retry['message_id']);
        $this->assertNotSame($first['message_id'], $client->send('09171234567', 'Test alert.', 'vent:7:counselor')['message_id']);
        $this->assertNotSame($first['message_id'], $client->send('09181234567', 'Test alert.', 'vent:7:parent')['message_id']);
        $this->settings(['app.baseURL' => 'https://other.example.test/']);
        $this->assertNotSame($first['message_id'], $client->send('09171234567', 'Test alert.', 'vent:7:parent')['message_id']);
    }

    public function testDuplicateIdIsReconciledWithoutAnotherPost(): void
    {
        $client = $this->client([['status' => 409], ['status' => 200, 'state' => 'Delivered']]);
        $result = $client->send('639171234567', 'Test alert.', 'vent:7:parent');
        $this->assertTrue($result['accepted']);
        $this->assertSame('Delivered', $result['state']);
        $this->assertSame(['POST', 'GET'], array_column($client->calls, 'method'));
        $this->assertStringEndsWith('/' . $result['message_id'], $client->calls[1]['url']);
    }

    public function testExplicitSimSelectionUsesTheWorkingSlot(): void
    {
        $this->settings(['sms.GatewaySimNumber' => '2']);
        $client = $this->client();
        $this->assertTrue($client->send('09171234567', 'Test alert.', 'event')['accepted']);
        $this->assertSame(2, $client->calls[0]['payload']['simNumber']);
        $this->settings(['sms.GatewaySimNumber' => '3']);
        $this->assertSame('NotConfigured', $client->send('09171234567', 'Test alert.', 'event')['state']);
        $this->assertCount(1, $client->calls);
    }

    public static function unsuccessfulResponses(): iterable
    {
        foreach ([400, 401, 403, 429] as $status) yield "rejected $status" => [[['status' => $status]], 'Rejected'];
        foreach ([0, 408, 500, 503] as $status) yield "unknown $status" => [[['status' => $status]], 'Unknown'];
        yield 'timeout' => [[['exception' => true]], 'Unknown'];
        yield 'malformed acceptance' => [[['status' => 202, 'body' => 'not JSON']], 'Unknown'];
        yield 'wrong ID' => [[['status' => 202, 'id' => 'another-notification']], 'Unknown'];
        yield 'unknown state' => [[['status' => 202, 'state' => 'Success']], 'Unknown'];
        yield 'failed duplicate' => [[['status' => 409], ['status' => 200, 'state' => 'Failed']], 'Failed'];
        yield 'unresolved duplicate' => [[['status' => 409], ['status' => 404]], 'Unknown'];
    }

    #[DataProvider('unsuccessfulResponses')]
    public function testFailuresAreNeverReportedAsSuccessfulOrBlindlyRetried(array $responses, string $state): void
    {
        $client = $this->client($responses);
        $result = $client->send('09171234567', 'Test alert.', 'vent:7:parent');
        $this->assertFalse($result['accepted']);
        $this->assertSame($state, $result['state']);
        $this->assertCount(1, array_filter($client->calls, static fn ($call) => $call['method'] === 'POST'));
    }

    public function testInvalidInputCannotSend(): void
    {
        $client = $this->client();
        foreach ([['', 'Test alert.', 'event'], ['1234', 'Test alert.', 'event'],
            ['+19171234567', 'Test alert.', 'event'], ['09171234567', '', 'event'],
            ['09171234567', str_repeat('a', 161), 'event'], ['09171234567', 'Emoji 🔒', 'event'],
            ['09171234567', 'Test alert.', '']] as $input) {
            $result = $client->send(...$input);
            $this->assertFalse($result['accepted']);
            $this->assertSame('InvalidRequest', $result['state']);
        }
        $this->assertSame([], $client->calls);
    }

    public function testMissingOrUnsafeConfigurationCannotSendCredentials(): void
    {
        $client = $this->client();
        foreach ([['sms.GatewayUsername' => ''], ['sms.GatewayPassword' => ''], ['sms.GatewayDeviceId' => ''],
            ['sms.GatewayUsername' => "user\r\nheader"], ['sms.GatewayPassword' => "secret\nheader"],
            ['sms.GatewayUrl' => 'http://gateway.example.test/messages'],
            ['sms.GatewayUrl' => 'https://user@gateway.example.test/messages'],
            ['sms.GatewayUrl' => 'https://gateway.example.test/messages?password=secret']] as $settings) {
            $this->settings($settings);
            $result = $client->send('09171234567', 'Test alert.', 'event');
            $this->assertSame('NotConfigured', $result['state']);
        }
        $this->assertSame([], $client->calls);
    }
}
