<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuardianAlertRoutes extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'user_id' => ['type' => 'INT'],
            'unsafe_guardian' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'alternate_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'alternate_phone' => ['type' => 'VARCHAR', 'constraint' => 13, 'null' => true],
            'alternate_email' => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => true],
            'verified_by' => ['type' => 'INT', 'null' => true],
            'verified_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('verified_by', 'users', 'id', '', 'SET NULL');
        $this->forge->createTable('guardian_alert_routes', true);
    }

    public function down()
    {
        $this->forge->dropTable('guardian_alert_routes', true);
    }
}
