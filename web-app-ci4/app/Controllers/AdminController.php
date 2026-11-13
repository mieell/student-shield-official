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

        $this->decryptRowsField($vents, 'raw_text');
        $sources = [];
        if ($vents) {
            $events = $db->table('audit_logs')->select('entity_id, action')->where('entity_type', 'vent')
                ->whereIn('entity_id', array_column($vents, 'id'))
                ->whereIn('action', ['VENT_ANALYSIS_MODEL', 'VENT_ANALYSIS_LOCAL'])
                ->orderBy('id', 'ASC')->get()->getResultArray();
            foreach ($events as $event) $sources[$event['entity_id']] = $event['action'] === 'VENT_ANALYSIS_MODEL' ? 'model' : 'local';
        }
        foreach ($vents as &$vent) {
            $vent['screening_analysis'] = \App\Libraries\ScreeningExplanation::decode($vent['analysis_details'] ?? null);
            $vent['analysis_source'] = $vent['screening_analysis']['source'] ?? $sources[$vent['id']] ?? 'unknown';
            unset($vent['analysis_details']);
        }
        unset($vent);
        return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON($vents);
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
        return $this->setArchiveState('vent_logs', (int) $id, date('Y-m-d H:i:s'), 'SOFT_DELETE', 'vent', '/admin/dashboard', "Vent Entry #$id archived (soft-deleted).");
    }

    public function deleteMultipleVents()
    {
        return $this->setBulkArchiveState('vent_logs', 'vent_ids', date('Y-m-d H:i:s'), 'BULK_SOFT_DELETE', 'vent', '/admin/dashboard', 'Vents archived (soft-deleted).', 'No vents selected for deletion.');
    }

    public function restoreVent($id)
    {
        return $this->setArchiveState('vent_logs', (int) $id, null, 'RESTORE', 'vent', '/admin/dashboard', "Vent Entry #$id unarchived and restored.");
    }

    public function restoreMultipleVents()
    {
        return $this->setBulkArchiveState('vent_logs', 'vent_ids', null, 'BULK_RESTORE', 'vent', '/admin/dashboard', 'Vents unarchived and restored.', 'No vents selected for restoration.');
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

        $this->decryptRowsField($incidents, 'description');

        return $this->response->setJSON($incidents);
    }

    public function resolveIncident($id)
    {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table('bully_reports')->where('id', $id)->where('status', 'verified')
            ->where('deleted_at', null)->update(['status' => 'resolved']);
        if ($db->affectedRows() !== 1) {
            return redirect()->to('/admin/incidents')->with('error', 'Only an active, verified report can be closed. Refresh the incidents list to check its status.');
        }
        $this->logAudit('RESOLVE', 'incident', (int) $id);
        return redirect()->to('/admin/incidents')->with('message', "Incident Report #$id closed. Its student-facing status has been updated.");
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
            return redirect()->to('/admin/incidents')->with('error', "Report #$id not found.");
        }

        // --- Execute Automated Parent SMS & Email Blast (Stage 2) ---
        $smsAccepted = 0;
        $smsUnconfirmed = 0;

        if (!empty($report->victim_student_id)) {
            $this->notifyIncidentParent(
                $db,
                (int) $id,
                (int) $report->victim_student_id,
                'victim',
                'SVBSM Guidance Alert: A verified report indicates your child may be involved in a bullying incident as a victim. Please offer a warm check-in with your child tonight. Our counselors have confirmed the report and are actively investigating.',
                $smsAccepted,
                $smsUnconfirmed
            );
        }

        if (!empty($report->bully_student_id)) {
            $this->notifyIncidentParent(
                $db,
                (int) $id,
                (int) $report->bully_student_id,
                'bully',
                'SVBSM Guidance Alert: A verified report involving your child regarding a disciplinary incident has been confirmed by our guidance team. Our administration is currently investigating. Please contact the school office tomorrow.',
                $smsAccepted,
                $smsUnconfirmed
            );
        }

        return redirect()->to('/admin/incidents')->with('message', "Incident Report #$id VERIFIED. SMS gateway accepted {$smsAccepted} request(s); delivery is not yet confirmed. {$smsUnconfirmed} SMS request(s) were not confirmed. Email notifications were attempted for available contacts.");
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
                    $this->routeGuardianContact($db, $student, (int) $reporterId);
                    $parentMsg = "SVBSM Guidance Notice: Your child ({$student->name}) has received a 2nd warning for filing false incident reports on the StudentShield system. One more violation will result in temporary suspension of their reporting privileges. Please discuss responsible use of the school's safety tools with your child.";
                    if (!empty($student->parent_phone))
                        $this->sendLocalSms($student->parent_phone,
                            'SVBSM Guidance: Please contact the Guidance Office about the second warning for false StudentShield reports involving your child.',
                            "incident:{$id}:strike-2-parent");
                    if (!empty($student->parent_email))
                        $this->sendLocalEmail($student->parent_email, $parentMsg);
                }
                $strikeMsg = " [STRIKE 2/3 — Parent notification attempted]";

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
                    $this->routeGuardianContact($db, $student, (int) $reporterId);
                    $lockMsg = "SVBSM Guidance Alert: Your child ({$student->name}) has had their incident reporting privileges temporarily suspended due to 3 confirmed false reports. To restore access, please visit the Guidance Office for a disciplinary meeting.";
                    if (!empty($student->parent_phone))
                        $this->sendLocalSms($student->parent_phone,
                            'SVBSM Guidance: Reporting access for your child was suspended after confirmed false reports. Please contact the Guidance Office.',
                            "incident:{$id}:account-lock-parent");
                    if (!empty($student->parent_email))
                        $this->sendLocalEmail($student->parent_email, $lockMsg);
                }
                $strikeMsg = " [STRIKE 3/3 — Account LOCKED]";

            } else {
                $strikeMsg = " [STRIKE {$totalStrikes}/3]";
            }
        }

        return redirect()->to('/admin/incidents')->with('message', "Incident Report #$id marked as unverified/fake. No parent alerts were sent.{$strikeMsg}");
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
        return redirect()->to('/admin/incidents')->with('message', "Incident Report #$id has been reset to Pending Review.");
    }

    private function sendLocalSms($phone, $message, string $eventKey)
    {
        if (empty($phone))
            return false;

        $result = \Config\Services::smsGateway()->send((string) $phone, (string) $message, $eventKey);
        \App\Libraries\AlertLog::record('SMS (Gateway)', (string) $phone, (string) $message, $result);
        return $result['accepted'];
    }

    private function sendLocalEmail($emailAddress, $messageBody)
    {
        if (empty($emailAddress))
            return false;
        try {
            $email = \Config\Services::email();

            $smtpUser = env('email.SMTPUser', '');
            $smtpPass = env('email.SMTPPass', '');
            if (empty($smtpUser) || empty($smtpPass)) {
                \App\Libraries\AlertLog::record('Email', (string) $emailAddress, (string) $messageBody, ['accepted' => false, 'state' => 'NotConfigured']);
                return false;
            }

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
            $sent = (bool) $email->send(false);
            \App\Libraries\AlertLog::record('Email', (string) $emailAddress, (string) $messageBody, ['accepted' => $sent, 'state' => $sent ? 'Sent' : 'Failed']);
            return $sent;
        } catch (\Exception $e) {
            \App\Libraries\AlertLog::record('Email', (string) $emailAddress, (string) $messageBody, ['accepted' => false, 'state' => 'Failed']);
            return false;
        }
    }

    public function deleteIncident($id)
    {
        return $this->setArchiveState('bully_reports', (int) $id, date('Y-m-d H:i:s'), 'SOFT_DELETE', 'incident', '/admin/incidents', "Incident Report #$id archived (soft-deleted).");
    }

    public function deleteMultipleIncidents()
    {
        return $this->setBulkArchiveState('bully_reports', 'incident_ids', date('Y-m-d H:i:s'), 'BULK_SOFT_DELETE', 'incident', '/admin/incidents', 'Incident Reports archived (soft-deleted).', 'No incidents selected for deletion.');
    }

    public function restoreIncident($id)
    {
        return $this->setArchiveState('bully_reports', (int) $id, null, 'RESTORE', 'incident', '/admin/incidents', "Incident Report #$id unarchived and restored.");
    }

    public function restoreMultipleIncidents()
    {
        return $this->setBulkArchiveState('bully_reports', 'incident_ids', null, 'BULK_RESTORE', 'incident', '/admin/incidents', 'Incident Reports unarchived and restored.', 'No incidents selected for restoration.');
    }

    public function fetchAlertLogs()
    {
        if (!session()->get('isLoggedIn') || (int) session()->get('role') !== 1) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setHeader('Pragma', 'no-cache');

        // Ensure alerts log file exists, otherwise return empty array
        $logFile = \App\Libraries\AlertLog::path();
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
        $guardianSafety = \App\Libraries\GuardianAlertRouting::settings($db, (int) $id);
        $guardianRoute = \App\Libraries\GuardianAlertRouting::resolve($user, $guardianSafety);


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

        return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON([
            'user'               => $user,
            'guardian_safety'    => $guardianSafety,
            'guardian_route_note' => $guardianRoute['note'],
            'csrf' => csrf_hash(),
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
        if (!$this->hasRole(1) && !$this->hasRole(2)) return $this->forbidden();

        $db = \Config\Database::connect();

        $registrationStatus = (string) ($this->request->getGet('status') ?? 'pending');
        if (!in_array($registrationStatus, ['pending', 'approved', 'rejected', 'archived'], true)) return $this->response->setStatusCode(422);
        $pendingUsers = [];
        try {
            $pendingUsers = $db->table('users')
                ->select('users.id as user_id, users.name, users.created_at, students.student_id, students.lrn, students.grade_level, students.section, students.parent_phone, students.parent_email,
                    gc.phone as verified_phone, gc.verified_name, gc.guardian_name, gc.response, gc.responded_at, gc.sms_state, gc.requested_at, gc.expires_at')
                ->join('students', 'students.user_id = users.id', 'left')
                ->join('guardian_consents gc', 'gc.user_id = users.id', 'left')
                ->where('users.role', 0)
                ->where('users.registration_status', $registrationStatus)
                ->orderBy('users.created_at', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'AdminController::pendingRegistrations query failed: ' . $e->getMessage());
            return $this->response->setStatusCode(503)->setBody('Registrations are unavailable. Check that the guardian consent database update is installed.');
        }

        $this->response->setHeader('Cache-Control', 'private, no-store');
        return view('admin_pending_registrations', [
            'pendingUsers' => $pendingUsers, 'registrationStatus' => $registrationStatus,
            'canVerifyContact' => $this->hasRole(2),
        ]);
    }

    /**
     * Approve a pending student account.
     */
    public function archiveRegistration(int $id)
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        $back = redirect()->to(site_url('admin/pending-registrations'));
        $db = \Config\Database::connect();
        $userUpdate = ['registration_status' => 'archived', 'is_active' => 0, 'remember_token' => null];
        if ($this->tableHasColumn($db, 'users', 'updated_at')) {
            $userUpdate['updated_at'] = date('Y-m-d H:i:s');
        }
        $db->table('users')->where('id', $id)->where('role', 0)->where('registration_status', 'pending')
            ->update($userUpdate);
        if ($db->affectedRows() !== 1) {
            return $back->with('error', 'Only pending student registrations can be archived.');
        }
        \App\Libraries\GuardianConsent::clearTokens($id);
        $this->logAudit('STUDENT_REGISTRATION_ARCHIVED', 'user', $id);
        return $back->with('success', 'Registration archived. Any outstanding consent link is closed.');
    }

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
        if ($status === 'approved') {
            $db->transBegin();
            try {
                $user = \App\Libraries\GuardianConsent::lockStudent($db, $id);
                $consent = $db->table('guardian_consents')->where('user_id', $id)->where('response', 'accepted')->get()->getRowArray();
                if (!$user || !$consent) {
                    $db->transRollback();
                    return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'A pending registration needs accepted guardian consent before approval.']);
                }
                if (!$db->table('students')->where('user_id', $id)->countAllResults()) {
                    $db->transRollback();
                    return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'The student profile is missing.']);
                }
                $db->table('students')->where('user_id', $id)->update(['parent_phone' => $consent['phone']]);
            } catch (\Throwable $e) {
                $db->transRollback();
                return $this->response->setStatusCode(503)->setJSON(['status' => 'error', 'message' => 'Guardian consent could not be checked.']);
            }
        }
        $userUpdate = [
            'registration_status' => $status,
            'is_active' => $status === 'approved' ? 1 : 0,
            'remember_token' => null,
        ];
        if ($this->tableHasColumn($db, 'users', 'updated_at')) {
            $userUpdate['updated_at'] = date('Y-m-d H:i:s');
        }
        $db->table('users')->where('id', $id)->where('role', 0)->where('registration_status', 'pending')
            ->update($userUpdate);
        if ($db->affectedRows() !== 1 || ($status === 'approved' && (!$db->transStatus() || !$db->transCommit()))) {
            if ($status === 'approved') $db->transRollback();
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'Registration could not be updated.']);
        }
        \App\Libraries\GuardianConsent::clearTokens($id);
        $this->logAudit($status === 'approved' ? 'STUDENT_APPROVED' : 'STUDENT_REGISTRATION_REJECTED', 'user', $id);
        if ($this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'json')) {
            return $this->response->setJSON(['status' => 'success', 'message' => 'Registration ' . $status . '.']);
        }
        return redirect()->to('/admin/pending-registrations')->with('success', 'Registration ' . $status . '.');
    }

    private function tableHasColumn($db, string $table, string $column): bool
    {
        try {
            return in_array($column, $db->getFieldNames($table), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function decryptRowsField(array &$rows, string $field): void
    {
        $encrypter = \Config\Services::encrypter();
        foreach ($rows as &$row) {
            try {
                if (isset($row[$field]) && is_string($row[$field]) && ctype_xdigit($row[$field])) {
                    $row[$field] = $encrypter->decrypt(hex2bin($row[$field]));
                }
            } catch (\Exception $e) {
                // Fallback for older plain-text entries
            }
        }
    }

    private function setArchiveState(
        string $table,
        int $id,
        ?string $deletedAt,
        string $auditAction,
        string $entity,
        string $redirectUrl,
        string $message
    ) {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $db = \Config\Database::connect();
        $db->table($table)->where('id', $id)->update(['deleted_at' => $deletedAt]);
        $this->logAudit($auditAction, $entity, $id);
        return redirect()->to($redirectUrl)->with('message', $message);
    }

    private function setBulkArchiveState(
        string $table,
        string $postKey,
        ?string $deletedAt,
        string $auditAction,
        string $entity,
        string $redirectUrl,
        string $successSuffix,
        string $emptyError
    ) {
        if (!$this->guardStaffAccess()) return $this->forbidden();
        $ids = $this->request->getPost($postKey);
        if (!empty($ids) && is_array($ids)) {
            $db = \Config\Database::connect();
            $db->table($table)->whereIn('id', $ids)->update(['deleted_at' => $deletedAt]);
            $this->logAudit($auditAction, $entity, 0);
            return redirect()->to($redirectUrl)->with('message', count($ids) . ' ' . $successSuffix);
        }
        return redirect()->to($redirectUrl)->with('error', $emptyError);
    }

    private function notifyIncidentParent(
        $db,
        int $incidentId,
        int $studentUserId,
        string $roleLabel,
        string $emailMessage,
        int &$smsAccepted,
        int &$smsUnconfirmed
    ): void {
        $student = $db->table('users')
            ->select('users.*, students.parent_phone, students.parent_email')
            ->join('students', 'students.user_id = users.id', 'left')
            ->where('users.id', $studentUserId)
            ->get()->getRow();
        if (!$student) {
            return;
        }
        $this->routeGuardianContact($db, $student, $studentUserId);
        if (!empty($student->parent_phone)) {
            $accepted = $this->sendLocalSms(
                $student->parent_phone,
                'SVBSM Guidance: A verified incident involving your child needs follow-up. Please contact the Guidance Office.',
                "incident:{$incidentId}:verified-{$roleLabel}-parent"
            );
            $accepted ? $smsAccepted++ : $smsUnconfirmed++;
        }
        if (!empty($student->parent_email)) {
            $this->sendLocalEmail($student->parent_email, $emailMessage);
        }
    }
    private function routeGuardianContact($db, object $student, int $userId): void
    {
        $settings = \App\Libraries\GuardianAlertRouting::settings($db, $userId);
        $route = \App\Libraries\GuardianAlertRouting::resolve((array) $student, $settings);
        $student->parent_phone = $route['phone'];
        $student->parent_email = $route['email'];
        if ($route['blocked']) {
            $this->logAudit('GUARDIAN_OUTREACH_REQUIRED', 'student', $userId);
        }
    }

}
