<?php

namespace App\Controllers;

/**
 * Case Summary Report Controller
 * 
 * Aggregates student data from vent_logs, bully_reports, sms_logs, email_logs,
 * audit_logs, counselor_notes, and case_status_history into a comprehensive
 * case summary report for counselors.
 */
class CaseSummaryController extends BaseController
{
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper('cookie');
    }

    /**
     * Guard: clinical student records are counselor-only (role 1).
     */
    private function guardAccess(): bool
    {
        return session()->get('isLoggedIn') && in_array((int) session()->get('role'), [1]);
    }

    /**
     * Render the full case summary page for a student.
     */
    public function index($studentId)
    {
        if (!$this->guardAccess()) {
            return redirect()->to('/admin/login');
        }

        $db = \Config\Database::connect();

        $student = $this->getStudentInfo($db, $studentId);
        if (!$student) {
            return redirect()->to('/admin/dashboard')->with('error', 'Student not found.');
        }

        $this->logAudit('VIEW_CASE_SUMMARY', 'student', (int) $studentId);

        // Disable the Debug Toolbar injection to prevent SQL/file paths from leaking into printed reports
        $this->request->setHeader('HX-Request', 'true');

        return view('case_summary', [
            'student'    => $student,
            'studentId'  => $studentId,
        ]);
    }

    /**
     * JSON API: return all aggregated case data for a student.
     */
    public function apiData($studentId)
    {
        if (!$this->guardAccess()) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $db = \Config\Database::connect();
        $student = $this->getStudentInfo($db, $studentId);
        if (!$student) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Student not found']);
        }

        $encrypter = \Config\Services::encrypter();

        // 1. Vent logs (risk + emotion data)
        $vents = $db->table('vent_logs')
            ->where('user_id', $studentId)
            ->where('deleted_at IS NULL')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        foreach ($vents as &$vent) {
            try {
                if (ctype_xdigit($vent['raw_text']) && strlen($vent['raw_text']) > 32) {
                    $vent['raw_text'] = $encrypter->decrypt(hex2bin($vent['raw_text']));
                }
            } catch (\Exception $e) {
            }
        }

        // 2. Risk timeline data
        $riskTimeline = [];
        foreach ($vents as $v) {
            $risk = strtoupper($v['risk_level'] ?? 'LOW');
            $y = $risk === 'CRITICAL' ? 100 : ($risk === 'HIGH' ? 75 : ($risk === 'MEDIUM' ? 50 : 25));
            $riskTimeline[] = [
                'x'          => strtotime($v['created_at']) * 1000,
                'y'          => $y,
                'risk_label' => $risk,
                'sentiment'  => $v['risk_percent'] ?? '0',
            ];
        }

        // 3. Emotion analysis
        $emotionCounts = [];
        foreach ($vents as $v) {
            $emotions = $v['emotions'] ?? '';
            if (!empty($emotions)) {
                $parts = array_map('trim', explode(',', $emotions));
                foreach ($parts as $emotion) {
                    $emotion = ucfirst(strtolower($emotion));
                    if (!empty($emotion)) {
                        $emotionCounts[$emotion] = ($emotionCounts[$emotion] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($emotionCounts);

        // 4. Risk level distribution
        $riskCounts = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
        $highestRisk = 'LOW';
        $highestRiskDate = null;
        $riskOrder = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];

        foreach ($vents as $v) {
            $risk = strtoupper($v['risk_level'] ?? 'LOW');
            if (isset($riskCounts[$risk])) {
                $riskCounts[$risk]++;
            }
            if (($riskOrder[$risk] ?? 0) > ($riskOrder[$highestRisk] ?? 0)) {
                $highestRisk = $risk;
                $highestRiskDate = $v['created_at'];
            }
        }

        // Current risk = most recent vent's risk level
        $currentRisk = !empty($vents) ? strtoupper(end($vents)['risk_level'] ?? 'LOW') : 'LOW';

        // 5. Bullying reports
        $bullyReports = $db->table('bully_reports')
            ->select('bully_reports.*, users.name as reporter_name')
            ->join('users', 'users.id = bully_reports.reporter_student_id', 'left')
            ->where('bully_reports.deleted_at IS NULL')
            ->groupStart()
                ->where('reporter_student_id', $studentId)
                ->orWhere('victim_student_id', $studentId)
                ->orWhere('bully_student_id', $studentId)
            ->groupEnd()
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        foreach ($bullyReports as &$report) {
            try {
                if (ctype_xdigit($report['incident_details']) && strlen($report['incident_details']) > 32) {
                    $report['incident_details'] = $encrypter->decrypt(hex2bin($report['incident_details']));
                }
            } catch (\Exception $e) {
            }

            // Fetch the staff member who verified/rejected this report
            $report['reviewed_by_name'] = null;
            if (in_array($report['status'], ['verified', 'rejected_fake'])) {
                try {
                    if ($db->tableExists('audit_logs')) {
                        $audit = $db->table('audit_logs')
                            ->select('users.name')
                            ->join('users', 'users.id = audit_logs.user_id', 'left')
                            ->where('audit_logs.entity_type', 'incident')
                            ->where('audit_logs.entity_id', $report['id'])
                            ->whereIn('audit_logs.action', ['VERIFY_AND_ALERT', 'VERIFY_INCIDENT', 'REJECT_FAKE'])
                            ->orderBy('audit_logs.created_at', 'DESC')
                            ->limit(1)
                            ->get()->getRow();
                        
                        if ($audit) {
                            $report['reviewed_by_name'] = $audit->name;
                        }
                    }
                } catch (\Exception $e) {
                    log_message('warning', 'Failed to fetch reviewer from audit_logs: ' . $e->getMessage());
                }
            }
        }

        $bullyStats = [
            'total'       => count($bullyReports),
            'pending'     => count(array_filter($bullyReports, fn($r) => $r['status'] === 'pending_review')),
            'verified'    => count(array_filter($bullyReports, fn($r) => $r['status'] === 'verified')),
            'rejected'    => count(array_filter($bullyReports, fn($r) => $r['status'] === 'rejected_fake')),
            'resolved'    => count(array_filter($bullyReports, fn($r) => in_array($r['status'], ['resolved', 'Resolved']))),
        ];

        // 6. SMS logs (table may not exist on some deployments)
        $smsLogs = [];
        try {
            if ($db->tableExists('sms_logs')) {
                $smsLogs = $db->table('sms_logs')
                    ->where('student_id', $studentId)
                    ->orderBy('timestamp', 'DESC')
                    ->limit(50)
                    ->get()->getResultArray();
            }
        } catch (\Exception $e) {
            log_message('warning', 'sms_logs query skipped: ' . $e->getMessage());
        }

        // 7. Email logs (table may not exist on some deployments)
        $emailLogs = [];
        try {
            if ($db->tableExists('email_logs')) {
                $emailLogs = $db->table('email_logs')
                    ->where('student_id', $studentId)
                    ->orderBy('timestamp', 'DESC')
                    ->limit(50)
                    ->get()->getResultArray();
            }
        } catch (\Exception $e) {
            log_message('warning', 'email_logs query skipped: ' . $e->getMessage());
        }

        // 8. Counselor notes
        $notes = [];
        try {
            $notes = $db->table('counselor_notes')
                ->select('counselor_notes.*, users.name as counselor_name')
                ->join('users', 'users.id = counselor_notes.counselor_id', 'left')
                ->where('counselor_notes.student_id', $studentId)
                ->where('counselor_notes.deleted_at IS NULL')
                ->orderBy('counselor_notes.created_at', 'DESC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            log_message('warning', 'counselor_notes query skipped: ' . $e->getMessage());
        }

        // 9. Case status history
        $statusHistory = [];
        try {
            $statusHistory = $db->table('case_status_history')
                ->select('case_status_history.*, users.name as changed_by_name')
                ->join('users', 'users.id = case_status_history.changed_by', 'left')
                ->where('case_status_history.student_id', $studentId)
                ->orderBy('case_status_history.created_at', 'DESC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            log_message('warning', 'case_status_history query skipped: ' . $e->getMessage());
        }

        $currentStatus = !empty($statusHistory) ? $statusHistory[0]['status'] : 'active_monitoring';

        // 10. Audit logs for this student (table may not exist on some deployments)
        $auditLogs = [];
        try {
            if ($db->tableExists('audit_logs')) {
                $auditLogs = $db->table('audit_logs')
                    ->where('entity_type', 'student')
                    ->where('entity_id', $studentId)
                    ->orderBy('created_at', 'DESC')
                    ->limit(20)
                    ->get()->getResultArray();
            }
        } catch (\Exception $e) {
            log_message('warning', 'audit_logs query skipped: ' . $e->getMessage());
        }

        // 11. Build unified timeline
        $timeline = [];

        foreach ($vents as $v) {
            $timeline[] = [
                'type'        => 'vent',
                'date'        => $v['created_at'],
                'title'       => 'Safe Space Entry — ' . strtoupper($v['risk_level'] ?? 'LOW') . ' Risk',
                'description' => mb_substr($v['raw_text'], 0, 120) . (mb_strlen($v['raw_text']) > 120 ? '...' : ''),
                'risk_level'  => strtoupper($v['risk_level'] ?? 'LOW'),
                'status'      => $v['status'],
            ];
        }

        foreach ($bullyReports as $r) {
            $role = '';
            if ($r['reporter_student_id'] == $studentId) $role = 'Reporter';
            elseif ($r['victim_student_id'] == $studentId) $role = 'Victim';
            elseif ($r['bully_student_id'] == $studentId) $role = 'Accused';

            $timeline[] = [
                'type'        => 'incident',
                'date'        => $r['created_at'],
                'title'       => 'Bullying Report — ' . $role,
                'description' => mb_substr($r['incident_details'], 0, 120) . (mb_strlen($r['incident_details']) > 120 ? '...' : ''),
                'status'      => $r['status'],
            ];
        }

        foreach ($smsLogs as $s) {
            $timeline[] = [
                'type'        => 'sms',
                'date'        => $s['timestamp'],
                'title'       => 'SMS Alert Sent',
                'description' => 'To: ' . $s['parent_number'] . ' — ' . $s['alert_type'],
                'status'      => $s['sent_status'],
            ];
        }

        foreach ($emailLogs as $e) {
            $timeline[] = [
                'type'        => 'email',
                'date'        => $e['timestamp'],
                'title'       => 'Email Alert Sent',
                'description' => 'To: ' . $e['recipient_email'] . ' — ' . $e['subject'],
                'status'      => $e['sent_status'],
            ];
        }

        foreach ($notes as $n) {
            $timeline[] = [
                'type'        => 'note',
                'date'        => $n['created_at'],
                'title'       => 'Counselor Note — ' . ucfirst(str_replace('_', ' ', $n['note_type'])),
                'description' => mb_substr($n['content'], 0, 120) . (mb_strlen($n['content']) > 120 ? '...' : ''),
                'counselor'   => $n['counselor_name'] ?? 'Unknown',
            ];
        }

        foreach ($statusHistory as $sh) {
            $timeline[] = [
                'type'        => 'status_change',
                'date'        => $sh['created_at'],
                'title'       => 'Status Changed → ' . ucwords(str_replace('_', ' ', $sh['status'])),
                'description' => $sh['remarks'] ?? '',
                'counselor'   => $sh['changed_by_name'] ?? 'Unknown',
            ];
        }

        // Sort timeline descending
        usort($timeline, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        return $this->response->setJSON([
            'student'        => $student,
            'riskTimeline'   => $riskTimeline,
            'riskCounts'     => $riskCounts,
            'highestRisk'    => $highestRisk,
            'highestRiskDate' => $highestRiskDate,
            'currentRisk'    => $currentRisk,
            'emotionCounts'  => $emotionCounts,
            'vents'          => $vents,
            'bullyReports'   => $bullyReports,
            'bullyStats'     => $bullyStats,
            'smsLogs'        => $smsLogs,
            'emailLogs'      => $emailLogs,
            'notes'          => $notes,
            'statusHistory'  => $statusHistory,
            'currentStatus'  => $currentStatus,
            'timeline'       => $timeline,
            'totalVents'     => count($vents),
            'totalNotifications' => count($smsLogs) + count($emailLogs),
            'generatedAt'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Add a counselor note for a student.
     */
    public function addNote($studentId)
    {
        if (!$this->guardAccess()) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $noteType = $this->request->getPost('note_type') ?? 'general';
        $content  = $this->request->getPost('content');

        if (empty($content)) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Note content is required']);
        }

        $db = \Config\Database::connect();
        $db->table('counselor_notes')->insert([
            'student_id'   => (int) $studentId,
            'counselor_id' => (int) session()->get('user_id'),
            'note_type'    => $noteType,
            'content'      => $content,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit('ADD_NOTE', 'student', (int) $studentId);

        return $this->response->setJSON(['success' => true, 'message' => 'Note added successfully']);
    }

    /**
     * Update the case status for a student.
     */
    public function updateStatus($studentId)
    {
        if (!$this->guardAccess()) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $status  = $this->request->getPost('status');
        $remarks = $this->request->getPost('remarks') ?? '';

        $validStatuses = [
            'active_monitoring', 'intervention_ongoing', 'parent_consultation_required',
            'escalated', 'under_investigation', 'resolved', 'closed',
        ];

        if (!in_array($status, $validStatuses)) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid status']);
        }

        $db = \Config\Database::connect();
        $db->table('case_status_history')->insert([
            'student_id' => (int) $studentId,
            'status'     => $status,
            'remarks'    => $remarks,
            'changed_by' => (int) session()->get('user_id'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit('UPDATE_CASE_STATUS', 'student', (int) $studentId);

        return $this->response->setJSON(['success' => true, 'message' => 'Status updated']);
    }

    /**
     * Soft-delete a counselor note.
     */
    public function deleteNote($noteId)
    {
        if (!$this->guardAccess()) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $db = \Config\Database::connect();
        $db->table('counselor_notes')
            ->where('id', $noteId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->logAudit('DELETE_NOTE', 'counselor_note', (int) $noteId);

        return $this->response->setJSON(['success' => true, 'message' => 'Note deleted']);
    }

    // ── Helpers ──

    private function getStudentInfo($db, $studentId): ?array
    {
        $user = $db->table('users')
            ->select('users.id, users.name, users.role,
                      students.student_id, students.section, students.adviser_name,
                      students.grade_level, students.parent_phone, students.parent_email')
            ->join('students', 'students.user_id = users.id', 'left')
            ->where('users.id', $studentId)
            ->where('users.role', 0)
            ->get()->getRowArray();


        if (!$user) return null;

        unset($user['password_hash']);
        return $user;
    }

}
