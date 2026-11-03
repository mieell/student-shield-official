<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class AuthAdminTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $this->setPrivateProperty($db, 'DBPrefix', '');

        $db->query("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role TINYINT(1) DEFAULT 0,
            remember_token VARCHAR(255) NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS counselors (
            user_id INT PRIMARY KEY,
            counselor_email VARCHAR(255),
            counselor_phone VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS admins (
            user_id INT PRIMARY KEY,
            email VARCHAR(255),
            phone VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Clean up tables between tests
        $db->table('audit_logs')->emptyTable();
        $db->table('admins')->emptyTable();
        $db->table('counselors')->emptyTable();
        $db->table('users')->emptyTable();

        // Clear rate limit files
        $rateLimitDir = WRITEPATH . 'rate_limits/';
        if (is_dir($rateLimitDir)) {
            $files = glob($rateLimitDir . '*.json');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        unset($_COOKIE['admin_trusted_device']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_COOKIE['admin_trusted_device']);

        $rateLimitDir = WRITEPATH . 'rate_limits/';
        if (is_dir($rateLimitDir)) {
            $files = glob($rateLimitDir . '*.json');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }

    public function testGetRequestRedirectsToLogin(): void
    {
        $result = $this->get('/admin/login/authenticate');
        $result->assertRedirectTo('/admin/login');
    }

    public function testEmptyFieldsReturnsError(): void
    {
        $result = $this->post('/admin/login/authenticate', [
            'name'     => '',
            'password' => '',
        ]);

        $result->assertSessionHas('error', 'Please fill in all fields.');
        $result->assertRedirect();
    }

    public function testNonExistentUserReturnsInvalidCredentials(): void
    {
        $result = $this->post('/admin/login/authenticate', [
            'name'     => 'NonExistentAdmin',
            'password' => 'SomePassword123!',
        ]);

        $result->assertSessionHas('error', 'Invalid credentials.');
        $result->assertRedirect();
    }

    public function testStudentRoleZeroCannotAuthenticateAsAdmin(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'StudentUser',
            'password_hash' => password_hash('StudentPassword123!', PASSWORD_BCRYPT),
            'role'          => 0, // Student role
            'is_active'     => 1,
        ]);

        $result = $this->post('/admin/login/authenticate', [
            'name'     => 'StudentUser',
            'password' => 'StudentPassword123!',
        ]);

        $result->assertSessionHas('error', 'Invalid credentials.');
        $result->assertRedirect();
        $result->assertSessionMissing('temp_role');
        $result->assertSessionMissing('user_id');
    }

    public function testCounselorRoleOneInvalidPasswordReturnsInvalidCredentials(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'CounselorUser',
            'password_hash' => password_hash('CorrectPassword123!', PASSWORD_BCRYPT),
            'role'          => 1, // Counselor role
            'is_active'     => 1,
        ]);

        $result = $this->post('/admin/login/authenticate', [
            'name'     => 'CounselorUser',
            'password' => 'WrongPassword123!',
        ]);

        $result->assertSessionHas('error', 'Invalid credentials.');
        $result->assertRedirect();
        $result->assertSessionMissing('temp_role');
    }

    public function testCounselorRoleOneAuthenticationSuccess(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'CounselorUser',
            'password_hash' => password_hash('CounselorPass123!', PASSWORD_BCRYPT),
            'role'          => 1, // Counselor role
            'is_active'     => 1,
        ]);
        $userId = $db->insertID();

        $db->table('counselors')->insert([
            'user_id'         => $userId,
            'counselor_email' => 'counselor@example.com',
            'counselor_phone' => '1234567890',
        ]);

        $result = $this->post('/admin/login/authenticate', [
            'name'     => 'CounselorUser',
            'password' => 'CounselorPass123!',
        ]);

        $result->assertRedirectTo('/admin/otp');
        $result->assertSessionHas('temp_user_id', $userId);
        $result->assertSessionHas('temp_name', 'CounselorUser');
        $result->assertSessionHas('temp_role', 1);
        $result->assertSessionHas('temp_role_name', 'counselor');
        $this->assertNotEmpty(session()->get('otp'));
    }

    public function testSuperAdminRoleTwoAuthenticationSuccess(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'name'          => 'SuperAdminUser',
            'password_hash' => password_hash('AdminPass123!', PASSWORD_BCRYPT),
            'role'          => 2, // Super Admin role
            'is_active'     => 1,
        ]);
        $userId = $db->insertID();

        $db->table('admins')->insert([
            'user_id' => $userId,
            'email'   => 'admin@example.com',
            'phone'   => '0987654321',
        ]);

        $result = $this->post('/admin/login/authenticate', [
            'name'     => 'SuperAdminUser',
            'password' => 'AdminPass123!',
        ]);

        $result->assertRedirectTo('/admin/otp');
        $result->assertSessionHas('temp_user_id', $userId);
        $result->assertSessionHas('temp_name', 'SuperAdminUser');
        $result->assertSessionHas('temp_role', 2);
        $result->assertSessionHas('temp_role_name', 'super_admin');
        $this->assertNotEmpty(session()->get('otp'));
    }

    public function testTrustedDeviceCookieBypassesOtpForAdmin(): void
    {
        $db = \Config\Database::connect();
        $token = bin2hex(random_bytes(32));

        $db->table('users')->insert([
            'name'           => 'TrustedAdmin',
            'password_hash'  => password_hash('AdminPass123!', PASSWORD_BCRYPT),
            'role'           => 2, // Super Admin role
            'remember_token' => password_hash($token, PASSWORD_BCRYPT),
            'is_active'      => 1,
        ]);
        $userId = $db->insertID();

        $db->table('admins')->insert([
            'user_id' => $userId,
            'email'   => 'admin@example.com',
            'phone'   => '0987654321',
        ]);

        $cookieValue = base64_encode($userId . ':' . $token);
        $_COOKIE['admin_trusted_device'] = $cookieValue;

        $result = $this->post('/admin/login/authenticate', [
            'name'     => 'TrustedAdmin',
            'password' => 'AdminPass123!',
        ]);

        $result->assertRedirectTo('/admin/dashboard');
        $result->assertSessionHas('user_id', $userId);
        $result->assertSessionHas('name', 'TrustedAdmin');
        $result->assertSessionHas('role', 2);
        $result->assertSessionHas('role_name', 'super_admin');
        $result->assertSessionHas('isLoggedIn', true);
    }
}
