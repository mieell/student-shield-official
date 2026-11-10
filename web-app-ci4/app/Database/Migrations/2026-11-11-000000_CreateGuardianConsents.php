<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuardianConsents extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'user_id' => ['type' => 'INT'],
            'verified_by' => ['type' => 'INT'],
            'phone' => ['type' => 'VARCHAR', 'constraint' => 13],
            'verified_name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'notice' => ['type' => 'TEXT'],
            'requested_at' => ['type' => 'DATETIME'],
            'expires_at' => ['type' => 'DATETIME'],
            'sms_state' => ['type' => 'VARCHAR', 'constraint' => 24],
            'response' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'guardian_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'responded_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('guardian_consents', true);
    }

    public function down()
    {
        $this->forge->dropTable('guardian_consents', true);
    }
}
