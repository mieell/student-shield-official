<?php

namespace Tests\Unit;

use App\Controllers\BullyReportController;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class BullyReportIntakeTest extends CIUnitTestCase
{
    public function testIntakeDoesNotTreatGuardianEmailAsReporterEmail(): void
    {
        helper(['url', 'form']);
        $saved = [];
        foreach (['COUNSELOR_PHONE', 'COUNSELOR_EMAIL'] as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = '';
            putenv($key . '=');
        }
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $connections = new \ReflectionProperty(Database::class, 'instances');
        $priorConnections = $connections->getValue();
        $connections->setValue(null, ['tests' => $db]);
        try {
            $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, is_locked INTEGER)');
            $db->query('CREATE TABLE students (user_id INTEGER, parent_email TEXT)');
            $db->query('CREATE TABLE counselors (user_id INTEGER, counselor_email TEXT)');
            $db->query('CREATE TABLE admins (user_id INTEGER, email TEXT)');
            $db->query('CREATE TABLE bully_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, reporter_student_id INTEGER, reporter_type TEXT, victim_student_id INTEGER, bully_student_id INTEGER, incident_details TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
            $db->table('users')->insert(['id' => 10, 'name' => 'Fictional Student', 'is_locked' => 0]);
            $db->table('students')->insert(['user_id' => 10, 'parent_email' => 'unsafe-parent@example.test']);

            $email = $this->getMockBuilder(\CodeIgniter\Email\Email::class)->disableOriginalConstructor()
                ->onlyMethods(['initialize', 'setFrom', 'setTo', 'setSubject', 'setMessage', 'send'])->getMock();
            $email->expects(self::never())->method('setTo');
            Services::injectMock('email', $email);
            $encryption = new \Config\Encryption();
            $encryption->key = str_repeat('k', 32);
            \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $encryption);
            $encrypter = $this->createMock(\CodeIgniter\Encryption\EncrypterInterface::class);
            $encrypter->method('encrypt')->willReturn('encrypted-fictional-report');
            Services::injectMock('encrypter', $encrypter);
            session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);
            session()->remove('last_bully_report_time');
            service('superglobals')->setPostArray(['reporter_type' => 'self', 'bully_student_id' => 'unknown',
                'bully_name' => 'Fictional classmate', 'incident_details' => 'Fictional verbal bullying report.']);
            $config = new \Config\App();
            $request = new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, 'report/bullying/submit'), null, new \CodeIgniter\HTTP\UserAgent());
            $request->setMethod('POST');
            Services::injectMock('request', $request);
            $controller = new class extends BullyReportController {
                protected function logAudit(string $action, string $entityType, int $entityId): void {}
            };
            $controller->initController($request, Services::response(), Services::logger());
            $response = $controller->submit();
            self::assertSame(302, $response->getStatusCode());
            self::assertSame(1, $db->table('bully_reports')->countAllResults());
            self::assertStringContainsString('received for counselor review', session()->getFlashdata('message'));
        } finally {
            \CodeIgniter\Config\Factories::reset('config');
            $connections->setValue(null, $priorConnections);
            $db->close();
            session()->destroy();
            foreach ($saved as $key => [$env, $server, $process]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($env !== null) $_ENV[$key] = $env;
                if ($server !== null) $_SERVER[$key] = $server;
                putenv($process === false ? $key : $key . '=' . $process);
            }
        }
    }
}
