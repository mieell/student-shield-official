<?php

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Controllers\BullyReportController;
use App\Controllers\GuardianConsentController;
use App\Controllers\VentController;
use App\Libraries\AlertLog;
use App\Libraries\SmsGateway;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class SmsNotificationFlowTest extends CIUnitTestCase
{
    private $smsDb;
    private array $connections;
    private array $saved = [];
    private SmsGateway $gateway;
    private ?string $priorAlertsBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $alertFile = AlertLog::path();
        $this->priorAlertsBackup = is_file($alertFile) ? (string) file_get_contents($alertFile) : null;
        @unlink($alertFile);
        helper(['url', 'form']);
        foreach ([
            'ML_API_KEY' => '',
            'app.baseURL' => 'http://test-' . bin2hex(random_bytes(8)) . '.invalid/',
            'email.SMTPUser' => '', 'email.SMTPPass' => '',
            'COUNSELOR_PHONE' => '09171234567', 'COUNSELOR_EMAIL' => '',
            'IPROG_SMS_API_TOKEN' => 'synthetic-secret',
            'GUARDIAN_CONSENT_NOTICE' => 'School guardian consent notice.',
            'encryption.key' => str_repeat('k', 32),
        ] as $key => $value) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
        $this->smsDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $property = new \ReflectionProperty(Database::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->smsDb]);
        foreach ([
            'users' => 'id INTEGER PRIMARY KEY, name TEXT, role INTEGER DEFAULT 0, registration_status TEXT DEFAULT \'approved\', is_locked INTEGER DEFAULT 0',
            'students' => 'user_id INTEGER PRIMARY KEY, parent_phone TEXT, parent_email TEXT, section TEXT, grade_level TEXT',
            'counselors' => 'user_id INTEGER PRIMARY KEY, counselor_email TEXT',
            'admins' => 'user_id INTEGER PRIMARY KEY, email TEXT',
            'vent_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, raw_text TEXT, emotions TEXT, risk_percent REAL, risk_level TEXT, status TEXT, created_at TEXT, deleted_at TEXT',
            'bully_reports' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, reporter_student_id INTEGER, reporter_type TEXT, victim_student_id INTEGER, bully_student_id INTEGER, incident_details TEXT, status TEXT, created_at TEXT, updated_at TEXT',
            'guardian_consents' => 'user_id INTEGER PRIMARY KEY, verified_by INTEGER, phone TEXT, verified_name TEXT, token_hash TEXT, notice TEXT, requested_at TEXT, expires_at TEXT, sms_state TEXT, response TEXT, guardian_name TEXT, responded_at TEXT',
        ] as $table => $fields) $this->smsDb->query("CREATE TABLE $table ($fields)");
        foreach ([10 => '09181234567', 11 => '09191234567'] as $id => $phone) {
            $this->smsDb->table('users')->insert(['id' => $id, 'name' => 'PRIVATE STUDENT ' . $id]);
            $this->smsDb->table('students')->insert(['user_id' => $id, 'parent_phone' => $phone, 'parent_email' => '', 'section' => 'A', 'grade_level' => '7']);
        }
        $encrypter = $this->createMock(\CodeIgniter\Encryption\EncrypterInterface::class);
        $encrypter->method('encrypt')->willReturn('encrypted-test-data');
        Services::injectMock('encrypter', $encrypter);
        $encConfig = new \Config\Encryption();
        $encConfig->key = str_repeat('k', 32);
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $encConfig);
        $this->gateway = new class extends SmsGateway {
            public array $payloads = [];
            protected function request(array $payload): array
            {
                $this->payloads[] = $payload;
                return ['status' => 200, 'body' => json_encode(['status' => 200, 'message_id' => 'iSms-test' . count($this->payloads)])];
            }
        };
        Services::injectMock('smsGateway', $this->gateway);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) $_ENV[$key] = $env;
            if ($server !== null) $_SERVER[$key] = $server;
            putenv($process === false ? $key : "$key=$process");
        }
        $property = new \ReflectionProperty(Database::class, 'instances');
        $property->setValue(null, $this->connections);
        \CodeIgniter\Config\Factories::reset('config');
        $alertFile = AlertLog::path();
        if ($this->priorAlertsBackup !== null) {
            file_put_contents($alertFile, $this->priorAlertsBackup);
        } else {
            @unlink($alertFile);
        }
        session()->destroy();
        parent::tearDown();
    }

    private function init($controller, array $post)
    {
        Services::resetSingle('response');
        $config = new \Config\App();
        $config->proxyIPs = [];
        service('superglobals')->setPostArray($post);
        service('superglobals')->setServer('REMOTE_ADDR', '192.0.2.10');
        $request = new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, 'student/vent'), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod('POST');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        Services::injectMock('request', $request);
        $controller->initController($request, Services::response(), Services::logger());
        return $controller;
    }

    public static function ventPolicies(): iterable
    {
        yield 'HIGH never contacts parents' => ['Sinampal ako sa school.', false, 'HIGH', ['639171234567']];
        yield 'CRITICAL includes eligible parent' => ['naglalaslas ako', false, 'CRITICAL', ['639171234567', '639181234567']];
        yield 'legacy preference cannot suppress CRITICAL parent alert' => ['naglalaslas ako', true, 'CRITICAL', ['639171234567', '639181234567']];
    }

    #[DataProvider('ventPolicies')]
    public function testWebsiteUsesExistingVentRecipientPolicy(string $text, bool $hidden, string $risk, array $recipients): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);
        $controller = new class extends VentController {
            public array $alerts = [];
            protected function logAudit(string $action, string $entityType, int $entityId): void {}
            protected function isAlertOnCooldown($recipient) { return false; }
            protected function logAlert($type, $recipient, $message, array $smsStatus = [])
            {
                $this->alerts[] = $smsStatus;
            }
        };
        $response = $this->init($controller, ['vent_text' => $text, 'hide_from_parents' => $hidden ? '1' : '0'])->submit();
        $body = json_decode($response->getBody(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame($risk, $body['risk_level']);
        $this->assertSame($recipients, array_column($this->gateway->payloads, 'phone_number'));
        $this->assertNotContains(false, array_column($controller->alerts, 'accepted'));
        $encoded = json_encode($this->gateway->payloads);
        $this->assertStringNotContainsString('PRIVATE STUDENT', $encoded);
        $this->assertStringNotContainsString($text, $encoded);
    }

    public function testVerifiedIncidentUsesRegisteredParentNumbersAndStableIds(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);
        $this->smsDb->table('bully_reports')->insert(['id' => 42, 'victim_student_id' => 10, 'bully_student_id' => 11, 'status' => 'pending_review']);
        $controller = new class extends AdminController {
            protected function logAudit(string $action, string $entityType, int $entityId): void {}
        };
        $this->init($controller, [])->verifyIncident(42);
        $first = $this->gateway->payloads;
        $this->assertSame(['639181234567', '639191234567'], array_column($first, 'phone_number'));
        $this->assertStringContainsString('delivery is not yet confirmed', session()->getFlashdata('message'));
        $this->init($controller, [])->verifyIncident(42);
        $this->assertCount(2, $this->gateway->payloads);
        $this->assertStringNotContainsString('PRIVATE STUDENT', json_encode($first));

        $alertsResponse = $this->init($controller, [])->fetchAlertLogs();
        $this->assertStringContainsString('no-store', (string) $alertsResponse->getHeaderLine('Cache-Control'));
        $recorded = json_decode((string) $alertsResponse->getBody(), true);
        $this->assertCount(2, $recorded);
        $this->assertSame(['09191234567', '09181234567'], array_column($recorded, 'recipient'));
        $this->assertSame(['SMS (Gateway)', 'SMS (Gateway)'], array_column($recorded, 'type'));
        $this->assertSame(['Queued', 'Queued'], array_column($recorded, 'state'));
    }

    public function testBullyReportIntakeAndGuardianConsentRecordRedactedSmsAlerts(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);
        $bullyController = new class extends BullyReportController {
            protected function logAudit(string $action, string $entityType, int $entityId): void {}
        };
        $this->init($bullyController, [
            'reporter_type' => 'self',
            'bully_name' => '',
            'incident_details' => 'Sinuntok ako sa hallway.',
        ])->submit();

        $this->smsDb->table('users')->insert(['id' => 99, 'name' => 'Pending Student', 'role' => 0, 'registration_status' => 'pending']);
        session()->set(['isLoggedIn' => true, 'role' => 2, 'user_id' => 2]);
        $gateway = $this->gateway;
        $consentController = new class($gateway) extends GuardianConsentController {
            public function __construct(private SmsGateway $mockGateway) {}
            protected function logAudit(string $action, string $entityType, int $entityId): void {}
            protected function smsGateway(): SmsGateway { return $this->mockGateway; }
        };
        $this->init($consentController, [
            'verified_phone' => '09179998888',
            'verified_name' => 'Parent Name',
            'checked_records' => '1',
        ])->send(99);

        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);
        $admin = new class extends AdminController {
            protected function logAudit(string $action, string $entityType, int $entityId): void {}
        };
        $logs = json_decode((string) $this->init($admin, [])->fetchAlertLogs()->getBody(), true);
        $this->assertCount(2, $logs);
        $this->assertSame('SMS (Guardian Consent)', $logs[0]['type']);
        $this->assertSame('+639179998888', $logs[0]['recipient']);
        $this->assertStringNotContainsString('guardian-consent/', $logs[0]['message']);
        $this->assertSame('SMS (Counselor)', $logs[1]['type']);
        $this->assertSame('09171234567', $logs[1]['recipient']);
    }
}
