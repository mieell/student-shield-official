<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class AuthStudentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $setUpMethods = [
        'resetFactories',
        'mockCache',
        'mockEmail',
        'mockSession',
    ];

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear rate limit files before each test to prevent lockout failures
        $files = glob(WRITEPATH . 'rate_limits/*.json');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function testAuthenticateStudentGetRedirectsToLogin(): void
    {
        $result = $this->get('/login/authenticate');
        $result->assertRedirectTo('/login');
    }

    public function testAuthenticateStudentMissingFields(): void
    {
        $result = $this->post('/login/authenticate', [
            'name'     => '',
            'password' => '',
        ]);

        $result->assertRedirect();
        $this->assertSame('Please fill in all fields.', session('error'));
    }

    public function testAuthenticateStudentInvalidCredentials(): void
    {
        $result = $this->post('/login/authenticate', [
            'name'     => 'NonExistentStudent',
            'password' => 'Secret123!',
        ]);

        $result->assertRedirect();
        $this->assertSame('Invalid Student credentials.', session('error'));
    }

    public function testAuthenticateStudentNonStudentRole(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'Admin User',
            'role'          => 1, // Counselor / Admin
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'is_active'     => 1,
        ]);

        $result = $this->post('/login/authenticate', [
            'name'     => 'Admin User',
            'password' => 'Secret123!',
        ]);

        $result->assertRedirect();
        $this->assertSame('Invalid Student credentials.', session('error'));
    }

    public function testAuthenticateStudentAccountPendingApproval(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'Pending Student',
            'role'          => 0,
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'is_active'     => 0,
        ]);

        $result = $this->post('/login/authenticate', [
            'name'     => 'Pending Student',
            'password' => 'Secret123!',
        ]);

        $result->assertRedirect();
        $this->assertSame('Your account is still pending approval by the guidance office.', session('error'));
    }

    public function testAuthenticateStudentSuccess(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'Active Student',
            'role'          => 0,
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'is_active'     => 1,
        ]);
        $userId = $db->insertID();

        $result = $this->post('/login/authenticate', [
            'name'     => 'Active Student',
            'password' => 'Secret123!',
        ]);

        $result->assertRedirectTo('/student/dashboard');
        $this->assertTrue(session()->get('isLoggedIn'));
        $this->assertSame($userId, session()->get('user_id'));
        $this->assertSame('Active Student', session()->get('name'));
        $this->assertSame(0, session()->get('role'));
        $this->assertSame('student', session()->get('role_name'));

        // Check audit log created
        $this->seeInDatabase('audit_logs', [
            'user_id' => $userId,
            'action'  => 'USER_LOGIN',
        ]);
    }

    public function testAuthenticateStudentWithRememberMe(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'Remember Student',
            'role'          => 0,
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'is_active'     => 1,
        ]);
        $userId = $db->insertID();

        $result = $this->post('/login/authenticate', [
            'name'     => 'Remember Student',
            'password' => 'Secret123!',
            'remember' => 'on',
        ]);

        $result->assertRedirectTo('/student/dashboard');

        // Verify remember_token in DB was set
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertNotEmpty($user['remember_token']);
    }
}
