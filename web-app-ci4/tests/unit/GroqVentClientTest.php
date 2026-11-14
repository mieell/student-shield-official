<?php

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Controllers\VentController;
use App\Libraries\GroqVentClient;
use App\Libraries\ScreeningExplanation;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class GroqVentClientTest extends CIUnitTestCase
{
    private $memDb;
    private array $connections;
    private array $savedEnv = [];
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);

        foreach ([
            'GROQ_API_KEY', 'GROQ_ENABLED', 'GROQ_VENT_FALLBACK_ENABLED',
            'GROQ_MODEL', 'GROQ_VENT_MODEL', 'GROQ_TIMEOUT_SECONDS',
            'ML_API_KEY', 'COUNSELOR_EMAIL', 'COUNSELOR_PHONE', 'email.SMTPUser', 'email.SMTPPass',
            'encryption.key',
        ] as $key) {
            $this->savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->savedServer = $_SERVER;

        $_ENV['GROQ_API_KEY'] = $_SERVER['GROQ_API_KEY'] = '';
        $_ENV['GROQ_ENABLED'] = $_SERVER['GROQ_ENABLED'] = '';
        $_ENV['GROQ_VENT_FALLBACK_ENABLED'] = $_SERVER['GROQ_VENT_FALLBACK_ENABLED'] = '';
        $_ENV['GROQ_MODEL'] = $_SERVER['GROQ_MODEL'] = '';
        $_ENV['GROQ_VENT_MODEL'] = $_SERVER['GROQ_VENT_MODEL'] = '';
        $_ENV['ML_API_KEY'] = $_SERVER['ML_API_KEY'] = '';
        $_ENV['COUNSELOR_EMAIL'] = $_SERVER['COUNSELOR_EMAIL'] = '';
        $_ENV['COUNSELOR_PHONE'] = $_SERVER['COUNSELOR_PHONE'] = '';
        $_ENV['email.SMTPUser'] = $_SERVER['email.SMTPUser'] = '';
        $_ENV['email.SMTPPass'] = $_SERVER['email.SMTPPass'] = '';
        $_ENV['encryption.key'] = $_SERVER['encryption.key'] = str_repeat('g', 32);

        $this->memDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $property = new \ReflectionProperty(Database::class, 'instances');
        $property->setAccessible(true);
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->memDb]);

        foreach ([
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, password_hash TEXT, role INTEGER DEFAULT 0',
            'students' => 'user_id INTEGER PRIMARY KEY, student_id TEXT, lrn TEXT, grade_level TEXT, section TEXT, parent_phone TEXT, parent_email TEXT, adviser_name TEXT',
            'vent_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, raw_text TEXT, emotions TEXT, risk_percent REAL, risk_level TEXT, analysis_details TEXT, trajectory TEXT, escalation_probability REAL, status TEXT, created_at TEXT, deleted_at TEXT',
            'audit_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, ip_address TEXT, user_agent TEXT, created_at TEXT',
            'guardian_alert_routes' => 'user_id INTEGER PRIMARY KEY, unsafe_guardian INTEGER DEFAULT 0',
            'counselors' => 'user_id INTEGER PRIMARY KEY, counselor_email TEXT, counselor_phone TEXT',
            'admins' => 'user_id INTEGER PRIMARY KEY, email TEXT, phone TEXT',
        ] as $table => $cols) {
            $this->memDb->query("CREATE TABLE $table ($cols)");
        }

        $this->memDb->table('users')->insert(['id' => 1, 'name' => 'Counselor', 'role' => 1]);
        $this->memDb->table('users')->insert(['id' => 10, 'name' => 'Juan Dela Cruz', 'role' => 0]);
        $this->memDb->table('students')->insert([
            'user_id' => 10,
            'student_id' => 'SS-2026-10',
            'lrn' => '123456789012',
            'grade_level' => '9',
            'section' => 'Rizal',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => [$envVal, $srvVal, $getVal]) {
            if ($envVal === null) unset($_ENV[$key]); else $_ENV[$key] = $envVal;
            if ($srvVal === null) unset($_SERVER[$key]); else $_SERVER[$key] = $srvVal;
            if ($getVal === false) @putenv($key); else @putenv("$key=$getVal");
        }
        $_SERVER = $this->savedServer;

        $property = new \ReflectionProperty(Database::class, 'instances');
        $property->setAccessible(true);
        $property->setValue(null, $this->connections);
        $this->memDb->close();
        \CodeIgniter\Config\Factories::reset('config');
        Services::reset();
        parent::tearDown();
    }

    private function buildRequest(string $path, string $method = 'POST', array $post = [], bool $ajax = false): IncomingRequest
    {
        $config = config('App');
        $uri = new SiteURI($config, $path);
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_POST = $post;
        $request = new IncomingRequest($config, $uri, null, new UserAgent());
        $request->setMethod($method);
        if ($post !== []) {
            $request->setGlobal('post', $post);
        }
        if ($ajax) {
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        }
        return $request;
    }

    public function testDisabledWithoutApiKeyOrWhenExplicitlyOff(): void
    {
        $called = false;
        self::assertFalse(GroqVentClient::isEnabled());
        self::assertNull(GroqVentClient::analyze('test vent', static function () use (&$called) {
            $called = true;
            return ['httpCode' => 200, 'body' => '{}'];
        }));
        self::assertFalse($called);

        $_ENV['GROQ_API_KEY'] = $_SERVER['GROQ_API_KEY'] = 'gsk-test-key';
        $_ENV['GROQ_ENABLED'] = $_SERVER['GROQ_ENABLED'] = 'false';
        self::assertFalse(GroqVentClient::isEnabled());
        self::assertNull(GroqVentClient::analyze('test vent', static function () use (&$called) {
            $called = true;
            return ['httpCode' => 200, 'body' => '{}'];
        }));
        self::assertFalse($called);

        $_ENV['GROQ_ENABLED'] = $_SERVER['GROQ_ENABLED'] = '';
        $_ENV['GROQ_VENT_FALLBACK_ENABLED'] = $_SERVER['GROQ_VENT_FALLBACK_ENABLED'] = 'false';
        self::assertFalse(GroqVentClient::isEnabled());

        $_ENV['GROQ_VENT_FALLBACK_ENABLED'] = $_SERVER['GROQ_VENT_FALLBACK_ENABLED'] = 'true';
        $_ENV['GROQ_VENT_MODEL'] = $_SERVER['GROQ_VENT_MODEL'] = 'openai/gpt-oss-120b';
        self::assertTrue(GroqVentClient::isEnabled());
        self::assertSame('openai/gpt-oss-120b', GroqVentClient::model());
    }

    public function testAnalyzeBuildsPiiFreeChatCompletionsRequestAndClampsBands(): void
    {
        $_ENV['GROQ_API_KEY'] = $_SERVER['GROQ_API_KEY'] = 'gsk-test-key';
        $_ENV['GROQ_MODEL'] = $_SERVER['GROQ_MODEL'] = 'openai/gpt-oss-120b';
        $captured = [];

        $apiBody = json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'triage_category' => 'compound_distress_or_bullying',
                        'risk_level' => 'HIGH',
                        'risk_score' => 99, // Deliberately out-of-band; must clamp to 85
                        'sarcasm_or_hyperbole' => false,
                        'taglish_nuance' => 'Taglish exhaustion cue',
                        'counselor_reasoning' => 'Student conveys sustained emotional depletion.',
                    ]),
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = GroqVentClient::analyze('nakakapagod na lahat parang ayoko na pumasok', static function (string $endpoint, string $jsonBody, string $apiKey, int $timeout) use (&$captured, $apiBody) {
            $captured = compact('endpoint', 'jsonBody', 'apiKey', 'timeout');
            return ['httpCode' => 200, 'body' => $apiBody];
        });

        self::assertNotNull($result);
        self::assertSame('https://api.groq.com/openai/v1/chat/completions', $captured['endpoint']);
        self::assertSame('gsk-test-key', $captured['apiKey']);
        $decodedBody = json_decode($captured['jsonBody'], true);
        self::assertSame('openai/gpt-oss-120b', $decodedBody['model']);
        self::assertSame(1200, $decodedBody['max_completion_tokens']);
        self::assertSame('medium', $decodedBody['reasoning_effort']);
        self::assertSame(['type' => 'json_object'], $decodedBody['response_format']);
        self::assertStringContainsString('<student_vent>', $captured['jsonBody']);
        self::assertStringContainsString('DepEd DO 40 s.2012', $captured['jsonBody']);
        self::assertStringContainsString('patay ako kay mama', $captured['jsonBody']);
        self::assertStringContainsString('healed past NSSI in recovery', $captured['jsonBody']);
        self::assertStringContainsString('perceived burdensomeness', $captured['jsonBody']);
        self::assertStringNotContainsString('Juan Dela Cruz', $captured['jsonBody']);
        self::assertStringNotContainsString('SS-2026-10', $captured['jsonBody']);
        self::assertSame('groq', $result['source']);
        self::assertSame('HIGH', $result['risk_level']);
        self::assertSame(85, $result['risk_score']);
        self::assertStringStartsWith('[Clinical Review] ', $result['reasoning']);
        self::assertStringContainsString('Student conveys sustained emotional depletion', $result['reasoning']);
        self::assertStringContainsString('Taglish exhaustion cue', $result['reasoning']);
        self::assertDoesNotMatchRegularExpression('/\bgroq\b/i', $result['reasoning']);

        // C-SSRS Level 2 passive ideation must pin to HIGH (61-85, min 79) so parent alerts do not fire prematurely.
        $passiveBody = json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'triage_category' => 'passive_ideation_cssrs2',
                        'risk_level' => 'CRITICAL',
                        'risk_score' => 92,
                        'sarcasm_or_hyperbole' => false,
                        'taglish_nuance' => 'Passive invisibility idiom',
                        'counselor_reasoning' => 'Counselor follow-up warranted without parent alarm.',
                    ]),
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $passiveParsed = GroqVentClient::parseApiResponse($passiveBody);
        self::assertNotNull($passiveParsed);
        self::assertSame('HIGH', $passiveParsed['risk_level']);
        self::assertSame(85, $passiveParsed['risk_score']);
    }

    public function testVentControllerUsesGroqFallbackThenPersistsEncryptedExplanationAndAdminSource(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);

        $encConfig = new \Config\Encryption();
        $encConfig->key = str_repeat('g', 32);
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $encConfig);

        $auditActions = [];
        $controller = new class($auditActions) extends VentController {
            private array $logRef;
            public function __construct(array &$logRef)
            {
                $this->logRef = &$logRef;
            }
            protected function callPrimaryMlService(string $mlApiBase, string $mlApiKey, string $payload): array
            {
                return ['httpCode' => 503, 'data' => null];
            }
            protected function callGroqFallback(string $text): ?array
            {
                return [
                    'risk_level' => 'HIGH',
                    'risk_score' => 76,
                    'reasoning' => '[Clinical Review] Subtle Bisaya hopelessness detected.',
                    'flag_reason' => '[Clinical Review] Subtle Bisaya hopelessness detected.',
                    'source' => 'groq',
                ];
            }
            protected function logAudit(string $action, string $entityType, int $entityId): void
            {
                $this->logRef[] = $action;
            }
        };

        $req = $this->buildRequest('student/vent', 'POST', [
            'vent_text' => 'kapoy na kaayo diria murag wala na koy kusog',
            'emotions' => 'Tired',
        ], true);
        Services::injectMock('request', $req);
        $controller->initController($req, Services::response(), Services::logger());

        $res = $controller->submit();
        self::assertSame(200, $res->getStatusCode());
        $json = json_decode((string) $res->getBody(), true);
        self::assertSame('success', $json['status']);
        self::assertArrayNotHasKey('reasoning', $json);

        self::assertContains('CREATE_VENT', $auditActions);
        self::assertContains('VENT_ANALYSIS_GROQ', $auditActions);

        $row = $this->memDb->table('vent_logs')->get()->getRowArray();
        self::assertNotNull($row);
        self::assertSame('HIGH', $row['risk_level']);
        self::assertSame(76.0, (float) $row['risk_percent']);
        self::assertStringNotContainsString('Subtle Bisaya hopelessness', (string) $row['analysis_details']);

        $decoded = ScreeningExplanation::decode($row['analysis_details']);
        self::assertSame([
            'source' => 'groq',
            'reasoning' => '[Clinical Review] Subtle Bisaya hopelessness detected.',
        ], $decoded);

        // Verify counselor dashboard API returns analysis_source = 'groq' and decrypted screening_analysis.
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);
        $admin = new AdminController();
        $fetchReq = $this->buildRequest('admin/api/vents', 'GET');
        Services::injectMock('request', $fetchReq);
        Services::resetSingle('response');
        $admin->initController($fetchReq, Services::response(), Services::logger());
        $adminRes = $admin->fetchVents();
        $adminRows = json_decode((string) $adminRes->getBody(), true);
        self::assertSame('groq', $adminRows[0]['analysis_source']);
        self::assertSame('[Clinical Review] Subtle Bisaya hopelessness detected.', $adminRows[0]['screening_analysis']['reasoning']);
    }

    public function testVentControllerFallsBackToLocalKeywordsWhenPrimaryAndGroqAreBothUnavailable(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);

        $encConfig = new \Config\Encryption();
        $encConfig->key = str_repeat('g', 32);
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $encConfig);

        $auditActions = [];
        $controller = new class($auditActions) extends VentController {
            private array $logRef;
            public function __construct(array &$logRef)
            {
                $this->logRef = &$logRef;
            }
            protected function callPrimaryMlService(string $mlApiBase, string $mlApiKey, string $payload): array
            {
                return ['httpCode' => 502, 'data' => null];
            }
            protected function callGroqFallback(string $text): ?array
            {
                return null;
            }
            protected function logAudit(string $action, string $entityType, int $entityId): void
            {
                $this->logRef[] = $action;
            }
        };

        $req = $this->buildRequest('student/vent', 'POST', [
            'vent_text' => 'I want to kill myself tonight',
            'emotions' => 'Hopeless',
        ], true);
        Services::injectMock('request', $req);
        $controller->initController($req, Services::response(), Services::logger());

        $res = $controller->submit();
        self::assertSame(200, $res->getStatusCode());
        self::assertContains('VENT_ANALYSIS_LOCAL', $auditActions);

        $row = $this->memDb->table('vent_logs')->get()->getRowArray();
        self::assertSame('CRITICAL', $row['risk_level']);
        self::assertGreaterThanOrEqual(86.0, (float) $row['risk_percent']);
    }
}
