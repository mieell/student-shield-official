<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddScreeningExplanation extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('analysis_details', 'vent_logs')) {
            $this->forge->addColumn('vent_logs', ['analysis_details' => ['type' => 'TEXT', 'null' => true]]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('analysis_details', 'vent_logs')) $this->forge->dropColumn('vent_logs', 'analysis_details');
    }
}
