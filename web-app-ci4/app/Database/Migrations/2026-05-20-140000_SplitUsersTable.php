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
            ],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('students', true);

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
            ],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('counselors', true);

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
            ],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('admins', true);

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
        foreach ($students as $student) {
            $db->table('users')->where('id', $student['user_id'])->update([
                'student_id'   => $student['student_id'],
                'parent_phone' => $student['parent_phone'],
                'parent_email' => $student['parent_email'],
                'section'      => $student['section'],
                'adviser_name' => $student['adviser_name'],
                'grade_level'  => $student['grade_level'],
            ]);
        }

        $counselors = $db->table('counselors')->get()->getResultArray();
        foreach ($counselors as $counselor) {
            $db->table('users')->where('id', $counselor['user_id'])->update([
                'parent_email' => $counselor['counselor_email'],
                'parent_phone' => $counselor['counselor_phone'],
            ]);
        }

        $admins = $db->table('admins')->get()->getResultArray();
        foreach ($admins as $admin) {
            $db->table('users')->where('id', $admin['user_id'])->update([
                'parent_email' => $admin['email'],
                'parent_phone' => $admin['phone'],
            ]);
        }

        // 3. Drop subclass tables
        $this->forge->dropTable('students');
        $this->forge->dropTable('counselors');
        $this->forge->dropTable('admins');
    }
}
