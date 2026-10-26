<?php

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

require_once APPPATH . 'Database/Migrations/2026-05-20-140000_SplitUsersTable.php';

use App\Database\Migrations\SplitUsersTable;

/**
 * @internal
 */
final class SplitUsersTableMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate   = false;
    protected $migrateOnce = false;

    public function testMigrationDownPerformanceAndCorrectness(): void
    {
        $db = \Config\Database::connect();
        $forge = \Config\Database::forge();

        // Ensure clean state: drop tables if they exist
        $forge->dropTable('students', true);
        $forge->dropTable('counselors', true);
        $forge->dropTable('admins', true);
        $forge->dropTable('users', true);

        // 1. Create users table (post-up state: without profile columns)
        $forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
            ],
            'role' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('users', true);

        // 2. Create students table
        $forge->addField([
            'user_id' => ['type' => 'INT', 'constraint' => 11],
            'student_id' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
            'parent_phone' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
            'parent_email' => ['type' => 'VARCHAR', 'constraint' => '255', 'null' => true],
            'section' => ['type' => 'VARCHAR', 'constraint' => '100', 'null' => true],
            'adviser_name' => ['type' => 'VARCHAR', 'constraint' => '255', 'null' => true],
            'grade_level' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
        ]);
        $forge->addPrimaryKey('user_id');
        $forge->createTable('students', true);

        // 3. Create counselors table
        $forge->addField([
            'user_id' => ['type' => 'INT', 'constraint' => 11],
            'counselor_email' => ['type' => 'VARCHAR', 'constraint' => '255', 'null' => true],
            'counselor_phone' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
        ]);
        $forge->addPrimaryKey('user_id');
        $forge->createTable('counselors', true);

        // 4. Create admins table
        $forge->addField([
            'user_id' => ['type' => 'INT', 'constraint' => 11],
            'email' => ['type' => 'VARCHAR', 'constraint' => '255', 'null' => true],
            'phone' => ['type' => 'VARCHAR', 'constraint' => '50', 'null' => true],
        ]);
        $forge->addPrimaryKey('user_id');
        $forge->createTable('admins', true);

        // Seed users & subclass tables (500 students, 500 counselors, 500 admins)
        $usersBatch = [];
        $studentsBatch = [];
        $counselorsBatch = [];
        $adminsBatch = [];

        for ($i = 1; $i <= 500; $i++) {
            $usersBatch[] = ['id' => $i, 'name' => "Student $i", 'email' => "student$i@example.com", 'role' => 0];
            $studentsBatch[] = [
                'user_id'      => $i,
                'student_id'   => "STU-$i",
                'parent_phone' => "123456789",
                'parent_email' => "parent$i@example.com",
                'section'      => 'A',
                'adviser_name' => 'Adviser Smith',
                'grade_level'  => '10',
            ];
        }

        for ($i = 501; $i <= 1000; $i++) {
            $usersBatch[] = ['id' => $i, 'name' => "Counselor $i", 'email' => "counselor$i@example.com", 'role' => 1];
            $counselorsBatch[] = [
                'user_id'         => $i,
                'counselor_email' => "counselor$i@counseling.com",
                'counselor_phone' => "987654321",
            ];
        }

        for ($i = 1001; $i <= 1500; $i++) {
            $usersBatch[] = ['id' => $i, 'name' => "Admin $i", 'email' => "admin$i@example.com", 'role' => 2];
            $adminsBatch[] = [
                'user_id' => $i,
                'email'   => "admin$i@admin.com",
                'phone'   => "555555555",
            ];
        }

        $db->table('users')->insertBatch($usersBatch);
        $db->table('students')->insertBatch($studentsBatch);
        $db->table('counselors')->insertBatch($counselorsBatch);
        $db->table('admins')->insertBatch($adminsBatch);

        $migration = new SplitUsersTable();

        // Measure execution time of down()
        $startTime = microtime(true);
        $migration->down();
        $endTime = microtime(true);

        $executionTimeMs = ($endTime - $startTime) * 1000;
        fwrite(STDERR, sprintf("\n[BENCHMARK] Migration down() execution time: %.2f ms\n", $executionTimeMs));

        // Verify data restored in users table
        $counselorUser = $db->table('users')->where('id', 501)->get()->getRowArray();
        $this->assertEquals('counselor501@counseling.com', $counselorUser['parent_email']);
        $this->assertEquals('987654321', $counselorUser['parent_phone']);

        $studentUser = $db->table('users')->where('id', 1)->get()->getRowArray();
        $this->assertEquals('STU-1', $studentUser['student_id']);
        $this->assertEquals('parent1@example.com', $studentUser['parent_email']);

        $adminUser = $db->table('users')->where('id', 1001)->get()->getRowArray();
        $this->assertEquals('admin1001@admin.com', $adminUser['parent_email']);

        $this->assertTrue(true);
    }
}
