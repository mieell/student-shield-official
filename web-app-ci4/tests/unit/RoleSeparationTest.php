<?php
namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Controllers\UserController;
use App\Controllers\PrincipalController;
use App\Filters\AuthFilter;
use App\Libraries\AuditTrail;
use App\Libraries\RoleAccess;
use App\Libraries\SchoolAnalytics;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class RoleSeparationTest extends CIUnitTestCase
{
    private $rbacDb;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['staffLogin.otpEnabled'] = 'true';
        helper(['url', 'form']);
        $this->rbacDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $property = new \ReflectionProperty(Database::class, 'instances');
        $property->setAccessible(true);
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->rbacDb]);
        foreach ([
            'users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, password_hash TEXT, role INTEGER, is_active INTEGER DEFAULT 1, access_disabled INTEGER DEFAULT 0, registration_status TEXT DEFAULT "approved", auth_version INTEGER DEFAULT 1, remember_token TEXT, is_locked INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT',
            'students' => 'user_id INTEGER PRIMARY KEY, student_id TEXT, lrn TEXT UNIQUE, grade_level TEXT, section TEXT, parent_email TEXT, parent_phone TEXT, adviser_name TEXT',
            'counselors' => 'user_id INTEGER PRIMARY KEY, counselor_email TEXT, counselor_phone TEXT',
            'admins' => 'user_id INTEGER PRIMARY KEY, email TEXT, phone TEXT',
            'vent_logs' => 'id INTEGER PRIMARY KEY, user_id INTEGER, risk_level TEXT, status TEXT, created_at TEXT, deleted_at TEXT, raw_text TEXT',
            'bully_reports' => 'id INTEGER PRIMARY KEY, reporter_student_id INTEGER, victim_student_id INTEGER, status TEXT, created_at TEXT, deleted_at TEXT, incident_details TEXT',
            'audit_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, ip_address TEXT, user_agent TEXT, created_at TEXT',
        ] as $table => $fields) $this->rbacDb->query("CREATE TABLE $table ($fields)");
        foreach ([[1, 1], [2, 2], [3, 3], [10, 0], [11, 0], [12, 0]] as [$id, $role]) {
            $this->rbacDb->table('users')->insert(['id' => $id, 'name' => $role ? 'Staff ' . $id : 'PRIVATE_STUDENT_' . $id, 'password_hash' => 'private_hash', 'role' => $role, 'created_at' => $id === 11 ? '2026-10-01 10:00:00' : '2026-10-15 10:00:00']);
        }
        foreach ([[10, '7', 'A'], [11, '8', 'B'], [12, '7', 'A']] as [$id, $grade, $section]) $this->rbacDb->table('students')->insert(['user_id' => $id, 'student_id' => 'PRIVATE_ID_' . $id, 'grade_level' => $grade, 'section' => $section, 'parent_email' => 'private@example.test']);
        $this->rbacDb->table('users')->where('id', 12)->update(['is_active' => 0, 'registration_status' => 'pending']);
        foreach ([[1, 10, 'CRITICAL', 'Pending', '2026-10-18 09:00:00', null], [2, 10, 'HIGH', 'Reviewed', '2026-10-17 10:00:00', null], [3, 11, 'LOW', 'Pending', '2026-10-10 10:00:00', null], [4, 12, 'HIGH', 'Flagged', '2026-10-18 10:00:00', null], [5, 10, 'CRITICAL', 'Pending', '2026-10-18 10:00:00', '2026-10-18']] as [$id, $student, $risk, $state, $date, $deleted]) {
            $this->rbacDb->table('vent_logs')->insert(['id' => $id, 'user_id' => $student, 'risk_level' => $risk, 'status' => $state, 'created_at' => $date, 'deleted_at' => $deleted, 'raw_text' => 'PRIVATE_TEXT']);
        }
        $this->rbacDb->table('bully_reports')->insert(['id' => 1, 'reporter_student_id' => 11, 'victim_student_id' => 10, 'status' => 'pending_review', 'created_at' => '2026-10-18 11:00:00', 'incident_details' => 'PRIVATE_INCIDENT']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['staffLogin.otpEnabled']);
        session()->destroy();
        $property = new \ReflectionProperty(Database::class, 'instances'); $property->setAccessible(true); $property->setValue(null, $this->connections);
        parent::tearDown();
    }

    private function login(int $role): void
    {
        session()->set(['isLoggedIn' => true, 'role' => $role, 'user_id' => $role === 0 ? 10 : $role, 'auth_version' => 1, 'last_activity' => time(), 'denial_logged_at' => time()]);
    }

    private function request(string $path, string $method = 'GET', array $post = [])
    {
        $config = new \Config\App(); $config->proxyIPs = [];
        service('superglobals')->setServer('REMOTE_ADDR', '192.0.2.10');
        service('superglobals')->setPostArray($post);
        $request = new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, $path), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod($method); $request->setHeader('Accept', 'application/json');
        Services::injectMock('request', $request);
        return $request;
    }

    private function controller(string $class, string $path, array $post = [])
    {
        Services::resetSingle('response'); $controller = new $class();
        $controller->initController($this->request($path, 'POST', $post), Services::response(), Services::logger());
        return $controller;
    }

    public function testRegistrationNotificationTracksOnlyPendingStudents(): void
    {
        $this->login(1);
        $path = 'admin/api/pending-registrations';
        $this->assertTrue(RoleAccess::allows(1, $path));
        $response = $this->controller(AdminController::class, $path)->pendingRegistrationCount();
        $this->assertSame(['count' => 1], json_decode($response->getBody(), true));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->rbacDb->table('users')->where('id', 12)->update(['registration_status' => 'approved']);
        $this->rbacDb->table('users')->where('id', 2)->update(['registration_status' => 'pending']);
        $response = $this->controller(AdminController::class, $path)->pendingRegistrationCount();
        $this->assertSame(['count' => 0], json_decode($response->getBody(), true));
        $this->rbacDb->table('users')->where('id', 10)->update(['registration_status' => 'pending']);
        $response = $this->controller(AdminController::class, $path)->pendingRegistrationCount();
        $this->assertSame(['count' => 1], json_decode($response->getBody(), true));
        foreach ([0, 2, 3] as $role) {
            $this->login($role);
            $this->assertFalse(RoleAccess::allows($role, $path));
            $this->assertSame(403, $this->controller(AdminController::class, $path)->pendingRegistrationCount()->getStatusCode());
        }
        session()->remove('isLoggedIn');
        $this->assertSame(403, $this->controller(AdminController::class, $path)->pendingRegistrationCount()->getStatusCode());
    }

    public function testStaffProfileIsOwnAccountOnlyWithNoHelp(): void
    {
        foreach ([1, 2, 3] as $role) {
            $this->login($role);
            $this->assertTrue(RoleAccess::allows($role, 'staff/profile'));
            $this->assertFalse(RoleAccess::allows($role, 'staff/help'));
            $this->assertTrue(RoleAccess::allows($role, 'staff/settings'));
            $html = $this->controller(\App\Controllers\StaffAccountController::class, 'staff/profile')->profile();
            $this->assertStringNotContainsString('PRIVATE_STUDENT_', $html);
            $this->assertStringNotContainsString('/staff/help', $html);
        }
        $this->login(0);
        $this->assertFalse(RoleAccess::allows(0, 'staff/profile'));
        $this->assertSame(403, $this->controller(\App\Controllers\StaffAccountController::class, 'staff/profile')->profile()->getStatusCode());
    }

    public function testStaffCanUpdateOnlyTheirOwnNameAndPhone(): void
    {
        foreach ([1, 2, 3] as $role) {
            $this->login($role);
            $this->rbacDb->table('users')->where('id', $role)->update(['password_hash' => password_hash('ProfilePassword!9', PASSWORD_DEFAULT)]);
            $this->controller(\App\Controllers\StaffAccountController::class, 'staff/profile', [
                'name' => 'Updated Staff ' . $role, 'phone' => '09123456789', 'current_password' => 'ProfilePassword!9',
                'user_id' => 10, 'role' => 2, 'email' => 'attacker@example.test',
            ])->updateProfile();
            $user = $this->rbacDb->table('users')->where('id', $role)->get()->getRowArray();
            $this->assertSame('Updated Staff ' . $role, $user['name']);
            $this->assertSame($role, (int) $user['role']);
            $this->assertSame($user['name'], session()->get('name'));
            $contact = $this->rbacDb->table($role === 1 ? 'counselors' : 'admins')->where('user_id', $role)->get()->getRowArray();
            $this->assertSame('09123456789', $contact[$role === 1 ? 'counselor_phone' : 'phone']);
            $this->assertNotSame('attacker@example.test', $contact[$role === 1 ? 'counselor_email' : 'email']);
        }
        $this->assertSame('PRIVATE_STUDENT_10', $this->rbacDb->table('users')->where('id', 10)->get()->getRowArray()['name']);
    }

    public function testProfileRejectsWrongPasswordDuplicateNameAndStudentRole(): void
    {
        $this->login(1);
        $this->rbacDb->table('users')->where('id', 1)->update(['password_hash' => password_hash('ProfilePassword!9', PASSWORD_DEFAULT)]);
        foreach ([['New name', 'wrong'], ['Staff 2', 'ProfilePassword!9'], ['', 'ProfilePassword!9']] as [$name, $password]) {
            $this->controller(\App\Controllers\StaffAccountController::class, 'staff/profile', ['name' => $name, 'current_password' => $password])->updateProfile();
            $this->assertSame('Staff 1', $this->rbacDb->table('users')->where('id', 1)->get()->getRowArray()['name']);
        }
        $this->login(0);
        $this->assertSame(403, $this->controller(\App\Controllers\StaffAccountController::class, 'staff/profile')->updateProfile()->getStatusCode());
    }

    public function testSuperAdminReceivesOnlyMaskedLrn(): void
    {
        $this->rbacDb->table('students')->where('user_id', 10)->update(['lrn' => '123456789012']);
        $this->login(2);
        $html = $this->controller(UserController::class, 'admin/users')->index();
        $this->assertStringContainsString('9012', $html);
        $this->assertStringNotContainsString('123456789012', $html);
        $this->assertStringNotContainsString('private@example.test', $html);
    }

    public function testCreateAccountWorksWithoutOptionalUpdatedAtColumn(): void
    {
        $this->rbacDb->query('ALTER TABLE users DROP COLUMN updated_at');
        $this->login(2);
        $this->controller(UserController::class, 'admin/users/create', ['name' => 'New Counselor', 'role' => '1', 'passcode' => 'ExamplePassword!9', 'email' => 'staff@example.test'])->create();
        $user = $this->rbacDb->table('users')->where('name', 'New Counselor')->get()->getRowArray();
        $this->assertNotNull($user, 'Super Admin must be able to create an account without optional timestamp columns');
        $this->assertSame('staff@example.test', $this->rbacDb->table('counselors')->where('user_id', $user['id'])->get()->getRowArray()['counselor_email']);
    }

    public function testRegistrationRequiresUniqueTwelveDigitLrnAndKeepsLeadingZero(): void
    {
        $post = ['name' => 'Registration fixture', 'lrn' => '012345678901', 'grade_level' => '8', 'section' => 'A', 'parent_contact' => 'guardian@example.test', 'password' => 'Example!234A', 'password_confirm' => 'Example!234A', 'terms' => '1'];
        $before = $this->rbacDb->table('users')->countAllResults();
        foreach (['', '123', '12345678901x', '1234567890123'] as $invalid) {
            $this->controller(\App\Controllers\AuthController::class, 'register/process', array_replace($post, ['lrn' => $invalid]))->processStudentRegistration();
            $this->assertSame($before, $this->rbacDb->table('users')->countAllResults());
        }
        $response = $this->controller(\App\Controllers\AuthController::class, 'register/process', $post)->processStudentRegistration();
        $this->assertStringEndsWith('/login', $response->getHeaderLine('Location'));
        $student = $this->rbacDb->table('students')->where('lrn', $post['lrn'])->get()->getRowArray();
        $this->assertSame($post['lrn'], $student['lrn']);
        $this->assertStringStartsWith('SS-', $student['student_id']);
        $this->controller(\App\Controllers\AuthController::class, 'register/process', array_replace($post, ['name' => 'Duplicate LRN']))->processStudentRegistration();
        $this->assertSame($before + 1, $this->rbacDb->table('users')->countAllResults());
    }

    public function testEveryRoleIsDeniedOnOtherRolesDirectUrlsAndApis(): void
    {
        $resources = ['admin/api/vents' => [1], 'admin/api/incidents' => [1], 'admin/api/student-profile/10' => [1], 'admin/api/case-summary/10' => [1], 'admin/evidence/test.png' => [1], 'admin/vent/delete/1' => [1], 'admin/approve-student/12' => [1], 'admin/reject-student/12' => [1], 'admin/users' => [2], 'admin/users/access/10' => [2], 'admin/audit-logs' => [2], 'principal/dashboard' => [3], 'principal/api/analytics' => [3], 'principal/reports/export' => [3], 'student/vent' => [0], 'report-bullying/submit' => [0], 'meeting/join/1' => [0, 1]];
        foreach ([0, 1, 2, 3] as $role) foreach ($resources as $path => $allowed) {
            $this->login($role);
            $response = (new AuthFilter())->before($this->request($path));
            if (in_array($role, $allowed, true)) $this->assertNull($response, "$role should reach $path");
            else $this->assertSame(403, $response->getStatusCode(), "$role should not reach $path");
        }
    }

    public function testSessionAndRememberCookieCannotBypassRevocation(): void
    {
        $this->login(1);
        $this->rbacDb->table('users')->where('id', 1)->update(['role' => 2]);
        $this->assertSame(401, (new AuthFilter())->before($this->request('admin/api/vents'))->getStatusCode());
        $this->login(2);
        $this->rbacDb->table('users')->where('id', 2)->update(['auth_version' => 2]);
        $this->assertSame(401, (new AuthFilter())->before($this->request('admin/users'))->getStatusCode());
        $this->assertFalse(RoleAccess::enabled(['role' => 0, 'is_active' => 1, 'registration_status' => 'rejected']));
        $this->assertFalse(RoleAccess::enabled(['role' => 3, 'access_disabled' => 1]));
        session()->destroy();
        $this->rbacDb->table('users')->where('id', 10)->update(['remember_token' => password_hash('token', PASSWORD_BCRYPT), 'registration_status' => 'rejected']);
        $_COOKIE['student_remember'] = base64_encode('10:token');
        try { $this->assertSame(401, (new AuthFilter())->before($this->request('student/vent'))->getStatusCode()); }
        finally { unset($_COOKIE['student_remember']); }
    }

    public function testCounselorDecisionsAreAtomicRetainedAndAudited(): void
    {
        $this->login(2);
        $this->assertSame(403, $this->controller(AdminController::class, 'admin/reject-student/12')->rejectStudent(12)->getStatusCode());
        $this->login(1);
        $this->assertSame(200, $this->controller(AdminController::class, 'admin/reject-student/12')->rejectStudent(12)->getStatusCode());
        $user = $this->rbacDb->table('users')->where('id', 12)->get()->getRowArray();
        $this->assertSame('rejected', $user['registration_status']); $this->assertSame(0, (int) $user['is_active']);
        $this->assertSame(1, $this->rbacDb->table('students')->where('user_id', 12)->countAllResults());
        $this->assertSame(409, $this->controller(AdminController::class, 'admin/approve-student/12')->approveStudent(12)->getStatusCode());
        $log = $this->rbacDb->table('audit_logs')->get()->getRowArray();
        $this->assertSame('STUDENT_REGISTRATION_REJECTED', $log['action']);
        $this->assertSame('192.0.2.10', $log['ip_address']);
        $this->assertSame(12, (int) $log['entity_id']);
    }

    public function testPrincipalUsesFilteredSqlAggregatesWithoutPrivateFields(): void
    {
        $today = new \DateTimeImmutable('2026-10-18', new \DateTimeZone('Asia/Manila'));
        $analytics = new SchoolAnalytics($this->rbacDb);
        $report = $analytics->summary(SchoolAnalytics::filters(['period' => 'last7'], $today));
        $this->assertSame(4, $report['metrics']['events']);
        $this->assertSame(3, $report['metrics']['today_count']);
        $this->assertSame(2, $report['metrics']['attention']);
        $this->assertSame(2, $report['metrics']['registered']);
        $this->assertSame(1, $report['metrics']['active']);
        $this->assertSame(1, $report['previous_events']);
        $this->assertSame(300.0, $report['change_percent']);
        $this->assertCount(7, $report['groups']['trend']);
        $filtered = $analytics->summary(SchoolAnalytics::filters(['period' => 'last7', 'grade' => '7', 'section' => 'A', 'risk' => 'CRITICAL'], $today));
        $this->assertSame(1, $filtered['metrics']['events']); $this->assertSame(1, $filtered['metrics']['registered']);
        $this->assertSame([['label' => 'CRITICAL', 'total' => 1]], $filtered['groups']['risk']);
        $this->assertStringNotContainsString('PRIVATE_', json_encode($report));
        $this->assertStringNotContainsString('private@example', json_encode($report));
        $bullying = $analytics->summary(SchoolAnalytics::filters(['period' => 'last7', 'category' => 'Bullying'], $today));
        $this->assertSame(1, $bullying['metrics']['events']);
        $this->assertSame([], $bullying['groups']['risk']);
        $empty = $analytics->summary(SchoolAnalytics::filters(['period' => 'last7', 'section' => "' OR 1=1 --"], $today));
        $this->assertSame(0, $empty['metrics']['events']);
    }

    public function testAuditIpDoesNotTrustArbitraryForwardedHeader(): void
    {
        $this->login(2); $request = $this->request('admin/users');
        $request->setHeader('X-Forwarded-For', '203.0.113.99'); $request->setHeader('CF-Connecting-IP', '203.0.113.99');
        AuditTrail::record('UPDATE_USER', 'user', 10);
        $log = $this->rbacDb->table('audit_logs')->get()->getRowArray();
        $this->assertSame('192.0.2.10', $log['ip_address']);
        $this->assertArrayNotHasKey('details', $log);
    }

    public function testAccountScreenAndRoleMenusExcludeCounselingAndGuardianDetails(): void
    {
        $this->login(2);
        $html = $this->controller(UserController::class, 'admin/users')->index();
        $this->assertStringNotContainsString('private@example', $html);
        $this->assertStringContainsString('PRIVATE_ID_', $html);
        $this->assertStringContainsString('PRIVATE_STUDENT_', $html);
        $this->assertStringNotContainsString('PRIVATE_TEXT', $html);
        $this->assertStringNotContainsString('PRIVATE_INCIDENT', $html);
        foreach ([2, 3] as $role) {
            $this->login($role);
            $nav = view('partials/staff_navigation');
            foreach (['admin/vents', 'admin/incidents', 'admin/pending-registrations', 'admin/sessions'] as $path) $this->assertStringNotContainsString($path, $nav);
        }
        $this->login(2);
        $this->assertSame(403, $this->controller(PrincipalController::class, 'principal/api/analytics')->analytics()->getStatusCode());
    }

    public function testDatePresetsAndInvalidRanges(): void
    {
        $today = new \DateTimeImmutable('2026-10-18');
        foreach (['today' => '2026-10-18', 'yesterday' => '2026-10-17', 'last7' => '2026-10-12', 'last30' => '2026-09-19', 'week' => '2026-10-12', 'month' => '2026-10-01'] as $preset => $start) $this->assertSame($start, SchoolAnalytics::filters(['period' => $preset], $today)['start']);
        $this->expectException(\InvalidArgumentException::class);
        SchoolAnalytics::filters(['period' => 'custom', 'start' => '2026-10-19', 'end' => '2026-02-30'], $today);
    }

    public function testPrincipalAccountCreationAndAccountEnableCannotApproveRegistration(): void
    {
        $this->login(2);
        $controller = $this->controller(UserController::class, 'admin/users/create', ['name' => 'Principal account', 'role' => '3', 'passcode' => 'ExamplePassword!9', 'email' => 'principal@example.test']);
        $this->assertSame(302, $controller->create()->getStatusCode());
        $principal = $this->rbacDb->table('users')->where('name', 'Principal account')->get()->getRowArray();
        $this->assertSame(3, (int) $principal['role']);
        $this->assertTrue(password_verify('ExamplePassword!9', $principal['password_hash']));
        $this->assertSame('principal@example.test', $this->rbacDb->table('admins')->where('user_id', $principal['id'])->get()->getRowArray()['email']);
        $this->assertSame(0, $this->rbacDb->table('students')->where('user_id', $principal['id'])->countAllResults());
        $this->controller(UserController::class, 'admin/users/access/12', ['disabled' => '0'])->access(12);
        $student = $this->rbacDb->table('users')->where('id', 12)->get()->getRowArray();
        $this->assertSame('pending', $student['registration_status']);
        $this->assertFalse(RoleAccess::enabled($student));
        $this->assertSame(409, $this->controller(UserController::class, 'admin/users/access/2', ['disabled' => '1'])->access(2)->getStatusCode());
    }

    public function testControllerGuardsDenyClinicalDataEvenWithoutFrontendOrRouteDispatch(): void
    {
        foreach ([2, 3] as $role) {
            $this->login($role);
            foreach (['fetchVents', 'fetchIncidents', 'fetchAlertLogs', 'getPendingMeetings'] as $method) {
                $this->assertGreaterThanOrEqual(400, $this->controller(AdminController::class, 'admin/api/vents')->$method()->getStatusCode());
            }
            $this->assertGreaterThanOrEqual(400, $this->controller(AdminController::class, 'admin/api/student-profile/10')->getStudentProfile(10)->getStatusCode());
            $this->assertGreaterThanOrEqual(400, $this->controller(\App\Controllers\CaseSummaryController::class, 'admin/api/case-summary/10')->apiData(10)->getStatusCode());
            $this->assertSame(403, $this->controller(\App\Controllers\VirtualMeetingController::class, 'admin/case-summary/10/meeting/start')->start(10)->getStatusCode());
        }
    }

    public function testOtpAccountRecheckRejectsDeactivatedPrincipal(): void
    {
        session()->set(['temp_user_id' => 3, 'temp_role' => 3, 'temp_auth_version' => 1, 'otp' => '654321', 'otp_expires' => time() + 300]);
        $this->rbacDb->table('users')->where('id', 3)->update(['access_disabled' => 1]);
        $response = $this->controller(\App\Controllers\AuthController::class, 'admin/verify-otp', ['otp' => '654321'])->verifyOtp();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse((bool) session()->get('isLoggedIn'));
        $this->assertFalse(session()->has('otp'));
    }

    public function testApproveRegistrationEnablesOnlyPendingStudents(): void
    {
        $this->login(1);
        $response = $this->controller(AdminController::class, 'admin/approve-student/12')->approveStudent(12);
        $this->assertSame(200, $response->getStatusCode());
        $student = $this->rbacDb->table('users')->where('id', 12)->get()->getRowArray();
        $this->assertTrue(RoleAccess::enabled($student));
        $this->assertSame(409, $this->controller(AdminController::class, 'admin/approve-student/2')->approveStudent(2)->getStatusCode());
        $this->assertSame('STUDENT_APPROVED', $this->rbacDb->table('audit_logs')->get()->getRowArray()['action']);
    }

    public function testSensitiveRoutesKeepAuthAndRegistrationActionsRequirePost(): void
    {
        $routes = service('routes'); $routes->loadRoutes();
        $this->assertFalse($routes->shouldAutoRoute());
        $get = $routes->getRoutes('GET'); $post = $routes->getRoutes('POST');
        foreach (['admin/api/vents', 'admin/api/student-profile/([0-9]+)', 'principal/dashboard', 'principal/api/analytics', 'principal/reports/export', 'admin/users', 'admin/audit-logs'] as $path) {
            $this->assertArrayHasKey($path, $get);
            $this->assertContains('auth', $routes->getFiltersForRoute($path, 'GET'));
        }
        foreach (['admin/approve-student/([0-9]+)', 'admin/reject-student/([0-9]+)'] as $path) {
            $this->assertArrayNotHasKey($path, $get);
            $this->assertArrayHasKey($path, $post);
            $this->assertContains('auth', $routes->getFiltersForRoute($path, 'POST'));
        }
        foreach (['GET', 'POST'] as $verb) foreach ($routes->getRoutes($verb) as $pattern => $handler) {
            if (!in_array('auth', $routes->getFiltersForRoute($pattern, $verb), true)) continue;
            $path = str_replace(['([0-9]+)', '([^/]+)', '(.*)', '(.+)'], ['10', 'fixture.png', 'dashboard', 'dashboard'], $pattern);
            $this->assertNotEmpty(array_filter([0, 1, 2, 3], static fn ($role) => RoleAccess::allows($role, $path)), 'Protected route missing from permission policy: ' . $path);
        }
        foreach ([0, 1, 2, 3] as $role) $this->assertFalse(RoleAccess::allows($role, 'admin/unknown-private-resource'));
    }

    public function testBrowserOnWrongDashboardReturnsToOwnWorkspace(): void
    {
        foreach ([1, 2, 3] as $role) {
            $this->login($role);
            $request = $this->request('student/dashboard');
            $request->setHeader('Accept', 'text/html');
            $response = (new AuthFilter())->before($request);
            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringEndsWith(RoleAccess::home($role), $response->getHeaderLine('Location'));
        }
    }

    public function testSharedLoginAcceptsStudentIdAndLeadingZeroLrn(): void
    {
        $this->rbacDb->table('users')->where('id', 10)->update(['password_hash' => password_hash('LoginPassword!9', PASSWORD_DEFAULT)]);
        $this->rbacDb->table('students')->where('user_id', 10)->update(['lrn' => '012345678901']);
        foreach (['PRIVATE_ID_10', '012345678901'] as $identifier) {
            session()->destroy();
            $response = $this->controller(\App\Controllers\AuthController::class, 'login/authenticate', ['identifier' => $identifier, 'password' => 'LoginPassword!9'])->authenticateStudent();
            $this->assertStringEndsWith('/student/dashboard', $response->getHeaderLine('Location'));
            $this->assertSame(10, (int) session()->get('user_id'));
            $this->assertSame(0, (int) session()->get('role'));
        }
    }

    public function testExistingStudentsCanStillUseRegisteredName(): void
    {
        $this->rbacDb->table('users')->where('id', 10)->update(['password_hash' => password_hash('LoginPassword!9', PASSWORD_DEFAULT)]);
        $response = $this->controller(\App\Controllers\AuthController::class, 'login/authenticate', ['identifier' => 'PRIVATE_STUDENT_10', 'password' => 'LoginPassword!9'])->authenticateStudent();
        $this->assertStringEndsWith('/student/dashboard', $response->getHeaderLine('Location'));
        $this->assertSame(10, (int) session()->get('user_id'));
    }

    public function testSharedLoginSendsEveryStaffRoleThroughOtp(): void
    {
        foreach ([1, 2, 3] as $role) {
            session()->destroy();
            $this->rbacDb->table('users')->where('id', $role)->update(['password_hash' => password_hash('StaffPassword!9', PASSWORD_DEFAULT)]);
            $this->rbacDb->table($role === 1 ? 'counselors' : 'admins')->insert(['user_id' => $role, $role === 1 ? 'counselor_email' : 'email' => 'staff' . $role . '@example.test']);
            foreach (['Staff ' . $role, 'staff' . $role . '@example.test'] as $identifier) {
                session()->destroy();
                $response = $this->controller(SharedLoginNoMailController::class, 'login/authenticate', ['identifier' => $identifier, 'password' => 'StaffPassword!9'])->authenticateStudent();
                $this->assertStringEndsWith('/admin/otp', $response->getHeaderLine('Location'));
                $this->assertFalse((bool) session()->get('isLoggedIn'));
                $this->assertSame($role, (int) session()->get('temp_role'));
            }
        }
    }

    public function testStaffLoginCanTemporarilySkipOtpAfterValidPassword(): void
    {
        $_ENV['staffLogin.otpEnabled'] = 'false';
        $this->rbacDb->table('users')->where('id', 1)->update(['password_hash' => password_hash('StaffPassword!9', PASSWORD_DEFAULT)]);

        $response = $this->controller(SharedLoginNoMailController::class, 'admin/login/authenticate', ['name' => 'Staff 1', 'password' => 'StaffPassword!9'])->authenticateAdmin();
        $this->assertStringEndsWith(RoleAccess::home(1), $response->getHeaderLine('Location'));
        $this->assertTrue((bool) session()->get('isLoggedIn'));
        $this->assertSame(1, (int) session()->get('user_id'));
        $this->assertFalse(session()->has('otp'));

        session()->remove(['isLoggedIn', 'user_id', 'role', 'role_name', 'auth_version']);
        $response = $this->controller(SharedLoginNoMailController::class, 'admin/login/authenticate', ['name' => 'Staff 1', 'password' => 'wrong-password'])->authenticateAdmin();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse((bool) session()->get('isLoggedIn'));
        $this->assertFalse(session()->has('otp'));
    }

    public function testSharedLoginRejectsDisabledAndPendingStudents(): void
    {
        $this->rbacDb->table('users')->where('id', 12)->update(['password_hash' => password_hash('LoginPassword!9', PASSWORD_DEFAULT)]);
        $response = $this->controller(\App\Controllers\AuthController::class, 'login/authenticate', ['identifier' => 'PRIVATE_ID_12', 'password' => 'LoginPassword!9'])->authenticateStudent();
        $this->assertFalse((bool) session()->get('isLoggedIn'));
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStaffLoginQueryRejectsWrongPasswordWithoutSqlError(): void
    {
        foreach ([1, 2, 3] as $role) {
            session()->destroy();
            $response = $this->controller(\App\Controllers\AuthController::class, 'admin/login/authenticate', ['name' => 'Staff ' . $role, 'password' => 'wrong-password'])->authenticateAdmin();
            $this->assertSame(302, $response->getStatusCode());
            $this->assertFalse((bool) session()->get('isLoggedIn'));
        }
    }

    public function testOtpResendContactQueryHandlesRemovedAccountWithoutSqlError(): void
    {
        session()->set(['temp_user_id' => 9999, 'otp' => '123456', 'otp_resend_time' => time() - 61, 'otp_resend_count' => 0]);
        $response = $this->controller(\App\Controllers\AuthController::class, 'admin/resend-otp')->resendOtp();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/admin/login', $response->getHeaderLine('Location'));
        $this->assertFalse(session()->has('otp'));
    }
}

class SharedLoginNoMailController extends \App\Controllers\AuthController
{
    protected function sendOtpEmail(array $user, string $recipientEmail, string $otp): bool { return false; }
}
