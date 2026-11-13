<?php

namespace App\Controllers;

use App\Libraries\GuardianAlertRouting;
use Config\Database;

final class GuardianSafetyController extends BaseController
{
    public function update(int $userId)
    {
        if (!$this->hasRole(1)) return $this->forbidden();
        try {
            $data = GuardianAlertRouting::validate($this->request->getPost());
        } catch (\InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $error->getMessage(), 'csrf' => csrf_hash()]);
        }

        $db = Database::connect();
        if (!$db->tableExists('guardian_alert_routes')) {
            return $this->response->setStatusCode(503)->setJSON(['error' => 'Guardian alert routing is unavailable. Install the guardian safety database update.', 'csrf' => csrf_hash()]);
        }
        $db->transBegin();
        try {
            $lock = $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
            $student = $db->query('SELECT u.id, s.parent_phone, s.parent_email FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ? AND u.role = 0' . $lock, [$userId])->getRowArray();
            if (!$student) {
                $db->transRollback();
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Student not found.', 'csrf' => csrf_hash()]);
            }
            if (!empty($data['unsafe_guardian'])) {
                $settings = GuardianAlertRouting::settings($db, $userId);
                if ($settings === null) throw new \RuntimeException('Guardian settings are unavailable.');
                $duplicatesPhone = !empty($data['alternate_phone']) && in_array($data['alternate_phone'], [
                    \App\Libraries\GuardianConsent::phone($student['parent_phone'] ?? null),
                    \App\Libraries\GuardianConsent::phone($settings['verified_guardian_phone'] ?? null),
                ], true);
                $duplicatesEmail = !empty($data['alternate_email']) && strcasecmp($data['alternate_email'], (string) ($student['parent_email'] ?? '')) === 0;
                if ($duplicatesPhone || $duplicatesEmail) {
                    $db->transRollback();
                    return $this->response->setStatusCode(422)->setJSON(['error' => 'Use an alternate contact different from the guardian marked unsafe.', 'csrf' => csrf_hash()]);
                }
            }
            $hasAlternate = !empty($data['alternate_phone']) || !empty($data['alternate_email']);
            $data += ['user_id' => $userId, 'verified_by' => $hasAlternate ? (int) session()->get('user_id') : null,
                'verified_at' => $hasAlternate ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')];
            $db->table('guardian_alert_routes')->upsert($data);
            if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Guardian routing write failed.');
        } catch (\Throwable $error) {
            $db->transRollback();
            log_message('error', 'Guardian safety routing could not be saved.');
            return $this->response->setStatusCode(503)->setJSON(['error' => 'The settings could not be saved. Try again.', 'csrf' => csrf_hash()]);
        }
        $this->logAudit($data['unsafe_guardian'] ? 'GUARDIAN_MARKED_UNSAFE' : 'GUARDIAN_ROUTE_RESTORED', 'student', $userId);
        return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON(['message' => 'Guardian alert routing saved.', 'csrf' => csrf_hash()]);
    }
}
