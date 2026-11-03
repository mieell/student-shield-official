<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVentLogsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sentiment' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'risk_level' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('vent_logs');
    }

    public function down()
    {
        $this->forge->dropTable('vent_logs');
    }
}
