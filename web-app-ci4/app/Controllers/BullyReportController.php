<?php

namespace App\Controllers;

use App\Libraries\StudentDirectory;

class BullyReportController extends BaseController
{
    public function index()
    {
        if (!$this->hasRole(0)) return $this->forbidden();
        // Block locked students from accessing the bullying report form
        $userId = session()->get('user_id');
        if ($userId) {
            $db = \Config\Database::connect();
            $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
            if (!empty($user['is_locked'])) {
                return redirect()->to('/student/dashboard')->with('error', 'Bullying reports are paused for your account. You can still use Safe Space or approach the Guidance Office.');
            }
        }

        $reports = \Config\Database::connect()->table('bully_reports')
            ->select('id, status, created_at')
            ->where('reporter_student_id', (int) $userId)
            ->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();

        return $this->response->setHeader('Cache-Control', 'no-store')
            ->setBody(view('reports/bullying_form', [
                'studentReports' => $reports,
                'directorySections' => \App\Libraries\SchoolRoster::sections(),
                'showReportHistory' => $this->request->getGet('view') === 'reports',
            ]));
    }

    public function directory()
    {
        if (!$this->hasRole(0)) return $this->forbidden();
        $grade = StudentDirectory::grade($this->request->getGet('grade'));
        $section = $this->request->getGet('section');
        $search = $this->request->getGet('q');
        if ($grade === null || !is_string($section) || trim($section) === '' || mb_strlen($section) > 80
            || !is_string($search) || mb_strlen(trim($search)) < 2 || mb_strlen($search) > 80) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Choose a grade and section, then enter at least two letters.']);
        }
        return $this->response->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['students' => StudentDirectory::search(\Config\Database::connect(), $grade, trim($section), trim($search))]);
    }

    public function submit()
    {
        if (!$this->hasRole(0)) return $this->forbidden();
        if (!$this->request->is('post')) return redirect()->to('/report/bullying');

        // Block locked students from submitting reports
        $userId = session()->get('user_id');
        if ($userId) {
            $db2 = \Config\Database::connect();
            $user = $db2->table('users')->where('id', $userId)->get()->getRowArray();
            if (!empty($user['is_locked'])) {
                return redirect()->to('/student/dashboard')->with('error', 'Bullying reports are paused for your account. You can still use Safe Space or approach the Guidance Office.');
            }
        }

        // Security: Anti-Spam Rate Limiting (1 Bullying Report per 120 seconds)
        // Malicious scripts cannot spam-deplete the SMS Gateway.
        $lastSubmit = session()->get('last_bully_report_time');
        if ($lastSubmit && time() - $lastSubmit < 120) {
            $waitTime = 120 - (time() - $lastSubmit);
            return redirect()->back()->with('error', "Security Limit: Please wait {$waitTime} seconds before submitting again to prevent spam.");
        }
        
        $reporterType = $this->request->getPost('reporter_type') ?? 'self';
        $victimName = $this->request->getPost('victim_name') ?? '';
        $bullyName = $this->request->getPost('bully_name') ?: $this->request->getPost('perpetrator_name') ?: '';
        $details = $this->request->getPost('incident_details') ?: $this->request->getPost('description');
        $incidentType = $this->request->getPost('incident_type');
        if (!in_array($reporterType, ['self', 'bystander'], true) || !is_string($details)
            || trim($details) === '' || mb_strlen($details) > 2000
            || !is_string($victimName) || !is_string($bullyName)
            || mb_strlen($victimName) > 160 || mb_strlen($bullyName) > 160
            || ($incidentType !== null && (!is_string($incidentType) || mb_strlen($incidentType) > 100))) {
            return redirect()->back()->withInput()->with('error', 'Check the people involved and describe the incident in up to 2,000 characters.');
        }
        $db = \Config\Database::connect();
        $victimStudentId = $reporterType === 'self' ? (int) $userId : null;
        $bullyStudentId = null;
        foreach (['victim' => $reporterType === 'bystander', 'bully' => true] as $person => $required) {
            if (!$required) continue;
            $selectedId = $this->request->getPost($person . '_student_id');
            if ($selectedId === 'unknown' || $selectedId === null) {
                if (trim($person === 'victim' ? $victimName : $bullyName) === '') {
                    return redirect()->back()->withInput()->with('error', 'Select the people involved or describe someone who is not listed.');
                }
                continue;
            }
            $selected = StudentDirectory::selected($db, $selectedId,
                $this->request->getPost($person . '_grade'), $this->request->getPost($person . '_section'));
            if (!$selected || ($person === 'bully' && (int) $selected['id'] === (int) $userId)) {
                return redirect()->back()->withInput()->with('error', 'Choose a valid student from the selected grade and section.');
            }
            if ($person === 'victim') {
                $victimStudentId = (int) $selected['id'];
                $victimName = $selected['name'];
            } else {
                $bullyStudentId = (int) $selected['id'];
                $bullyName = $selected['name'];
            }
        }
        if ($reporterType === 'bystander' && $victimStudentId === $bullyStudentId && $victimStudentId !== null) {
            return redirect()->back()->withInput()->with('error', 'The targeted student and reported aggressor must be different people.');
        }
        if ($reporterType === 'self') $victimName = 'Self';
        if ($incidentType) $details = "INCIDENT CATEGORY: " . $incidentType . "\n\n" . $details;

        $evidenceFile = $this->request->getFile('evidence_file');
        $evidencePath = null;

        if ($evidenceFile && $evidenceFile->isValid() && !$evidenceFile->hasMoved()) {
            $rules = [
                'evidence_file' => [
                    'rules' => 'max_size[evidence_file,10240]|ext_in[evidence_file,jpg,jpeg,png,gif,webp,heic,heif,mp4,mov,avi,mkv,webm]|mime_in[evidence_file,image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm]',
                    'errors' => [
                        'max_size' => 'The evidence file must not exceed 10MB.',
                        'ext_in'   => 'Only images (JPG, PNG, GIF, WEBP, HEIC) and videos (MP4, MOV, AVI, MKV, WEBM) are allowed.',
                        'mime_in'  => 'The uploaded evidence file type is not allowed.'
                    ]
                ]
            ];

            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('error', $this->validator->getError('evidence_file'));
            }

            $evidenceDir = WRITEPATH . 'uploads/evidence';
            if (!is_dir($evidenceDir)) {
                mkdir($evidenceDir, 0755, true);
            }
            $newName = $evidenceFile->getRandomName();
            $evidenceFile->move($evidenceDir, $newName);
            $evidencePath = 'evidence/' . $newName;
        }
        
        // Loophole 3 Mitigation: Identity Traceability via Session
        $userId = session()->get('user_id'); 
        if (!$userId) {
            return redirect()->to('/login')->with('error', 'Authentication required. Please log in.');
        }

        $db = \Config\Database::connect();

        $encrypter = \Config\Services::encrypter();
        $rawDetails = "TARGETED STUDENT: " . ($victimName ?: 'Self') . "\nREPORTED AGGRESSOR(S): " . ($bullyName ?: 'N/A') . "\n\n" . $details;
        $encryptedDetails = bin2hex($encrypter->encrypt($rawDetails));
        
        $nowManila = \App\Libraries\ManilaTime::now();
        $reportData = [
            'reporter_student_id' => $userId,
            'reporter_type'       => $reporterType,
            'victim_student_id'   => $victimStudentId,
            'bully_student_id'    => $bullyStudentId,
            'incident_details'    => $encryptedDetails,
            'status'              => 'pending_review',
            'created_at'          => $nowManila,
            'updated_at'          => $nowManila,
        ];

        // Only include evidence_file if a file was actually uploaded
        if (!empty($evidencePath)) {
            $reportData['evidence_file'] = $evidencePath;
        }

        // Simple direct insert — no transaction wrapper (InfinityFree MyISAM tables don't support transactions)
        try {
            $db->table('bully_reports')->insert($reportData);
            $insertId = $db->insertID();
            
            if (empty($insertId)) {
                $dbError = $db->error();
                log_message('error', 'Insert returned no ID - DB error: ' . json_encode($dbError) . ' | Data keys: ' . json_encode(array_keys($reportData)));
                return redirect()->back()->with('error', 'Submission failed due to a system error. Please try again.');
            }
        } catch (\Exception $e) {
            log_message('error', 'DB Exception: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Submission failed due to a system error. Please try again.');
        }

        session()->set('last_bully_report_time', time());
        $this->logAudit('CREATE_INCIDENT', 'incident', (int) $insertId);

        // --- Stage 1: Instant Counselor Alert (Report Intake) ---
        // Parent alerts are NOT sent here. They fire only after counselor verification (Stage 2)
        // to prevent fake/malicious reports from triggering parent panic SMS blasts.
        
        // The reporter receives the confirmation in-app. Guardian alerts require counselor verification.

        // 2. Alert the Counselor Immediately (SMS & Email) — so they can investigate ASAP
        $counselorPhone = env('COUNSELOR_PHONE', '');
        $counselorEmail = env('COUNSELOR_EMAIL', '');
        if (!empty($counselorPhone)) {
            $this->sendLocalSms($counselorPhone,
                'StudentShield: A new bullying report needs verification. Open the counselor dashboard. Parent alerts await verification.',
                "incident:{$insertId}:intake-counselor");
        }
        if (!empty($counselorEmail)) {
            $this->sendLocalEmail($counselorEmail, "StudentShield URGENT: A new Bullying Report was just filed and requires your verification. Please log into the admin dashboard immediately to investigate and verify the report before parent alerts are sent.");
        }

        // NOTE: Parent SMS & Email alerts are now triggered via Stage 2 (Counselor Verification)
        // See AdminController::verifyIncident() — counselor clicks "Verify & Alert Parents" on the dashboard

        return redirect()->to('/report/bullying')->with('message', 'Your report has been received for counselor review. You can check its progress in My reports. If you need help while waiting, please approach the Guidance Office or a trusted school staff member.');
    }

    private function sendLocalSms($phone, $message, string $eventKey)
    {
        // Loophole 4 Mitigation: Graceful skip for missing phone numbers
        if (empty($phone)) return false;

        $result = \Config\Services::smsGateway()->send((string) $phone, (string) $message, $eventKey);
        \App\Libraries\AlertLog::record('SMS (Counselor)', (string) $phone, (string) $message, $result);
        return $result['accepted'];
    }
    
    private function sendLocalEmail($emailAddress, $messageBody)
    {
        if (empty($emailAddress)) return false;
        try {
            $email = \Config\Services::email();
            
            // Safe fallback configuration using environment variables
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
                'SMTPPort' => (int)env('email.SMTPPort', 587),
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

}
