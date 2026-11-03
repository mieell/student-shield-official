<?php

use App\Database\Seeds\AdminSeeder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AdminSeederTest extends CIUnitTestCase
{
    public function testAdminSeederCreatesUserWithSecurePassword(): void
    {
        $db = \Config\Database::connect();

        $prefix = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$prefix}users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100),
            role INT DEFAULT 0,
            password_hash VARCHAR(255),
            created_at DATETIME,
            updated_at DATETIME
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$prefix}admins (
            user_id INT PRIMARY KEY,
            email VARCHAR(255),
            phone VARCHAR(50),
            created_at DATETIME,
            updated_at DATETIME
        )");

        // Clean up table state before running test
        $db->table('users')->where('name', 'admin')->delete();

        // Execute Seeder with output buffering to satisfy PHPUnit strict output check
        ob_start();
        $seeder = new AdminSeeder(new \Config\Database());
        $seeder->run();
        $output = ob_get_clean();

        $this->assertStringContainsString('Admin account created successfully. Username: admin, Password:', $output);

        $user = $db->table('users')->where('name', 'admin')->get()->getRow();

        $this->assertNotNull($user);
        $this->assertEquals('admin', $user->name);
        $this->assertEquals(2, (int)$user->role);
        // Ensure default hardcoded password 'admin123' is NOT used
        $this->assertFalse(password_verify('admin123', $user->password_hash));

        $admin = $db->table('admins')->where('user_id', $user->id)->get()->getRow();
        $this->assertNotNull($admin);
        $this->assertEquals('admin@studentshield.org', $admin->email);

        // Clean up after test
        $db->table('users')->where('id', $user->id)->delete();
        $db->table('admins')->where('user_id', $user->id)->delete();
    }
}
