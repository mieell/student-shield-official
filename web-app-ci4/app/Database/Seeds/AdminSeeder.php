<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $userData = [
            'name'            => 'admin',
            'role_id'         => 2, // Super Admin
            'email'           => 'admin@studentshield.org',
            'phone'           => '09000000000',
            'password_hash'   => password_hash('admin123', PASSWORD_BCRYPT),
        ];

        // Check if admin already exists
        $existing = $this->db->table('users')->where('name', 'admin')->get()->getRow();
        if (!$existing) {
            $this->db->table('users')->insert($userData);
            echo "Admin account created successfully. Username: admin, Password: admin123\n";
        } else {
            echo "Admin account already exists.\n";
        }
    }
}
