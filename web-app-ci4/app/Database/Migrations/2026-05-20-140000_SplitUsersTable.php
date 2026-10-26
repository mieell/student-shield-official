<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SplitUsersTable extends Migration
{
    public function up()
    {
        // 1. Create `students` table
        $this->forge->addField([
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'student_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'parent_phone' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'parent_email' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'section' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
            ],
            'adviser_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'grade_level' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            ],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('students');

        // 2. Create `counselors` table
        $this->forge->addField([
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'counselor_email' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'counselor_phone' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            ],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('counselors');

        // 3. Create `admins` table
        $this->forge->addField([
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'phone' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            ],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('admins');

        // 4. Migrate Data from `users` to profile tables
        $db = \Config\Database::connect();
        $users = $db->table('users')->get()->getResultArray();

        foreach ($users as $user) {
            $role = (int)$user['role'];
            if ($role === 0) {
                $db->table('students')->insert([
                    'user_id'      => $user['id'],
                    'student_id'   => $user['student_id'],
                    'parent_phone' => $user['parent_phone'],
                    'parent_email' => $user['parent_email'],
                    'section'      => $user['section'],
                    'adviser_name' => $user['adviser_name'],
                    'grade_level'  => $user['grade_level'] ?? null,
                ]);
            } elseif ($role === 1) {
                $db->table('counselors')->insert([
                    'user_id'          => $user['id'],
                    'counselor_email' => $user['parent_email'],
                    'counselor_phone' => $user['parent_phone'],
                ]);
            } elseif ($role === 2) {
                $db->table('admins')->insert([
                    'user_id' => $user['id'],
                    'email'   => $user['parent_email'],
                    'phone'   => $user['parent_phone'],
                ]);
            }
        }

        // 5. Drop profile columns from `users` table
        // We drop these because they are now physically split into the subclass tables
        $this->forge->dropColumn('users', [
            'student_id',
            'parent_phone',
            'parent_email',
            'section',
            'adviser_name',
            'grade_level'
        ]);
    }

    public function down()
    {
        // 1. Add back the profile columns to the `users` table
        $this->forge->addColumn('users', [
            'student_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'parent_phone' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'parent_email' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'section' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
            ],
            'adviser_name' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'grade_level' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
        ]);

        // 2. Restore data from subclass tables back to `users`
        $db = \Config\Database::connect();

        $students = $db->table('students')->get()->getResultArray();
        if (!empty($students)) {
            $studentUpdates = [];
            foreach ($students as $student) {
                $studentUpdates[] = [
                    'id'           => $student['user_id'],
                    'student_id'   => $student['student_id'],
                    'parent_phone' => $student['parent_phone'],
                    'parent_email' => $student['parent_email'],
                    'section'      => $student['section'],
                    'adviser_name' => $student['adviser_name'],
                    'grade_level'  => $student['grade_level'],
                ];
            }
            $db->table('users')->updateBatch($studentUpdates, 'id');
        }

        $counselors = $db->table('counselors')->get()->getResultArray();
        if (!empty($counselors)) {
            $counselorUpdates = [];
            foreach ($counselors as $counselor) {
                $counselorUpdates[] = [
                    'id'           => $counselor['user_id'],
                    'parent_email' => $counselor['counselor_email'],
                    'parent_phone' => $counselor['counselor_phone'],
                ];
            }
            $db->table('users')->updateBatch($counselorUpdates, 'id');
        }

        $admins = $db->table('admins')->get()->getResultArray();
        if (!empty($admins)) {
            $adminUpdates = [];
            foreach ($admins as $admin) {
                $adminUpdates[] = [
                    'id'           => $admin['user_id'],
                    'parent_email' => $admin['email'],
                    'parent_phone' => $admin['phone'],
                ];
            }
            $db->table('users')->updateBatch($adminUpdates, 'id');
        }

        // 3. Drop subclass tables
        $this->forge->dropTable('students');
        $this->forge->dropTable('counselors');
        $this->forge->dropTable('admins');
    }
}
