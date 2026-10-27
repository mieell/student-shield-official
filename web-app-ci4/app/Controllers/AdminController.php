<?php

namespace App\Controllers;

class AdminController extends BaseController
{
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper('cookie');

        // Auto-migrate logic removed: schema alterations should be handled by CI4 Migrations.
    }
        private function guardStaffAccess(): bool
    {
        return (bool) (session()->get('isLoggedIn') && (int) session()->get('role') === 1);
    }

    public function dashboard(string $page = 'vents')
    {
        if (session()->get('isLoggedIn') && in_array((int) session()->get('role'), [2, 3], true)) {
            return redirect()->to(\App\Libraries\RoleAccess::home((int) session()->get('role')));
        }
        if (!$this->guardStaffAccess()) {
            return redirect()->to('/admin/login');
        }
        if (!in_array($page, ['vents', 'incidents', 'sessions', 'alerts', 'analytics'], true)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $db = \Config\Database::connect();
        // Each workspace loads its records through the existing staff-only API.
        $data = [];

        // Fetch pending virtual meeting requests
        $pendingMeetingsCount = 0;
        if ($db->tableExists('virtual_meetings')) {
            $pendingMeetingsCount = $db->table('virtual_meetings')
                                       ->where('status', 'requested')
                                       ->countAllResults();
        }
        $data['pending_meetings_count'] = $pendingMeetingsCount;
        $data['dashboard_page'] = $page;


        return view('admin_dashboard', $data);
    }

    /**
     * API endpoint: returns pending meeting request count and student list for AJAX polling.
     */
    public function getPendingMeetings()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Unauthorized'])->setStatusCode(401);
        }

        $db = \Config\Database::connect();

        if (!$db->tableExists('virtual_meetings')) {
            return $this->response->setJSON(['status' => 'success', 'count' => 0, 'requests' => []]);
        }

        $requests = $db->table('virtual_meetings vm')
            ->select('vm.id, vm.student_id, vm.created_at as requested_at, u.name as student_name, s.student_id as student_number, s.section')
            ->join('users u', 'u.id = vm.student_id', 'left')
            ->join('students s', 's.user_id = vm.student_id', 'left')
            ->where('vm.status', 'requested')
            ->orderBy('vm.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->response->setJSON([
            'status'   => 'success',
            'count'    => count($requests),
            'requests' => $requests,
        ]);
    }

    /**
     * Display the System Audit Logs page for administrative oversight.
     */
    public function auditLogs()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 2) {
            return $this->forbidden();
        }

        $db = \Config\Database::connect();
        
        $pager = null;
        $logs = [];
        if ($db->tableExists('audit_logs')) {
            $auditModel = new \App\Models\AuditLogModel();
            $logs = $auditModel
                ->select("audit_logs.id, audit_logs.action, audit_logs.entity_type, audit_logs.entity_id, audit_logs.created_at, audit_logs.ip_address, users.role AS actor_role, CASE WHEN users.role = 0 THEN 'Student account' ELSE users.name END AS actor_name", false)
                ->join('users', 'users.id = audit_logs.user_id', 'left')
                ->orderBy('audit_logs.created_at', 'DESC')
                ->paginate(50);
                
            $pager = $auditModel->pager;
        }

        return view('admin_audit_logs', [
            'logs' => $logs,
            'pager' => $pager
        ]);
    }

    public function fetchVents()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        // AJAX endpoint
        $db = \Config\Database::connect();
        $isArchived = $this->request->getGet('archived') == '1';

        $query = $db->table('vent_logs')
            ->select('vent_logs.*, users.name, students.student_id, students.parent_phone, students.parent_email, students.section, students.adviser_name')
            ->join('users', 'users.id = vent_logs.user_id', 'left')
            ->join('students', 'students.user_id = users.id', 'left');

        if ($isArchived) {
            $query->where('vent_logs.deleted_at IS NOT NULL');
        } else {
            $query->where('vent_logs.deleted_at IS NULL');
        }

        $vents = $query->orderBy('created_at', 'DESC')
            ->limit(500)
            ->get()->getResultArray();

        $encrypter = \Config\Services::encrypter();
        foreach ($vents as &$vent) {
            try {
                if (ctype_xdigit($vent['raw_text'])) {
                    $vent['raw_text'] = $encrypter->decrypt(hex2bin($vent['raw_text']));
                }
            } catch (\Exception $e) {
                // Fallback for older non-encrypted plain text vents
            }
        }

        return $this->response->setJSON($vents);
    }

    public function resolveVent($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table('vent_logs')->where('id', $id)->update(['status' => 'Reviewed']);
        $this->logAudit('RESOLVE', 'vent', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Vent Entry #$id resolved.");
    }

    public function deleteVent($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        // Soft Delete: set deleted_at timestamp instead of permanent removal
        $db->table('vent_logs')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->logAudit('SOFT_DELETE', 'vent', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Vent Entry #$id archived (soft-deleted).");
    }

    public function deleteMultipleVents()
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $ids = $this->request->getPost('vent_ids');
        if (!empty($ids) && is_array($ids)) {
            $db = \Config\Database::connect();
            // Soft Delete: set deleted_at timestamp instead of permanent removal
            $db->table('vent_logs')->whereIn('id', $ids)->update(['deleted_at' => date('Y-m-d H:i:s')]);
            $this->logAudit('BULK_SOFT_DELETE', 'vent', 0);
            return redirect()->to('/admin/dashboard')->with('message', count($ids) . " Vents archived (soft-deleted).");
        }
        return redirect()->to('/admin/dashboard')->with('error', "No vents selected for deletion.");
    }

    public function restoreVent($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table('vent_logs')->where('id', $id)->update(['deleted_at' => null]);
        $this->logAudit('RESTORE', 'vent', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Vent Entry #$id unarchived and restored.");
    }

    public function restoreMultipleVents()
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $ids = $this->request->getPost('vent_ids');
        if (!empty($ids) && is_array($ids)) {
            $db = \Config\Database::connect();
            $db->table('vent_logs')->whereIn('id', $ids)->update(['deleted_at' => null]);
            $this->logAudit('BULK_RESTORE', 'vent', 0);
            return redirect()->to('/admin/dashboard')->with('message', count($ids) . " Vents unarchived and restored.");
        }
        return redirect()->to('/admin/dashboard')->with('error', "No vents selected for restoration.");
    }

    public function fetchIncidents()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $db = \Config\Database::connect();
        $isArchived = $this->request->getGet('archived') == '1';

        $query = $db->table('bully_reports')
            ->select('bully_reports.*, bully_reports.incident_details as description, bully_reports.reporter_student_id as reporter_id, users.name, students.parent_phone, students.parent_email')
            ->join('users', 'users.id = bully_reports.reporter_student_id', 'left')
            ->join('students', 'students.user_id = users.id', 'left');

        if ($isArchived) {
            $query->where('bully_reports.deleted_at IS NOT NULL');
        } else {
            $query->where('bully_reports.deleted_at IS NULL');
        }

        $incidents = $query->orderBy('created_at', 'DESC')
            ->limit(500)
            ->get()->getResultArray();

        $encrypter = \Config\Services::encrypter();
        foreach ($incidents as &$incident) {
            try {
                if (ctype_xdigit($incident['description'])) {
                    $incident['description'] = $encrypter->decrypt(hex2bin($incident['description']));
                }
            } catch (\Exception $e) {
                // Fallback for older non-encrypted plain text descriptions
            }
        }

        return $this->response->setJSON($incidents);
    }

    public function resolveIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table('bully_reports')->where('id', $id)->update(['status' => 'resolved']);
        $this->logAudit('RESOLVE', 'incident', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Incident Report #$id resolved.");
    }

    /**
     * Stage 2: Counselor Verification — Triggers Automated Parent SMS & Email Blast
     * 
     * This is the thesis core feature: "Automated Parent SMS Alerts"
     * The automation is counselor-gated to prevent fake/malicious reports from 
     * triggering parent panic. The counselor clicks one button, and the entire
     * notification pipeline fires automatically — no typing, no manual selection.
     */
    public function verifyIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();

        // Update status to verified
        $db->table('bully_reports')->where('id', $id)->update([
            'status' => 'verified',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        $this->logAudit('VERIFY_AND_ALERT', 'incident', (int) $id);

        // Fetch the full report record
        $report = $db->table('bully_reports')->where('id', $id)->get()->getRow();
        if (!$report) {
            return redirect()->to('/admin/dashboard')->with('error', "Report #$id not found.");
        }

        // --- Execute Automated Parent SMS & Email Blast (Stage 2) ---

        // 1. Alert the Victim's Parent (SMS & Email)
        if (!empty($report->victim_student_id)) {
            $victim = $db->table('users')
                ->select('users.*, students.parent_phone, students.parent_email')
                ->join('students', 'students.user_id = users.id', 'left')
                ->where('users.id', $report->victim_student_id)
                ->get()->getRow();
            if ($victim) {
                $victimMsg = "SVBSM Guidance Alert: A verified report indicates your child may be involved in a bullying incident as a victim. Please offer a warm check-in with your child tonight. Our counselors have confirmed the report and are actively investigating.";
                if (!empty($victim->parent_phone))
                    $this->sendLocalSms($victim->parent_phone, $victimMsg);
                if (!empty($victim->parent_email))
                    $this->sendLocalEmail($victim->parent_email, $victimMsg);
            }
        }

        // 2. Alert the Bully's Parent (SMS & Email)
        if (!empty($report->bully_student_id)) {
            $bully = $db->table('users')
                ->select('users.*, students.parent_phone, students.parent_email')
                ->join('students', 'students.user_id = users.id', 'left')
                ->where('users.id', $report->bully_student_id)
                ->get()->getRow();
            if ($bully) {
                $bullyMsg = "SVBSM Guidance Alert: A verified report involving your child regarding a disciplinary incident has been confirmed by our guidance team. Our administration is currently investigating. Please contact the school office tomorrow.";
                if (!empty($bully->parent_phone))
                    $this->sendLocalSms($bully->parent_phone, $bullyMsg);
                if (!empty($bully->parent_email))
                    $this->sendLocalEmail($bully->parent_email, $bullyMsg);
            }
        }

        return redirect()->to('/admin/dashboard')->with('message', "Incident Report #$id VERIFIED. Automated SMS & Email alerts have been sent to parents.");
    }

    /**
     * Reject a fake/malicious report without sending any parent alerts.
     * 
     * ── False Report Strike System (3-Strike Progressive Discipline) ──
     * Strike 1: Warning shown to student on next login
     * Strike 2: Parent/guardian notified via SMS & Email
     * Strike 3: Student's reporting privileges auto-locked (is_locked = 1)
     */
    public function rejectIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();

        // Fetch the report to identify the reporter
        $report = $db->table('bully_reports')->where('id', $id)->get()->getRow();

        $db->table('bully_reports')->where('id', $id)->update([
            'status' => 'rejected_fake',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        $this->logAudit('REJECT_FAKE', 'incident', (int) $id);

        // ── Strike System Enforcement ──
        $strikeMsg = '';
        if ($report && !empty($report->reporter_student_id)) {
            $reporterId = $report->reporter_student_id;

            // Count total false reports by this student
            $totalStrikes = (int) $db->table('bully_reports')
                ->where('reporter_student_id', $reporterId)
                ->where('status', 'rejected_fake')
                ->where('deleted_at IS NULL')
                ->countAllResults();

            if ($totalStrikes === 2) {
                // ── Strike 2: Notify parent/guardian ──
                $student = $db->table('users')
                    ->select('users.name, students.parent_phone, students.parent_email')
                    ->join('students', 'students.user_id = users.id', 'left')
                    ->where('users.id', $reporterId)
                    ->get()->getRow();

                if ($student) {
                    $parentMsg = "SVBSM Guidance Notice: Your child ({$student->name}) has received a 2nd warning for filing false incident reports on the StudentShield system. One more violation will result in temporary suspension of their reporting privileges. Please discuss responsible use of the school's safety tools with your child.";
                    if (!empty($student->parent_phone))
                        $this->sendLocalSms($student->parent_phone, $parentMsg);
                    if (!empty($student->parent_email))
                        $this->sendLocalEmail($student->parent_email, $parentMsg);
                }
                $strikeMsg = " [STRIKE 2/3 — Parent notified]";

            } elseif ($totalStrikes >= 3) {
                // ── Strike 3: Auto-lock the student's reporting privileges ──
                $db->table('users')->where('id', $reporterId)->update([
                    'is_locked' => 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $this->logAudit('AUTO_LOCK_ACCOUNT', 'user', (int) $reporterId);

                // Notify parent about suspension
                $student = $db->table('users')
                    ->select('users.name, students.parent_phone, students.parent_email')
                    ->join('students', 'students.user_id = users.id', 'left')
                    ->where('users.id', $reporterId)
                    ->get()->getRow();

                if ($student) {
                    $lockMsg = "SVBSM Guidance Alert: Your child ({$student->name}) has had their incident reporting privileges temporarily suspended due to 3 confirmed false reports. To restore access, please visit the Guidance Office for a disciplinary meeting.";
                    if (!empty($student->parent_phone))
                        $this->sendLocalSms($student->parent_phone, $lockMsg);
                    if (!empty($student->parent_email))
                        $this->sendLocalEmail($student->parent_email, $lockMsg);
                }
                $strikeMsg = " [STRIKE 3/3 — Account LOCKED]";

            } else {
                $strikeMsg = " [STRIKE {$totalStrikes}/3]";
            }
        }

        return redirect()->to('/admin/dashboard')->with('message', "Incident Report #$id marked as unverified/fake. No parent alerts were sent.{$strikeMsg}");
    }

    /**
     * Undo a verification or rejection — resets the report back to pending_review.
     * This lets counselors fix accidental button clicks.
     */
    public function undoIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table('bully_reports')->where('id', $id)->update([
            'status' => 'pending_review',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        $this->logAudit('UNDO_TO_PENDING', 'incident', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Incident Report #$id has been reset to Pending Review.");
    }

    private function sendLocalSms($phone, $message)
    {
        if (empty($phone))
            return false;

        $cleanPhone = ltrim($phone, '+');
        if (str_starts_with($cleanPhone, '63') && strlen($cleanPhone) == 12) {
            $cleanPhone = '0' . substr($cleanPhone, 2);
        }

        $payload = json_encode(['phone' => $cleanPhone, 'message' => $message]);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => env('sms.GatewayUrl', ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Connection: close"],
        ]);
        curl_exec($curl);
        curl_close($curl);

        return true;
    }

    private function sendLocalEmail($emailAddress, $messageBody)
    {
        if (empty($emailAddress))
            return false;
        try {
            $email = \Config\Services::email();

            $smtpUser = env('email.SMTPUser', '');
            $smtpPass = env('email.SMTPPass', '');

            $email->initialize([
                'protocol' => 'smtp',
                'SMTPHost' => env('email.SMTPHost', 'smtp.gmail.com'),
                'SMTPUser' => $smtpUser,
                'SMTPPass' => $smtpPass,
                'SMTPPort' => (int) env('email.SMTPPort', 587),
                'SMTPCrypto' => env('email.SMTPCrypto', 'tls'),
                'SMTPTimeout' => 5,
                'mailType' => 'html',
                'charset' => 'utf-8',
            ]);

            $email->setFrom($smtpUser, 'SVBSM Wellness Team');
            $email->setTo($emailAddress);
            $email->setSubject('StudentShield: Protective Services Notification');
            $email->setMessage("<div style='font-family: Arial; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;'><h3>Counseling Alert</h3><p>" . esc($messageBody, 'html') . "</p></div>");
            $email->send(false);
        } catch (\Exception $e) {
        }
    }

    public function deleteIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        // Soft Delete
        $db->table('bully_reports')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->logAudit('SOFT_DELETE', 'incident', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Incident Report #$id archived (soft-deleted).");
    }

    public function deleteMultipleIncidents()
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $ids = $this->request->getPost('incident_ids');
        if (!empty($ids) && is_array($ids)) {
            $db = \Config\Database::connect();
            // Soft Delete
            $db->table('bully_reports')->whereIn('id', $ids)->update(['deleted_at' => date('Y-m-d H:i:s')]);
            $this->logAudit('BULK_SOFT_DELETE', 'incident', 0);
            return redirect()->to('/admin/dashboard')->with('message', count($ids) . " Incident Reports archived (soft-deleted).");
        }
        return redirect()->to('/admin/dashboard')->with('error', "No incidents selected for deletion.");
    }

    public function restoreIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table('bully_reports')->where('id', $id)->update(['deleted_at' => null]);
        $this->logAudit('RESTORE', 'incident', (int) $id);
        return redirect()->to('/admin/dashboard')->with('message', "Incident Report #$id unarchived and restored.");
    }

    public function restoreMultipleIncidents()
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $ids = $this->request->getPost('incident_ids');
        if (!empty($ids) && is_array($ids)) {
            $db = \Config\Database::connect();
            $db->table('bully_reports')->whereIn('id', $ids)->update(['deleted_at' => null]);
            $this->logAudit('BULK_RESTORE', 'incident', 0);
            return redirect()->to('/admin/dashboard')->with('message', count($ids) . " Incident Reports unarchived and restored.");
        }
        return redirect()->to('/admin/dashboard')->with('error', "No incidents selected for restoration.");
    }

    public function fetchAlertLogs()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        // Ensure alerts log file exists, otherwise return empty array
        $logFile = WRITEPATH . 'logs/alerts.json';
        if (!file_exists($logFile)) {
            return $this->response->setJSON([]);
        }

        $logsContent = file_get_contents($logFile);
        $logs = json_decode($logsContent, true) ?: [];

        // Sort descending by timestamp
        usort($logs, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        // Return latest 50
        $logs = array_slice($logs, 0, 50);

        return $this->response->setJSON($logs);
    }

    public function evidence($fileName)
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setStatusCode(401)->setBody('Unauthorized');
        }

        $safeName = basename($fileName);
        $path = WRITEPATH . 'uploads/evidence/' . $safeName;
        if (!is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Evidence not found');
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . $safeName . '"')
            ->setBody(file_get_contents($path));
    }

    public function getStudentProfile($id)
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $db = \Config\Database::connect();

        // 1. Fetch User Data
        $user = $db->table('users')
            ->select('users.id, users.name, users.role, users.is_locked, users.created_at,
                      students.student_id, students.section, students.adviser_name, students.grade_level,
                      students.parent_phone,
                      students.parent_email')
            ->join('students', 'students.user_id = users.id', 'left')
            ->where('users.id', $id)
            ->where('users.role', 0)
            ->get()->getRowArray();
        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'User not found']);
        }

        // Remove sensitive info
        unset($user['password_hash']);

        // 2. Fetch Vents (wellness tracking data)
        $vents = $db->table('vent_logs')
            ->where('user_id', $id)
            ->where('deleted_at IS NULL')
            ->orderBy('created_at', 'ASC')
            ->limit(30)
            ->get()->getResultArray();

        $encrypter = \Config\Services::encrypter();
        $timelineData = [];
        foreach ($vents as &$vent) {
            try {
                if (ctype_xdigit($vent['raw_text'])) {
                    $vent['raw_text'] = $encrypter->decrypt(hex2bin($vent['raw_text']));
                }
            } catch (\Exception $e) {
            }

            // Format for ApexCharts datetime axis (timestamps in ms)
            $risk = $vent['risk_level'] ?? 'LOW';
            $riskUpper = strtoupper($risk);
            if ($riskUpper === 'CRITICAL')
                $y = 100;
            elseif ($riskUpper === 'HIGH')
                $y = 75;
            elseif ($riskUpper === 'MEDIUM')
                $y = 50;
            else
                $y = 25;

            $timelineData[] = [
                'x'                    => strtotime($vent['created_at']) * 1000,
                'y'                    => $y,
                'risk_label'           => $riskUpper,
                'sentiment'            => $vent['risk_percent'] ?? '0',
                'trajectory'           => $vent['trajectory'] ?? 'INSUFFICIENT_DATA',
                'escalation_prob'      => (float)($vent['escalation_probability'] ?? 0.0),
            ];
        }

        // 3. Fetch Involved Incidents
        $incidents = $db->table('bully_reports')
            ->select('id, status, created_at, reporter_student_id, victim_student_id, bully_student_id')
            ->groupStart()
            ->where('reporter_student_id', $id)
            ->orWhere('victim_student_id', $id)
            ->orWhere('bully_student_id', $id)
            ->groupEnd()
            ->orderBy('created_at', 'DESC')
            ->limit(15)
            ->get()->getResultArray();

        // Derive the most-recent trajectory for the counselor badge
        $latestVent = !empty($vents) ? end($vents) : null;
        $currentTrajectory       = $latestVent['trajectory']           ?? 'INSUFFICIENT_DATA';
        $currentEscalationProb   = (float)($latestVent['escalation_probability'] ?? 0.0);

        return $this->response->setJSON([
            'user'               => $user,
            'timeline'           => $timelineData,
            'vents'              => array_reverse($vents),
            'incidents'          => $incidents,
            'current_trajectory' => $currentTrajectory,
            'escalation_prob'    => $currentEscalationProb,
        ]);
    }

    /**
     * Counselor-only indicator, derived from the existing approval queue.
     */
    public function pendingRegistrationCount()
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();

        try {
            $count = \Config\Database::connect()->table('users')
                ->where('role', 0)->where('registration_status', 'pending')->countAllResults();
            return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['count' => $count]);
        } catch (\Throwable $e) {
            log_message('error', 'Pending registration count failed: {message}', ['message' => $e->getMessage()]);
            return $this->response->setStatusCode(503)->setJSON(['message' => 'Registration updates are unavailable.']);
        }
    }

    public function pendingRegistrations()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return redirect()->to('/admin/login');
        }

        $db = \Config\Database::connect();

        $registrationStatus = (string) ($this->request->getGet('status') ?? 'pending');
        if (!in_array($registrationStatus, ['pending', 'approved', 'rejected'], true)) return $this->response->setStatusCode(422);
        $pendingUsers = [];
        try {
            $pendingUsers = $db->table('users')
                ->select('users.id as user_id, users.name, users.created_at, students.student_id, students.lrn, students.grade_level, students.section, students.parent_phone, students.parent_email')
                ->join('students', 'students.user_id = users.id', 'left')
                ->where('users.role', 0)
                ->where('users.registration_status', $registrationStatus)
                ->orderBy('users.created_at', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'AdminController::pendingRegistrations query failed: ' . $e->getMessage());
            $pendingUsers = [];
        }

        return view('admin_pending_registrations', [
            'pendingUsers' => $pendingUsers, 'registrationStatus' => $registrationStatus
        ]);
    }

    /**
     * Approve a pending student account.
     */
    public function approveStudent($userId)
    {
        return $this->decideRegistration((int) $userId, 'approved');
    }

    public function rejectStudent($userId)
    {
        return $this->decideRegistration((int) $userId, 'rejected');
    }

    private function decideRegistration(int $id, string $status)
    {
        if (!$this->hasRole(1)) return $this->forbidden();
        if (strtoupper($this->request->getMethod()) !== 'POST') return $this->response->setStatusCode(405);
        $db = \Config\Database::connect();
        $db->table('users')->where('id', $id)->where('role', 0)->where('registration_status', 'pending')
            ->update(['registration_status' => $status, 'is_active' => $status === 'approved' ? 1 : 0,
                'remember_token' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($db->affectedRows() !== 1) return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'Registration is no longer pending.']);
        $this->logAudit($status === 'approved' ? 'STUDENT_APPROVED' : 'STUDENT_REGISTRATION_REJECTED', 'user', $id);
        if ($this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'json')) {
            return $this->response->setJSON(['status' => 'success', 'message' => 'Registration ' . $status . '.']);
        }
        return redirect()->to('/admin/pending-registrations')->with('success', 'Registration ' . $status . '.');
    }
}
