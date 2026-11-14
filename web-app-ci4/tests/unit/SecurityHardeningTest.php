<?php

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BullyReportController;
use App\Controllers\VentController;
use App\Filters\IpWhitelistFilter;
use App\Filters\RateLimitFilter;
use App\Libraries\AlertLog;
use App\Libraries\GuardianAlertRouting;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class SecurityHardeningTest extends CIUnitTestCase
{
    private $secDb;
    private array $connections;
    private array $savedEnv = [];
    private array $savedServer = [];
    private ?string $priorAlertsBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);

        $alertFile = AlertLog::path();
        $this->priorAlertsBackup = is_file($alertFile) ? (string) file_get_contents($alertFile) : null;
        @unlink($alertFile);

        foreach ([
            'ML_API_KEY', 'app.allowedHostnames', 'app.proxyIPs', 'app.proxyHeader',
            'COUNSELOR_EMAIL', 'COUNSELOR_PHONE', 'email.SMTPUser', 'email.SMTPPass',
            'ADMIN_ALLOWED_IPS', 'encryption.key',
        ] as $key) {
            $this->savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->savedServer = $_SERVER;

        $_ENV['ML_API_KEY'] = $_SERVER['ML_API_KEY'] = '';
        $_ENV['COUNSELOR_PHONE'] = $_SERVER['COUNSELOR_PHONE'] = '';
        $_ENV['COUNSELOR_EMAIL'] = $_SERVER['COUNSELOR_EMAIL'] = '';
        $_ENV['email.SMTPUser'] = $_SERVER['email.SMTPUser'] = '';
        $_ENV['email.SMTPPass'] = $_SERVER['email.SMTPPass'] = '';
        $_ENV['encryption.key'] = $_SERVER['encryption.key'] = str_repeat('k', 32);

        $this->secDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $property = new \ReflectionProperty(Database::class, 'instances');
        $property->setAccessible(true);
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->secDb]);

        foreach ([
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, password_hash TEXT, role INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, access_disabled INTEGER DEFAULT 0, registration_status TEXT DEFAULT "approved", auth_version INTEGER DEFAULT 1, remember_token TEXT, is_locked INTEGER DEFAULT 0, created_at TEXT',
            'students' => 'user_id INTEGER PRIMARY KEY, student_id TEXT, lrn TEXT UNIQUE, grade_level TEXT, section TEXT, parent_phone TEXT, parent_email TEXT, adviser_name TEXT, created_at TEXT',
            'counselors' => 'user_id INTEGER PRIMARY KEY, counselor_email TEXT, counselor_phone TEXT',
            'admins' => 'user_id INTEGER PRIMARY KEY, email TEXT, phone TEXT',
            'guardian_consents' => 'user_id INTEGER PRIMARY KEY, verified_by INTEGER, phone TEXT, verified_name TEXT, token_hash TEXT, notice TEXT, requested_at TEXT, expires_at TEXT, sms_state TEXT, response TEXT, guardian_name TEXT, responded_at TEXT',
            'guardian_alert_routes' => 'user_id INTEGER PRIMARY KEY, unsafe_guardian INTEGER DEFAULT 0, alternate_name TEXT, alternate_phone TEXT, alternate_email TEXT, verified_by INTEGER, verified_at TEXT, updated_at TEXT',
            'vent_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, raw_text TEXT, emotions TEXT, risk_percent REAL, risk_level TEXT, status TEXT, created_at TEXT, deleted_at TEXT',
            'bully_reports' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, reporter_student_id INTEGER, reporter_type TEXT, victim_student_id INTEGER, bully_student_id INTEGER, incident_details TEXT, status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'audit_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, ip_address TEXT, user_agent TEXT, created_at TEXT',
        ] as $table => $cols) {
            $this->secDb->query("CREATE TABLE $table ($cols)");
        }

        $this->secDb->table('users')->insert(['id' => 1, 'name' => 'Counselor', 'role' => 1, 'password_hash' => password_hash('StaffPass!9', PASSWORD_BCRYPT)]);
        $this->secDb->table('users')->insert(['id' => 2, 'name' => 'SuperAdmin', 'role' => 2, 'password_hash' => password_hash('StaffPass!9', PASSWORD_BCRYPT)]);
        $this->secDb->table('users')->insert(['id' => 3, 'name' => 'Principal', 'role' => 3, 'password_hash' => password_hash('StaffPass!9', PASSWORD_BCRYPT)]);
        $this->secDb->table('users')->insert(['id' => 10, 'name' => 'Safe Student', 'role' => 0]);
        $this->secDb->table('students')->insert([
            'user_id' => 10,
            'student_id' => 'SS-10',
            'lrn' => '100000000010',
            'grade_level' => '8',
            'section' => 'B',
            'parent_phone' => '+639181234567',
            'parent_email' => 'safe-parent@example.test',
        ]);
        $this->secDb->table('users')->insert(['id' => 11, 'name' => 'At-Risk Student', 'role' => 0]);
        $this->secDb->table('students')->insert([
            'user_id' => 11,
            'student_id' => 'SS-11',
            'lrn' => '100000000011',
            'grade_level' => '8',
            'section' => 'B',
            'parent_phone' => '+639191112222',
            'parent_email' => 'unsafe-parent@example.test',
        ]);
        $this->secDb->table('guardian_alert_routes')->insert([
            'user_id' => 11,
            'unsafe_guardian' => 1,
            'alternate_name' => 'Auntie Approved',
            'alternate_phone' => '+639203334444',
            'alternate_email' => 'auntie@example.test',
            'verified_by' => 1,
            'verified_at' => '2026-11-14 10:00:00',
        ]);
        $this->secDb->table('users')->insert(['id' => 12, 'name' => 'Blocked Unsafe Student', 'role' => 0]);
        $this->secDb->table('students')->insert([
            'user_id' => 12,
            'student_id' => 'SS-12',
            'lrn' => '100000000012',
            'grade_level' => '8',
            'section' => 'B',
            'parent_phone' => '+639195556666',
            'parent_email' => 'blocked-unsafe@example.test',
        ]);
        $this->secDb->table('guardian_alert_routes')->insert([
            'user_id' => 12,
            'unsafe_guardian' => 1,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $encConfig = new \Config\Encryption();
        $encConfig->key = str_repeat('k', 32);
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $encConfig);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            putenv($process === false ? $key : "$key=$process");
        }
        $_SERVER = $this->savedServer;

        $property = new \ReflectionProperty(Database::class, 'instances');
        $property->setAccessible(true);
        $property->setValue(null, $this->connections);
        \CodeIgniter\Config\Factories::reset('config');
        Services::resetSingle('encrypter');
        Services::resetSingle('request');
        Services::resetSingle('response');

        $alertFile = AlertLog::path();
        if ($this->priorAlertsBackup !== null) {
            file_put_contents($alertFile, $this->priorAlertsBackup);
        } else {
            @unlink($alertFile);
        }
        $rateDir = WRITEPATH . 'rate_limits/';
        if (is_dir($rateDir)) {
            foreach (glob($rateDir . '*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
        session()->destroy();
        parent::tearDown();
    }

    private function buildRequest(string $path, string $method = 'POST', array $post = [], string $ip = '192.0.2.50', bool $ajax = false): IncomingRequest
    {
        $config = new \Config\App();
        $config->proxyIPs = [];
        service('superglobals')->setServer('REMOTE_ADDR', $ip);
        service('superglobals')->setPostArray($post);
        $request = new IncomingRequest($config, new SiteURI($config, $path), null, new UserAgent());
        $request->setMethod($method);
        if ($ajax) {
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        }
        return $request;
    }

    public function testMissingOptionalSafetyTableDefaultsToStandardRoutingWhileQueryFailureStaysFailClosed(): void
    {
        $unmigratedDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => ''], false);
        try {
            $unmigratedDb->query('CREATE TABLE guardian_consents (user_id INTEGER PRIMARY KEY, phone TEXT)');
            $unmigratedDb->table('guardian_consents')->insert(['user_id' => 10, 'phone' => '+639170000111']);

            $settings = GuardianAlertRouting::settings($unmigratedDb, 10);
            $this->assertIsArray($settings);
            $this->assertSame(0, $settings['unsafe_guardian']);
            $route = GuardianAlertRouting::resolve([
                'parent_phone' => '+639181234567',
                'parent_email' => 'parent@example.test',
            ], $settings);
            $this->assertFalse($route['blocked']);
            $this->assertSame('+639170000111', $route['phone']);
            $this->assertSame('parent@example.test', $route['email']);
        } finally {
            $unmigratedDb->close();
        }

        $corruptDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => ''], false);
        try {
            // Table exists with incompatible columns so SELECT throws; must fail closed (null -> blocked).
            $corruptDb->query('CREATE TABLE guardian_alert_routes (wrong_col INTEGER)');
            $corruptSettings = GuardianAlertRouting::settings($corruptDb, 10);
            $this->assertNull($corruptSettings);
            $this->assertTrue(GuardianAlertRouting::resolve(['parent_phone' => '+639181234567'], $corruptSettings)['blocked']);
        } finally {
            $corruptDb->close();
        }
    }

    public function testVentAbortsOnEncryptionFailureWithoutStoringPlaintextAndHidesExceptionDetails(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);

        $badEncConfig = new \Config\Encryption();
        $badEncConfig->key = '';
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $badEncConfig);

        $controller = new class extends VentController {
            protected function logAudit(string $action, string $entityType, int $entityId): void {}
        };
        $req = $this->buildRequest('student/vent', 'POST', ['vent_text' => 'I feel overwhelmed today.'], '192.0.2.50', true);
        Services::injectMock('request', $req);
        $controller->initController($req, Services::response(), Services::logger());

        $res = $controller->submit();
        $this->assertSame(503, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('error', $body['status']);
        $this->assertStringContainsString('could not be encrypted', $body['message']);
        $this->assertStringNotContainsString('.php', $body['message']);
        $this->assertSame(0, $this->secDb->table('vent_logs')->countAllResults());

        // Restore working encryption key and verify fatal downstream throwable hides internal trace/details.
        $goodEncConfig = new \Config\Encryption();
        $goodEncConfig->key = str_repeat('k', 32);
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $goodEncConfig);
        $fatalController = new class extends VentController {
            protected function logAudit(string $action, string $entityType, int $entityId): void
            {
                throw new \RuntimeException('INTERNAL_STACK_TRACE_LEAK /secret/path.php:99');
            }
        };
        session()->remove('last_vent_time');
        Services::resetSingle('response');
        $fatalReq = $this->buildRequest('student/vent', 'POST', ['vent_text' => 'Simple feeling check-in.'], '192.0.2.50', true);
        Services::injectMock('request', $fatalReq);
        $fatalController->initController($fatalReq, Services::response(), Services::logger());
        $fatalRes = $fatalController->submit();
        $this->assertSame(500, $fatalRes->getStatusCode());
        $fatalBody = json_decode((string) $fatalRes->getBody(), true);
        $this->assertStringNotContainsString('INTERNAL_STACK_TRACE_LEAK', $fatalBody['message']);
        $this->assertStringNotContainsString('.php', $fatalBody['message']);
    }

    public function testBullyReportAbortsSecurelyWhenEncryptionFails(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);

        $badEncConfig = new \Config\Encryption();
        $badEncConfig->key = '';
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $badEncConfig);

        $controller = new BullyReportController();
        $req = $this->buildRequest('report-bullying/submit', 'POST', [
            'reporter_type' => 'self',
            'bully_student_id' => 'unknown',
            'bully_name' => 'Unknown Bully',
            'incident_details' => 'They pushed me in the hallway.',
        ]);
        Services::injectMock('request', $req);
        $controller->initController($req, Services::response(), Services::logger());

        $res = $controller->submit();
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('could not be encrypted', (string) session()->getFlashdata('error'));
        $this->assertSame(0, $this->secDb->table('bully_reports')->countAllResults());
    }

    public function testAlertCooldownUsesOneHourWindowAndFailedEmailsDoNotTriggerCooldown(): void
    {
        $controller = new VentController();
        $cooldownMethod = new \ReflectionMethod($controller, 'isAlertOnCooldown');
        $cooldownMethod->setAccessible(true);

        // 10 minutes ago accepted alert -> must STILL be on 1-hour cooldown (regression check for -1 minute bug).
        file_put_contents(AlertLog::path(), json_encode([
            [
                'type' => 'Email',
                'recipient' => 'recent@example.test',
                'message' => 'Sent',
                'timestamp' => date('Y-m-d H:i:s', time() - 600),
                'accepted' => true,
            ],
            [
                'type' => 'Email',
                'recipient' => 'expired@example.test',
                'message' => 'Sent',
                'timestamp' => date('Y-m-d H:i:s', time() - 3700),
                'accepted' => true,
            ],
        ]));
        $this->assertTrue($cooldownMethod->invoke($controller, 'recent@example.test'));
        $this->assertFalse($cooldownMethod->invoke($controller, 'expired@example.test'));

        // Failed email (e.g. missing SMTP creds) must record accepted=false and NOT activate cooldown.
        @unlink(AlertLog::path());
        $emailAlertMethod = new \ReflectionMethod($controller, 'triggerEmailAlert');
        $emailAlertMethod->setAccessible(true);
        $emailAlertMethod->invoke($controller, 'failed-smtp@example.test', 'At-Risk Student');

        $logged = json_decode((string) file_get_contents(AlertLog::path()), true);
        $this->assertIsArray($logged);
        $this->assertFalse($logged[0]['accepted']);
        $this->assertSame('Failed', $logged[0]['state']);
        $this->assertStringNotContainsString('At-Risk Student', $logged[0]['message']);
        $this->assertFalse($cooldownMethod->invoke($controller, 'failed-smtp@example.test'));
    }

    public function testConfigAppRejectsUntrustedHostHeaderAndSupportsProxyAndHttpsCookies(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil-phishing.example';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_CF_VISITOR'], $_SERVER['HTTPS']);
        $appWithEvilHost = new \Config\App();
        $this->assertSame('http://example.com/', $appWithEvilHost->baseURL);

        $_ENV['app.allowedHostnames'] = $_SERVER['app.allowedHostnames'] = 'portal.studentshield.ph';
        $_ENV['app.proxyIPs'] = $_SERVER['app.proxyIPs'] = '10.0.0.1, 10.0.0.2';
        $_SERVER['HTTP_HOST'] = 'portal.studentshield.ph';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $appAllowed = new \Config\App();
        $this->assertSame('https://portal.studentshield.ph/', $appAllowed->baseURL);
        $this->assertSame([
            '10.0.0.1' => 'X-Forwarded-For',
            '10.0.0.2' => 'X-Forwarded-For',
        ], $appAllowed->proxyIPs);

        $httpsCookie = new \Config\Cookie();
        $this->assertTrue($httpsCookie->secure);
    }

    public function testRateLimitFiltersCoverConsentTokensRegistrationAndDirectoryAndWhitelistHonorsEnv(): void
    {
        $filters = (new \Config\Filters())->filters['ratelimit']['before'];
        $this->assertContains('guardian-consent/*', $filters);
        $this->assertContains('register/process', $filters);
        $this->assertContains('report/bullying/directory', $filters);

        $rateFilter = new RateLimitFilter();

        // POST /guardian-consent/{token} must enforce limit of 10 attempts.
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($rateFilter->before($this->buildRequest('guardian-consent/abc123token', 'POST', [], '198.51.100.11')));
        }
        $blockedConsent = $rateFilter->before($this->buildRequest('guardian-consent/abc123token', 'POST', [], '198.51.100.11'));
        $this->assertNotNull($blockedConsent);
        $this->assertSame(302, $blockedConsent->getStatusCode());

        // GET /guardian-consent/{token} view remains unrestricted.
        $this->assertNull($rateFilter->before($this->buildRequest('guardian-consent/abc123token', 'GET', [], '198.51.100.11')));

        // POST /register/process limits enumeration at 10 attempts.
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($rateFilter->before($this->buildRequest('register/process', 'POST', [], '198.51.100.12')));
        }
        $blockedReg = $rateFilter->before($this->buildRequest('register/process', 'POST', [], '198.51.100.12'));
        $this->assertNotNull($blockedReg);
        $this->assertSame(302, $blockedReg->getStatusCode());
        $this->assertStringEndsWith('/register', $blockedReg->getHeaderLine('Location'));

        // GET /report/bullying/directory enforces 60 lookup ceiling with 429 JSON.
        for ($i = 0; $i < 60; $i++) {
            $this->assertNull($rateFilter->before($this->buildRequest('report/bullying/directory', 'GET', [], '198.51.100.13')));
        }
        $blockedDir = $rateFilter->before($this->buildRequest('report/bullying/directory', 'GET', [], '198.51.100.13'));
        $this->assertNotNull($blockedDir);
        $this->assertSame(429, $blockedDir->getStatusCode());

        // IpWhitelistFilter is a safe no-op unless ADMIN_ALLOWED_IPS is set, then enforces allowlist.
        $ipFilter = new IpWhitelistFilter();
        $this->assertNull($ipFilter->before($this->buildRequest('admin/dashboard', 'GET', [], '203.0.113.9')));

        $_ENV['ADMIN_ALLOWED_IPS'] = $_SERVER['ADMIN_ALLOWED_IPS'] = '203.0.113.50';
        putenv('ADMIN_ALLOWED_IPS=203.0.113.50');
        $this->assertNull($ipFilter->before($this->buildRequest('admin/dashboard', 'GET', [], '203.0.113.50')));
        $denied = $ipFilter->before($this->buildRequest('admin/dashboard', 'GET', [], '203.0.113.9'));
        $this->assertNotNull($denied);
        $this->assertSame(403, $denied->getStatusCode());
    }

    public function testSuperAdminAndPrincipalNeverFallBackToCounselorOtpInboxAndRegistrationIsUniform(): void
    {
        $_ENV['COUNSELOR_EMAIL'] = $_SERVER['COUNSELOR_EMAIL'] = 'counselor-only@example.test';
        $_ENV['email.SMTPUser'] = $_SERVER['email.SMTPUser'] = 'smtp-fallback@example.test';

        $probeController = new class extends AuthController {
            public array $sentTo = [];
            protected function sendOtpEmail(array $user, string $recipientEmail, string $otp): bool
            {
                $this->sentTo[] = $recipientEmail;
                return false;
            }
        };

        // Super Admin (role 2) & Principal (role 3) with empty admins.email MUST be rejected, never sent to COUNSELOR_EMAIL.
        foreach (['SuperAdmin', 'Principal'] as $staffName) {
            session()->destroy();
            $req = $this->buildRequest('admin/login/authenticate', 'POST', [
                'name' => $staffName,
                'password' => 'StaffPass!9',
            ]);
            Services::injectMock('request', $req);
            Services::resetSingle('response');
            $probeController->initController($req, Services::response(), Services::logger());
            $res = $probeController->authenticateAdmin();
            $this->assertSame(302, $res->getStatusCode());
            $this->assertFalse(session()->has('otp'));
            $this->assertSame([], $probeController->sentTo);
        }

        // Test resendOtp also refuses fallback when role !== 1 lacks admins.email.
        session()->set([
            'temp_user_id' => 2,
            'otp' => '123456',
            'otp_resend_time' => time() - 120,
            'otp_resend_count' => 0,
        ]);
        $resendReq = $this->buildRequest('admin/resend-otp', 'POST', []);
        Services::injectMock('request', $resendReq);
        Services::resetSingle('response');
        $probeController->initController($resendReq, Services::response(), Services::logger());
        $resendRes = $probeController->resendOtp();
        $this->assertStringEndsWith('/admin/login', $resendRes->getHeaderLine('Location'));
        $this->assertFalse(session()->has('otp'));
        $this->assertSame([], $probeController->sentTo);

        // Duplicate LRN and duplicate name return the exact same error message (anti-enumeration).
        $dupLrnReq = $this->buildRequest('register/process', 'POST', [
            'name' => 'Brand New Name',
            'lrn' => '100000000010',
            'grade_level' => '8',
            'section' => 'B',
            'parent_contact' => '09171234567',
            'password' => 'StrongPass!1',
            'password_confirm' => 'StrongPass!1',
            'terms' => '1',
        ]);
        Services::injectMock('request', $dupLrnReq);
        Services::resetSingle('response');
        $probeController->initController($dupLrnReq, Services::response(), Services::logger());
        $probeController->processStudentRegistration();
        $lrnError = (string) session()->getFlashdata('error');

        $dupNameReq = $this->buildRequest('register/process', 'POST', [
            'name' => 'Safe Student',
            'lrn' => '999999999999',
            'grade_level' => '8',
            'section' => 'B',
            'parent_contact' => '09171234567',
            'password' => 'StrongPass!1',
            'password_confirm' => 'StrongPass!1',
            'terms' => '1',
        ]);
        Services::injectMock('request', $dupNameReq);
        Services::resetSingle('response');
        $probeController->initController($dupNameReq, Services::response(), Services::logger());
        $probeController->processStudentRegistration();
        $nameError = (string) session()->getFlashdata('error');

        $this->assertSame($lrnError, $nameError);
        $this->assertStringNotContainsString('LRN', $lrnError);
    }

    public function testAdminApiRedactsUnsafeGuardianContactsAndHardensEvidenceHeaders(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);
        $encrypter = $this->createMock(EncrypterInterface::class);
        $encrypter->method('decrypt')->willReturn('decrypted text');
        Services::injectMock('encrypter', $encrypter);

        $this->secDb->table('vent_logs')->insert([
            'id' => 101, 'user_id' => 10, 'raw_text' => bin2hex('x'), 'risk_percent' => 90, 'risk_level' => 'CRITICAL', 'status' => 'Pending', 'created_at' => '2026-11-14 08:00:00',
        ]);
        $this->secDb->table('vent_logs')->insert([
            'id' => 102, 'user_id' => 11, 'raw_text' => bin2hex('y'), 'risk_percent' => 95, 'risk_level' => 'CRITICAL', 'status' => 'Pending', 'created_at' => '2026-11-14 09:00:00',
        ]);
        $this->secDb->table('vent_logs')->insert([
            'id' => 103, 'user_id' => 12, 'raw_text' => bin2hex('z'), 'risk_percent' => 95, 'risk_level' => 'CRITICAL', 'status' => 'Pending', 'created_at' => '2026-11-14 10:00:00',
        ]);

        $this->secDb->table('bully_reports')->insert([
            'id' => 201, 'reporter_student_id' => 11, 'incident_details' => bin2hex('r1'), 'status' => 'pending_review', 'created_at' => '2026-11-14 10:00:00',
        ]);

        $admin = new AdminController();
        $ventsReq = $this->buildRequest('admin/api/vents', 'GET');
        Services::injectMock('request', $ventsReq);
        Services::resetSingle('response');
        $admin->initController($ventsReq, Services::response(), Services::logger());
        $vents = json_decode((string) $admin->fetchVents()->getBody(), true);

        $byId = array_column($vents, null, 'id');
        $this->assertSame('+639181234567', $byId[101]['parent_phone']);
        $this->assertSame('+639203334444', $byId[102]['parent_phone']);
        $this->assertSame('auntie@example.test', $byId[102]['parent_email']);
        $this->assertNull($byId[103]['parent_phone']);
        $this->assertNull($byId[103]['parent_email']);

        $incReq = $this->buildRequest('admin/api/incidents', 'GET');
        Services::injectMock('request', $incReq);
        Services::resetSingle('response');
        $admin->initController($incReq, Services::response(), Services::logger());
        $incRes = $admin->fetchIncidents();
        $this->assertStringContainsString('no-store', $incRes->getHeaderLine('Cache-Control'));
        $incidents = json_decode((string) $incRes->getBody(), true);
        $this->assertSame('+639203334444', $incidents[0]['parent_phone']);
        $this->assertSame('auntie@example.test', $incidents[0]['parent_email']);

        // Evidence delivery headers: PNG inline + nosniff + no-store; SVG/HTML forced attachment + octet-stream.
        $dir = WRITEPATH . 'uploads/evidence/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $pngName = 'test_hardening_' . bin2hex(random_bytes(4)) . '.png';
        $svgName = 'test_hardening_' . bin2hex(random_bytes(4)) . '.svg';
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        file_put_contents($dir . $pngName, $pngBytes);
        file_put_contents($dir . $svgName, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        try {
            Services::resetSingle('response');
            $admin->initController($this->buildRequest('admin/evidence/' . $pngName, 'GET'), Services::response(), Services::logger());
            $pngRes = $admin->evidence($pngName);
            $this->assertSame('nosniff', $pngRes->getHeaderLine('X-Content-Type-Options'));
            $this->assertStringContainsString('no-store', $pngRes->getHeaderLine('Cache-Control'));
            $this->assertSame('image/png', $pngRes->getHeaderLine('Content-Type'));
            $this->assertStringStartsWith('inline;', $pngRes->getHeaderLine('Content-Disposition'));

            Services::resetSingle('response');
            $admin->initController($this->buildRequest('admin/evidence/' . $svgName, 'GET'), Services::response(), Services::logger());
            $svgRes = $admin->evidence($svgName);
            $this->assertSame('nosniff', $svgRes->getHeaderLine('X-Content-Type-Options'));
            $this->assertStringContainsString('no-store', $svgRes->getHeaderLine('Cache-Control'));
            $this->assertSame('application/octet-stream', $svgRes->getHeaderLine('Content-Type'));
            $this->assertStringStartsWith('attachment;', $svgRes->getHeaderLine('Content-Disposition'));
        } finally {
            @unlink($dir . $pngName);
            @unlink($dir . $svgName);
        }
    }
}
