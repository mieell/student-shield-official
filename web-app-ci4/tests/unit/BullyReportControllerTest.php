<?php

namespace Tests\Unit;

use App\Controllers\BullyReportController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class BullyReportControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = \Config\Database::connect();
        $forge = \Config\Database::forge();

        if (!$db->tableExists('users')) {
            $forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'username' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => true,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => true,
                ],
                'is_locked' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
            ]);
            $forge->addKey('id', true);
            $forge->createTable('users', true);
        } else {
            $fields = $db->getFieldNames('users');
            if (!in_array('is_locked', $fields, true)) {
                $forge->addColumn('users', [
                    'is_locked' => [
                        'type'       => 'TINYINT',
                        'constraint' => 1,
                        'default'    => 0,
                    ],
                ]);
            }
        }

        // Clean up users table between test runs
        $db->table('users')->truncate();
    }

    public function testIndexRedirectsWhenUserIsLocked(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'        => 1,
            'username'  => 'locked_student',
            'name'      => 'Locked Student',
            'is_locked' => 1,
        ]);

        $_SESSION['user_id'] = 1;
        $session = service('session');
        $session->set('user_id', 1);

        $result = $this->controller(BullyReportController::class)
                       ->execute('index');

        $this->assertTrue($result->isRedirect());
        $this->assertStringContainsString('/student/dashboard', $result->getRedirectUrl());
        $this->assertSame(
            'Your reporting privileges have been suspended. Please visit the Guidance Office.',
            session()->getFlashdata('error')
        );
    }

    public function testIndexAccessAllowedWhenUserIsNotLocked(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'        => 2,
            'username'  => 'active_student',
            'name'      => 'Active Student',
            'is_locked' => 0,
        ]);

        $_SESSION['user_id'] = 2;
        $session = service('session');
        $session->set('user_id', 2);

        $result = $this->controller(BullyReportController::class)
                       ->execute('index');

        $this->assertFalse($result->isRedirect());
        $this->assertTrue($result->isOK());
    }

    public function testIndexAccessAllowedWhenNoSession(): void
    {
        unset($_SESSION['user_id']);
        $session = service('session');
        $session->remove('user_id');

        $result = $this->controller(BullyReportController::class)
                       ->execute('index');

        $this->assertFalse($result->isRedirect());
        $this->assertTrue($result->isOK());
    }
}
