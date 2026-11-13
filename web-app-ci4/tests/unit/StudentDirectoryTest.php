<?php

namespace Tests\Unit;

use App\Libraries\StudentDirectory;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class StudentDirectoryTest extends CIUnitTestCase
{
    public function testSelectedStudentMustMatchScopeAndBeApproved(): void
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => ''], false);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role INTEGER, is_active INTEGER, access_disabled INTEGER, registration_status TEXT)');
        $db->query('CREATE TABLE students (user_id INTEGER, grade_level TEXT, section TEXT)');
        foreach ([[1, 0, 'approved'], [2, 1, 'approved'], [3, 0, 'pending']] as [$id, $role, $state]) {
            $db->table('users')->insert(['id' => $id, 'name' => 'Sample ' . $id, 'role' => $role, 'is_active' => 1, 'access_disabled' => 0, 'registration_status' => $state]);
            $db->table('students')->insert(['user_id' => $id, 'grade_level' => 'Grade 7', 'section' => 'Rizal']);
        }
        self::assertSame('Sample 1', StudentDirectory::selected($db, '1', '7', 'Rizal')['name']);
        self::assertNull(StudentDirectory::selected($db, '1', '8', 'Rizal'));
        self::assertNull(StudentDirectory::selected($db, '1', '7', 'Other'));
        self::assertNull(StudentDirectory::selected($db, '2', '7', 'Rizal'));
        self::assertNull(StudentDirectory::selected($db, '3', '7', 'Rizal'));
        self::assertNull(StudentDirectory::selected($db, '1 OR 1=1', '7', 'Rizal'));
        self::assertCount(1, StudentDirectory::search($db, '7', 'Rizal', 'Sample'));
        self::assertSame(['id', 'name'], array_keys(StudentDirectory::search($db, '7', 'Rizal', 'Sample')[0]));
        $db->close();
    }
}
