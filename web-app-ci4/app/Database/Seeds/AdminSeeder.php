<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $adminPassword = getenv('ADMIN_PASSWORD') ?: (function_exists('env') ? env('ADMIN_PASSWORD') : null);
        if (empty($adminPassword)) {
            $adminPassword = bin2hex(random_bytes(10));
        }

        $userData = [
            'name'            => 'admin',
            'role'            => 2, // Super Admin
            'password_hash'   => password_hash($adminPassword, PASSWORD_BCRYPT),
        ];

        // Check if admin already exists
        $existing = $this->db->table('users')->where('name', 'admin')->get()->getRow();
        if (!$existing) {
            $this->db->table('users')->insert($userData);
            $newUserId = $this->db->insertID();
            
            $this->db->table('admins')->insert([
                'user_id' => $newUserId,
                'email'   => 'admin@studentshield.org',
                'phone'   => '09000000000'
            ]);
            echo "Admin account created successfully. Username: admin, Password: {$adminPassword}\n";
        } else {
            echo "Admin account already exists.\n";
        }
    }
}
