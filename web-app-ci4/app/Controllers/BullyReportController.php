<?php

namespace App\Controllers;

use App\Models\BullyReportModel;

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
                return redirect()->to('/student/dashboard')->with('error', 'Your reporting privileges have been suspended. Please visit the Guidance Office.');
            }
        }

        $reports = \Config\Database::connect()->table('bully_reports')
            ->select('id, status, created_at')
            ->where('reporter_student_id', (int) $userId)
            ->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();

        return $this->response->setHeader('Cache-Control', 'no-store')
            ->setBody(view('reports/bullying_form', [
                'studentReports' => $reports,
                'showReportHistory' => $this->request->getGet('view') === 'reports',
            ]));
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
                return redirect()->to('/student/dashboard')->with('error', 'Your reporting privileges have been suspended.');
            }
        }

        // Security: Anti-Spam Rate Limiting (1 Bullying Report per 120 seconds)
        // Malicious scripts cannot spam-deplete the SMS Gateway.
        $lastSubmit = session()->get('last_bully_report_time');
        if ($lastSubmit && time() - $lastSubmit < 120) {
            $waitTime = 120 - (time() - $lastSubmit);
            return redirect()->back()->with('error', "Security Limit: Please wait {$waitTime} seconds before submitting again to prevent spam.");
        }
        session()->set('last_bully_report_time', time());
        
        $reporterType = $this->request->getPost('reporter_type') ?? 'self';
        $victimName   = trim($this->request->getPost('victim_name') ?? '');
        $bullyName    = trim($this->request->getPost('bully_name') ?: $this->request->getPost('perpetrator_name') ?: '');
        
        $details      = $this->request->getPost('incident_details') ?: $this->request->getPost('description');
        $incidentType = $this->request->getPost('incident_type');
        if (!empty($incidentType)) {
            $details = "INCIDENT CATEGORY: " . $incidentType . "\n\n" . $details;
        }
        
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

        // Privacy Upgrade & Security Fix: Resolve Victim Name -> ID using strictly unique fuzzy lookup
        $victimStudentId = null;
        if ($reporterType === 'self') {
            $victimStudentId = $userId;
        } else if (!empty($victimName)) {
            $vUsers = $db->table('users')->where('name', $victimName)->get()->getResult();
            // ONLY associate if there is exactly 1 match to prevent wrong-parent notification panics.
            if (count($vUsers) === 1) $victimStudentId = $vUsers[0]->id;
        }

        // Privacy Upgrade & Security Fix: Resolve Bully Name -> ID using strictly unique fuzzy lookup
        // Note: If multiple comma-separated names are provided, fuzzy lookup will safely fail, keeping bully_student_id Null. 
        // This is a feature, not a bug, protecting parents from mass incorrect automated SMS panics. The counselor will read the exact list below manually.
        $bullyStudentId = null;
        if (!empty($bullyName) && !str_contains($bullyName, ',')) {
            $bUsers = $db->table('users')->like('name', $bullyName)->get()->getResult();
            // ONLY associate if there is exactly 1 match to prevent wrong-parent notification panics.
            if (count($bUsers) === 1) $bullyStudentId = $bUsers[0]->id;
        }

        $encrypter = \Config\Services::encrypter();
        $rawDetails = "TARGETED STUDENT: " . ($victimName ?: 'Self') . "\nREPORTED AGGRESSOR(S): " . ($bullyName ?: 'N/A') . "\n\n" . $details;
        $encryptedDetails = bin2hex($encrypter->encrypt($rawDetails));
        
        $reportData = [
            'reporter_student_id' => $userId,
            'reporter_type'       => $reporterType,
            'victim_student_id'   => $victimStudentId,
            'bully_student_id'    => $bullyStudentId,
            'incident_details'    => $encryptedDetails,
            'status'              => 'pending_review',
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
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

        $this->logAudit('CREATE_INCIDENT', 'incident', (int) $insertId);

        // --- Stage 1: Instant Counselor Alert (Report Intake) ---
        // Parent alerts are NOT sent here. They fire only after counselor verification (Stage 2)
        // to prevent fake/malicious reports from triggering parent panic SMS blasts.
        
        // 1. Alert the Reporter (Email) — confirmation only, no sensitive details
        $reporter = $db->table('users')
            ->select('users.*, COALESCE(students.parent_email, counselors.counselor_email, admins.email) AS email')
            ->join('students', 'students.user_id = users.id', 'left')
            ->join('counselors', 'counselors.user_id = users.id', 'left')
            ->join('admins', 'admins.user_id = users.id', 'left')
            ->where('users.id', $userId)
            ->get()->getRow();
        if ($reporter && !empty($reporter->email)) {
            $this->sendLocalEmail($reporter->email, "Thank you for speaking up. Your courageous report has been securely received by the SVBSM Counseling Team. A counselor will review it shortly. You are safe.");
        }

        // 2. Alert the Counselor Immediately (SMS & Email) — so they can investigate ASAP
        $counselorPhone = env('COUNSELOR_PHONE', '');
        $counselorEmail = env('COUNSELOR_EMAIL', '');
        if (!empty($counselorPhone)) {
            $this->sendLocalSms($counselorPhone, "StudentShield URGENT: A new Bullying Report was just filed and requires your verification. Please log into the admin dashboard immediately to investigate and verify the report before parent alerts are sent.");
        }
        if (!empty($counselorEmail)) {
            $this->sendLocalEmail($counselorEmail, "StudentShield URGENT: A new Bullying Report was just filed and requires your verification. Please log into the admin dashboard immediately to investigate and verify the report before parent alerts are sent.");
        }

        // NOTE: Parent SMS & Email alerts are now triggered via Stage 2 (Counselor Verification)
        // See AdminController::verifyIncident() — counselor clicks "Verify & Alert Parents" on the dashboard

        return redirect()->to('/report/bullying')->with('message', 'Your report has been securely submitted. A school counselor has been alerted and will review it shortly.');
    }

    private function sendLocalSms($phone, $message)
    {
        // Loophole 4 Mitigation: Graceful skip for missing phone numbers
        if (empty($phone)) return false;

        $cleanPhone = ltrim($phone, '+');
        if (str_starts_with($cleanPhone, '63') && strlen($cleanPhone) == 12) {
            $cleanPhone = '0' . substr($cleanPhone, 2);
        }

        $payload = json_encode(['phone' => $cleanPhone, 'message' => $message]);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => env('sms.GatewayUrl', ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1, // Ultra-fast fail timeout mapping to prevent bottlenecking the main UI
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
        if(empty($emailAddress)) return false;
        try {
            $email = \Config\Services::email();
            
            // Safe fallback configuration using environment variables
            $smtpUser = env('email.SMTPUser', '');
            $smtpPass = env('email.SMTPPass', '');
            
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
            $email->send(false);
        } catch (\Exception $e) {}
    }

}
