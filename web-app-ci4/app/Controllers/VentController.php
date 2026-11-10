<?php

namespace App\Controllers;

use App\Libraries\ExplicitCrisisGuard;
use App\Libraries\VentRiskAnalyzer;
use App\Libraries\ViolentThreats;

class VentController extends BaseController
{
    public function index()
    {
        if (!$this->hasRole(0)) return $this->forbidden();
        return view('vent_form');
    }

    public function submit()
    {
        if (!$this->hasRole(0)) return $this->forbidden();
        try {
        $text = $this->request->getPost('vent_text');
        $userId = session()->get('user_id');
        if (empty($userId)) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Authentication required.']);
            }
            return redirect()->to('/login')->with('error', 'Authentication required. Please log in.');
        }

        // Security: Anti-Spam Rate Limiting (1 vent per 30 seconds)
        $lastSubmit = session()->get('last_vent_time');
        if ($lastSubmit && time() - $lastSubmit < 30) {
            $waitTime = 30 - (time() - $lastSubmit);
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['status' => 'error', 'message' => "Security Cooldown: Please wait {$waitTime} seconds before submitting again."]);
            }
            return redirect()->back()->with('error', "Security Cooldown: Please wait {$waitTime} seconds before submitting again.");
        }
        session()->set('last_vent_time', time());

        if (empty($text)) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['status' => 'error', 'message' => 'Please enter text.']);
            }
            return redirect()->back()->with('error', 'Please enter some text before submitting.');
        }

        // Security: Input length validation (prevent memory abuse on ML service)
        if (mb_strlen($text) > 5000) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['status' => 'error', 'message' => 'Your vent is too long. Please keep it under 5000 characters.']);
            }
            return redirect()->back()->with('error', 'Your vent is too long. Please keep it under 5000 characters.');
        }

        // --- 1. CALL ML SERVICE FOR RISK ANALYSIS ---
        $mlApiBase = env('ML_API_URL') ?: (getenv('ML_API_URL') ?: 'https://ashlogg-student-shield-official.hf.space');
        $mlApiBase = rtrim($mlApiBase, '/');
        $mlApiKey  = trim((string) (env('ML_API_KEY') ?: getenv('ML_API_KEY') ?: ''));

        // The web database is the durable source for the student's 14-day risk
        // history. Sending only risk labels and timestamps avoids transmitting
        // previous vent text and survives Hugging Face container rebuilds.
        $db = \Config\Database::connect();
        $riskHistory = [];
        try {
            $historyRows = $db->table('vent_logs')
                ->select('risk_level, created_at')
                ->where('user_id', $userId)
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-14 days')))
                ->where('deleted_at IS NULL')
                ->orderBy('created_at', 'ASC')
                ->limit(100)
                ->get()->getResultArray();

            foreach ($historyRows as $historyRow) {
                $historyRisk = strtoupper((string) ($historyRow['risk_level'] ?? ''));
                $historyTimestamp = strtotime((string) ($historyRow['created_at'] ?? ''));
                if (in_array($historyRisk, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) && $historyTimestamp !== false) {
                    $riskHistory[] = [
                        'risk_level' => $historyRisk,
                        'created_at' => gmdate('c', $historyTimestamp),
                    ];
                }
            }
        } catch (\Throwable $historyError) {
            log_message('warning', 'Risk history query failed; continuing without trajectory history: ' . $historyError->getMessage());
        }
        
        // Handle potentially invalid UTF-8 (smart quotes) which makes json_encode return false
        $payloadData = [
            'raw_text' => $text,
            'student_id' => (string) $userId,
            'risk_history' => $riskHistory,
        ];
        $payload = json_encode($payloadData, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            log_message('error', 'JSON encode failed: ' . json_last_error_msg());
            $payloadData['raw_text'] = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            $payload = json_encode($payloadData);
        }

        $response = false;
        $httpCode = 0;
        if ($mlApiKey !== '') {
            $ch = curl_init($mlApiBase . '/analyze-text');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $mlApiKey
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            log_message('error', 'ML_API_KEY is not configured; checking vent fallback.');
        }

        $mlResult = is_string($response) ? json_decode($response, true) : null;
        if ($httpCode < 200 || $httpCode >= 300 || !VentRiskAnalyzer::valid($mlResult)) {
            log_message('warning', 'Primary vent analysis unavailable (HTTP {status}); checking configured fallback.', ['status' => $httpCode]);
        }
        $assessment = VentRiskAnalyzer::resolve($text, $mlResult, (int) $httpCode,
            fn (string $entry) => $this->localKeywordFallback($entry));
        $riskLevel = $assessment['risk_level'];
        $sentimentScore = $assessment['risk_percent'];
        $trajectory = $assessment['trajectory'];
        $escalationProbability = $assessment['escalation_probability'];

        // --- 2. DATA PRIVACY SECURE ENCRYPTION ---
        try {
            $encrypter = \Config\Services::encrypter();
            $encryptedText = bin2hex($encrypter->encrypt($text));
        } catch (\Exception $e) {
            log_message('error', 'Encryption failed: ' . $e->getMessage());
            // Store safely without encryption so submission is not lost
            $encryptedText = base64_encode($text);
        }

        // --- 3. SAVE TO DATABASE ---
        $emotions = (string)($this->request->getPost('emotions') ?? '');
        $insertData = [
            'user_id'      => $userId,
            'raw_text'     => $encryptedText,
            'emotions'     => $emotions,
            'risk_percent' => $sentimentScore,
            'risk_level'   => $riskLevel,
            'status'       => 'Pending',
            'created_at'   => date('Y-m-d H:i:s')
        ];

        try {
            $fields = $db->getFieldNames('vent_logs');
            if (in_array('trajectory', $fields)) {
                $insertData['trajectory'] = $trajectory;
            }
            if (in_array('escalation_probability', $fields)) {
                $insertData['escalation_probability'] = $escalationProbability;
            }
        } catch (\Throwable $e) {
            // Proceed safely with standard columns
        }

        $db->table('vent_logs')->insert($insertData);
        $ventId = (int) $db->insertID();
        $this->logAudit('CREATE_VENT', 'vent', $ventId);
        // Keep provenance separate from model-performance evaluation and student-facing responses.
        $this->logAudit('VENT_ANALYSIS_' . strtoupper($assessment['source']), 'vent', $ventId);


        // --- 4. TRIGGER ALERTS IF CRITICAL OR HIGH ---
        if ($riskLevel === 'CRITICAL' || $riskLevel === 'HIGH') {
            try {
                $user = $db->table('users')
                    ->select('users.*, students.parent_phone, students.parent_email, students.section, students.grade_level')
                    ->join('students', 'students.user_id = users.id', 'left')
                    ->where('users.id', $userId)
                    ->get()->getRow();

                if ($user) {
                    $studentName = $user->name ?? 'A student';
                    $firstName = explode(' ', trim($studentName))[0];
                    $hasParentContact = !empty($user->parent_email) || !empty($user->parent_phone);
                    $parentNote = $hasParentContact ? "" : " (Note: No parent contact registered on file. Please summon student or contact class adviser.)";

                    if ($riskLevel === 'CRITICAL') {
                        // ALWAYS alert the counselor immediately for any CRITICAL vent
                        $this->triggerCounselorEmergencyAlert(
                            "URGENT: {$studentName} ({$user->section}) submitted a CRITICAL risk vent. Please review the dashboard immediately.{$parentNote}",
                            "CRITICAL Emergency Alert: {$studentName}",
                            "vent:{$ventId}:critical-counselor",
                            'StudentShield: CRITICAL alert. Review the counselor dashboard immediately.'
                        );

                        if (!empty($user->parent_phone) && !$this->isAlertOnCooldown($user->parent_phone)) {
                            $this->triggerSmsAlert($user->parent_phone, "vent:{$ventId}:parent");
                        }
                        if (!empty($user->parent_email) && !$this->isAlertOnCooldown($user->parent_email)) {
                            $this->triggerEmailAlert($user->parent_email, $firstName);
                        }
                    } elseif ($riskLevel === 'HIGH') {
                        // HIGH RISK: Alert counselor exclusively to handle professionally at school
                        // (We do NOT alert parents on HIGH to prevent alarm fatigue and helicopter parenting)
                        $this->triggerCounselorEmergencyAlert(
                            "HIGH PRIORITY: {$studentName} ({$user->section}) submitted a HIGH risk vent ({$sentimentScore}%). Please schedule a counseling session or wellness check-in.{$parentNote}",
                            "High Risk Counselor Notice: {$studentName}",
                            "vent:{$ventId}:high-counselor",
                            'StudentShield: HIGH-priority alert. Open the counselor dashboard to arrange a student check-in.'
                        );
                    }
                }
            } catch (\Throwable $alertEx) {
                log_message('error', 'Alert triggering failed: ' . $alertEx->getMessage());
            }
        }

        // --- 5. RETURN RESPONSE ---
        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Vent securely submitted.',
                'risk_level' => $riskLevel
            ]);
        }

        return redirect()->to('/student/vent')->with('message', 'Vent securely submitted.');
        } catch (\Throwable $e) {
            $errDetail = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            log_message('error', 'VentController::submit fatal: ' . $errDetail);
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(500)->setJSON([
                    'status' => 'error',
                    'message' => 'Server error: ' . $errDetail
                ]);
            }
            return redirect()->back()->with('error', 'Server error: ' . $errDetail);
        }
    }

    // --- 3. ALERT FATIGUE COOLDOWN (RATE LIMITING) ---
    protected function isAlertOnCooldown($recipient)
    {
        $file = WRITEPATH . 'logs/alerts.json';
        if (!file_exists($file))
            return false;

        $alerts = json_decode(file_get_contents($file), true);
        if (!is_array($alerts))
            return false;

        // 1-minute cooldown for TESTING purposes (originally 1 hour)
        $oneHourAgo = strtotime('-1 minute');

        foreach ($alerts as $alert) {
            if (isset($alert['accepted']) && !$alert['accepted']) continue;
            if ($alert['recipient'] === $recipient) {
                if (strtotime($alert['timestamp']) > $oneHourAgo) {
                    return true;
                }
            }
        }
        return false;
    }

    private function triggerSmsAlert($parentPhone, string $eventKey)
    {
        $message = 'StudentShield: Please check in with your child and contact the SVBSM Guidance Office for support.';
        $result = \Config\Services::smsGateway()->send((string) $parentPhone, $message, $eventKey);
        $this->logAlert('SMS (Gateway)', $parentPhone, $message, $result);
        return $result['accepted'];
    }

    private function triggerEmailAlert($parentEmail, $studentName = 'your child')
    {
        $subject = 'StudentShield: Support Alert for ' . ucfirst($studentName);

        $htmlMessage = "
        <div style=\"background-color: #f7fafc; padding: 40px 20px; font-family: 'Inter', Helvetica, Arial, sans-serif;\">
            <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;\">
                
                <!-- Header -->
                <div style=\"background: linear-gradient(135deg, #2F855A 0%, #38A169 100%); padding: 30px; text-align: center;\">
                    <img src=\"" . base_url('images/logo.png') . "\" alt=\"SVBSM Logo\" style=\"width: 95px; height: auto; margin-bottom: 12px; display: inline-block; background-color: transparent;\">
                    <h1 style=\"color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 0.5px;\">StudentShield Portal</h1>
                    <p style=\"color: #c6f6d5; margin: 8px 0 0 0; font-size: 15px;\">Wellness Support Update</p>
                </div>

                <!-- Body -->
                <div style=\"padding: 35px 30px; color: #4a5568;\">
                    <p style=\"font-size: 16px; margin-top: 0;\">Dear Parent/Guardian,</p>
                    
                    <div style=\"background-color: #ebf8f6; border-left: 4px solid #319795; padding: 15px 20px; margin: 25px 0; border-radius: 0 8px 8px 0;\">
                        <p style=\"margin: 0; font-size: 16px; line-height: 1.6; color: #234e52;\">
                            Our intelligent wellness portal recently reviewed a submission from <strong>$studentName</strong> and noted some heavy emotions being processed today.
                        </p>
                    </div>

                    <p style=\"font-size: 16px; line-height: 1.6;\">This is a completely normal part of student life. We are sending you this proactive courtesy update because we believe in partnering with parents to ensure every student feels supported.</p>
                    
                    <h3 style=\"color: #2d3748; font-size: 18px; margin: 30px 0 15px 0; border-bottom: 2px solid #edf2f7; padding-bottom: 8px;\"> How you can help tonight:</h3>
                    
                    <ul style=\"padding-left: 20px; margin: 0;\">
                        <li style=\"margin-bottom: 12px; font-size: 15px; line-height: 1.5;\"><strong>Offer a warm check-in:</strong> Just asking about their day creates a safe, non-judgmental space.</li>
                        <li style=\"margin-bottom: 12px; font-size: 15px; line-height: 1.5;\"><strong>Listen actively:</strong> Sometimes they just need to vent and be heard, rather than having a problem 'solved'.</li>
                        <li style=\"margin-bottom: 0px; font-size: 15px; line-height: 1.5;\"><strong>Remind them of support:</strong> Let them know you are always in their corner.</li>
                    </ul>

                    <div style=\"margin-top: 35px; text-align: center;\">
                        <a href=\"#\" style=\"display: inline-block; background-color: #2F855A; color: #ffffff; text-decoration: none; padding: 13px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; box-shadow: 0 2px 4px rgba(47, 133, 90, 0.3);\">Contact School Counselor</a>
                    </div>
                </div>

                <!-- Footer -->
                <div style=\"background-color: #f8fafc; padding: 25px 30px; text-align: center; border-top: 1px solid #e2e8f0;\">
                    <p style=\"margin: 0; color: #718096; font-size: 14px; line-height: 1.5;\">
                        <strong>The Counseling & Wellness Team</strong><br>
                        St. Vincent Blessed School of Manila
                    </p>
                    <p style=\"margin: 10px 0 0 0; color: #a0aec0; font-size: 12px;\">This is an automated message. Please do not reply directly to this email.</p>
                </div>
            </div>
        </div>
        ";

        $this->logAlert('Email', $parentEmail, "StudentShield Alert: Sent wellness check email regarding $studentName.");

        try {
            $email = \Config\Services::email();

            // Explicitly set SMTP config from .env to guarantee it's applied
            $smtpUser = env('email.SMTPUser', '');
            $smtpPass = env('email.SMTPPass', '');

            if (empty($smtpUser) || empty($smtpPass)) {
                log_message('error', 'Auto Email Failed: SMTP credentials not set in .env (email.SMTPUser, email.SMTPPass)');
                return;
            }

            $email->initialize([
                'protocol' => 'smtp',
                'SMTPHost' => env('email.SMTPHost', 'smtp.gmail.com'),
                'SMTPUser' => $smtpUser,
                'SMTPPass' => $smtpPass,
                'SMTPPort' => (int)env('email.SMTPPort', 587),
                'SMTPCrypto' => env('email.SMTPCrypto', 'tls'),
                'SMTPTimeout' => 10,
                'mailType' => 'html',
                'charset' => 'utf-8',
            ]);

            $email->setFrom($smtpUser, 'SVBSM Wellness Team');
            $email->setTo($parentEmail);
            $email->setSubject($subject);

            // Attach the logo inline and replace the img src with its generated Content-ID (CID)
            // This guarantees the image loads in Gmail even when running off offline localhost (Laragon)!
            $logoPath = FCPATH . 'images/logo.png';
            if (file_exists($logoPath)) {
                $email->attach($logoPath, 'inline');
                $cid = $email->setAttachmentCID($logoPath);
                $htmlMessage = str_replace(base_url('images/logo.png'), "cid:" . $cid, $htmlMessage);
            }

            $email->setMessage($htmlMessage);

            if (!$email->send(false)) {
                log_message('error', 'Auto Email Failed: ' . $email->printDebugger(['headers', 'subject']));
            }
            else {
                log_message('info', "Auto Email sent successfully to {$parentEmail} for student {$studentName}");
            }
        }
        catch (\Exception $e) {
            log_message('error', 'Auto Email Exception: ' . $e->getMessage());
        }
    }

    private function triggerCounselorEmergencyAlert($message, $subject, string $eventKey, string $smsMessage)
    {
        // 1. Text the default Counselor Phone
        $counselorPhone = env('COUNSELOR_PHONE', '');
        $result = \Config\Services::smsGateway()->send((string) $counselorPhone, $smsMessage, $eventKey);
        $this->logAlert('SMS (Counselor)', $counselorPhone, $smsMessage, $result);

        // 2. Email the Counselor Office
        try {
            $email = \Config\Services::email();
            $smtpUser = env('email.SMTPUser', '');
            $smtpPass = env('email.SMTPPass', '');
            $counselorEmail = env('COUNSELOR_EMAIL', '');
            if (empty($counselorEmail)) {
                $counselorEmail = $smtpUser;
            }

            if (!empty($smtpUser) && !empty($smtpPass)) {
                $email->initialize([
                    'protocol'    => 'smtp',
                    'SMTPHost'    => env('email.SMTPHost', 'smtp.gmail.com'),
                    'SMTPUser'    => $smtpUser,
                    'SMTPPass'    => $smtpPass,
                    'SMTPPort'    => (int)env('email.SMTPPort', 587),
                    'SMTPCrypto'  => env('email.SMTPCrypto', 'tls'),
                    'SMTPTimeout' => 10,
                    'mailType'    => 'html',
                    'charset'     => 'utf-8',
                    'newline'     => "\r\n",
                    'CRLF'        => "\r\n",
                    'wordWrap'    => true,
                ]);
                $email->setFrom($smtpUser, 'StudentShield Emergency Alert');
                $email->setTo($counselorEmail);
                $email->setSubject($subject);
                $email->setMessage(
                    "<div style='border: 2px solid #ef4444; padding: 24px; font-family: sans-serif; background: #fff5f5; border-radius: 12px; max-width: 600px; margin: 0 auto;'>" .
                    "<h2 style='color: #b91c1c; margin-top: 0;'>🚨 " . esc($subject, 'html') . "</h2>" .
                    "<p style='color: #1e293b; font-size: 16px; line-height: 1.6;'><strong>" . esc($message, 'html') . "</strong></p>" .
                    "<hr style='border: none; border-top: 1px solid #fecaca; margin: 20px 0;' />" .
                    "<p style='color: #64748b; font-size: 14px;'>Please log into the <a href='" . base_url('admin/login') . "' style='color: #166534; font-weight: bold;'>StudentShield Counselor Dashboard</a> immediately to review the student's encrypted entry and initiate the safety protocol.</p>" .
                    "</div>"
                );
                if (!$email->send(false)) {
                    log_message('error', 'Counselor Emergency Alert Email Failed: ' . $email->printDebugger(['headers', 'subject']));
                } else {
                    log_message('info', "Counselor Emergency Alert Email sent successfully to {$counselorEmail}");
                }
            }
        }
        catch (\Throwable $e) {
            log_message('error', 'Counselor Emergency Alert Email Exception: ' . $e->getMessage());
        }
    }

    protected function logAlert($type, $recipient, $message, array $smsStatus = [])
    {
        $file = WRITEPATH . 'logs/alerts.json';
        $alerts = [];
        if (file_exists($file) && filesize($file) > 0) {
            $decoded = json_decode(file_get_contents($file), true);
            if (is_array($decoded)) {
                $alerts = $decoded;
            }
        }

        // Add new alert at the top
        array_unshift($alerts, array_merge([
            'type' => $type,
            'recipient' => $recipient,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], $smsStatus));

        file_put_contents($file, json_encode($alerts, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Local keyword-based risk classification fallback.
     * Used when the ML microservice is offline/unreachable.
     * Mirrors the ML service's keyword categories for consistency.
     */
    private function localKeywordFallback(string $text): array
    {
        $threat = ViolentThreats::detect($text);
        if ($threat !== null) {
            return $threat;
        }
        if (ExplicitCrisisGuard::hasTagalogSelfHarm($text)) {
            return ['risk_level' => 'CRITICAL', 'risk_percent' => 99];
        }

        $lower = mb_strtolower($text);

        // Gen-Z sarcasm check — before keyword scanning
        $sarcasticContext = false;
        $jokeMarkers = ['haha', 'lol', 'lmao', 'char', 'charot', 'jk', 'joke lang', '😂', '💀', '🤣', 'eme', 'eme lang', 'dejk', 'charizz', 'chz', 'de joke', 'hehe', 'lmfao', 'biruan lang', 'nagbibiro', 'charot lang', 'char lang', 'joke lang naman', 'halfmeant', 'half meant', 'deadz', 'dedz', 'deds'];
        $academicContext = ['exam', 'test', 'quiz', 'math', 'homework', 'assignment', 'school', 'class', 'project', 'thesis', 'defense', 'recit', 'plate', 'subject', 'module', 'prof', 'teacher', 'guro', 'output', 'board exam'];

        foreach ($jokeMarkers as $jm) {
            if (mb_strpos($lower, $jm) !== false) {
                $sarcasticContext = true;
                break;
            }
        }
        // "kms after math" / "i wanna die this exam" = sarcasm
        if (!$sarcasticContext) {
            foreach ($academicContext as $ac) {
                if (mb_strpos($lower, $ac) !== false) {
                    foreach (['kms', 'kill me', 'end me', 'wanna die', 'want to die', 'magpakamatay', 'mamatay na ko'] as $casual) {
                        if (mb_strpos($lower, $casual) !== false) {
                            $sarcasticContext = true;
                            break 2;
                        }
                    }
                }
            }
        }
        // Trivial trigger neutralizer (traffic, boredom, food, weather)
        if (!$sarcasticContext) {
            $trivialContext = ['traffic', 'bored', 'boring', 'food', 'hungry', 'init', 'mainit', 'hot'];
            foreach ($trivialContext as $tc) {
                if (mb_strpos($lower, $tc) !== false) {
                    foreach (['kms', 'kill me', 'wanna die', 'want to die', 'mamatay', 'magpakamatay'] as $casual) {
                        if (mb_strpos($lower, $casual) !== false) {
                            $sarcasticContext = true;
                            break 2;
                        }
                    }
                }
            }
        }

        // CRITICAL EXTREME — Explicit Suicide Intent & Farewell Cues (NEVER DOWNGRADED BY SARCASM)
        $critical_extreme = [
            'suicide', 'kill myself', 'cut myself', 'magpakamatay', 'laslas',
            'tatalon', 'bibigti', 'patayin sarili ko', 'magbibigti',
            'slit my wrist', 'self-harm', 'self harm', 'bigti', 'bitay',
            'i wanna end this shit', 'i wanna end this na', 'sana kunin na ko ni lord', 'tapusin ko na buhay ko', 'sana matulog at di na magising',
            'tatapusin ko na', 'bibitay', 'magbigti', 'lulunukin ko na lang',
            'tumalon', 'lulundag', 'stab myself', 'overdose',
            'unalive', 'kms', 'kayat kon matayen',
            // Farewell cues / Suicide notes (Extreme risk — ignores sarcasm)
            'nag-iwan ng sulat', 'nag-iwan na rin ako ng sulat', 'nag-iwan ako ng sulat',
            'sulat para kay mama', 'sulat para kay papa', 'huling sulat',
            'matutulog na lang ako nang mahaba', 'matulog nang mahaba',
            'salamat sa lahat', 'huling paalam', 'paalam sa inyong lahat',
            'di na siya maghirap sa akin', 'di na kayo mahihirapan sa akin',
            'wala na kong mararating', 'wala na akong mararating',
            // Bereavement-induced suicide pacts & following-in-death cues
            'sasabay na ako', 'sasabay na ko', 'sasabay na aq',
            'susunod na ako', 'susunod na ko', 'susunod na aq',
            'sasama na ako sa kanya', 'sasama na ko sa kanya',
            'gusto ko nang sumunod', 'gusto ko nang sumama sa kanya',
            'lost my whole motivation to live', 'lost my motivation to live',
        ];

        // CRITICAL PASSIVE — Passive Ideation (Can be downgraded to MEDIUM if joking)
        $critical_passive = [
            'end it all', 'no reason to live', 'ayoko na mabuhay',
            'gusto ko na mawala', 'gusto ko nang mamatay', 'gusto nako mamatay',
            'gusto kong mamatay', 'sana mamatay na ako', 'mamatay na lang ako',
            'unalive myself', 'better off dead',
            'sana di na lang ako pinanganak', 'wala nang pag-asa',
            'i wanna die', 'i want to die', 'end my life', 'hang myself',
            'wish i was never born', 'wish i could disappear',
            'done with life', 'tired of living', 'gusto ko maglaho na lang', 'wala nang tutulong sakin',
            'ayoko na sa mundong ito', 'di na worth it mabuhay',
            'gusto ko na lang mawala ng tuluyan', 'para saan pa ako',
            'bakit pa ako nabuhay', 'bakit pa ako narito',
            'kung wala na ako mas maayos pa', 'may magbabago ba kung wala ako',
            'sana kunin na ako', 'konti na lang susuko na ko',
            'nasa tipping point na ko', 'hirap na hirap na ko mabuhay',
            'di na ko lalaban pa', 'tapusin ko na',
            'gilaay na jud ko sa tanan', 'gusto na ko mahunong',
            'narigat unay ti riknak', 'mabannogakon ti panagbiag',
        ];

        // HIGH — Emotional Distress & Passive Disappearance Ideation
        $high_emotional = [
            // Passive disappearance ideation (C-SSRS Level 2)
            'mawala sa lahat ng buhay',
            'makakapansin ba kung',
            'napapaisip ako kung',
            'bigla nalang akong mawala',
            'bigla lang akong mawala',
            'wonder if anyone would notice',
            'would anyone notice if',
            'anyone would notice if',
            'nobody would notice',
            'no one would notice',
            'wala naman maghahanap sakin',
            'wala namang maghahanap sakin',
            'di naman nila mapapansin',
            'wala naman nakakapansin',
            'wla rn nmn makakapansin',
            'parang extra lang ako sa lahat',
            'disappeared', 'disappered',
            'pagod na ako', 'depressed', 'depression', 'di ko na kaya', 'ayoko na',
            'sobrang lungkot', 'sobrang bigat', 'hirap na hirap', 'sawang sawa na',
            'walang silbi', 'wala akong kwenta', 'pabigat ako', 'suko na ko',
            'di na ko lalaban', 'deds na ko', 'game over na', 'namamatay', 'durug na durog', 'wasak na wasak', 'sukong suko na', 'parang sasabog utak ko', "can't take this anymore",
            'tinatamad mabuhay', 'manhid na ko', 'blangko na lang', 'wala na akong gana',
            'nawalan ng gana sa lahat', 'parang robot na lang', 'autopilot na lang',
            'wala na kong pake', 'wala na akong maramdaman',
            'ang hirap magpanggap na okay', 'nagmumukmok', 'di makatulog',
            'paulit-ulit iniisip', 'wala na akong silbi', 'extra lang ako',
            'feel ko ang useless ko', 'parang wala akong silbi',
            'pabigat lang naman ako', 'walang nagmamahal sakin',
            'di naman mapapansin kung mawala ako',
            'gilaay na', 'way kwenta', 'wala koy pulos', 'kapoy na mabuhi',
            'awan pag-asa', 'awan serserbi', 'narigat unay',
            // v7.2: Subtle emotional distress
            'so sad', 'im sad', 'i am sad', 'i\'m sad', 'feeling sad', 'really sad',
            'it hurts', 'hurts so much', 'i\'m hurting', 'im hurting',
            'crying', 'been crying', 'can\'t stop crying',
            'scared', 'i\'m scared', 'im scared',
            'lonely', 'feel lonely', 'feeling lonely', 'so alone', 'all alone',
            'heartbroken', 'broken hearted', 'miserable',
            'anxious', 'overwhelmed', 'drained', 'exhausted', 'exhausting', 'mentally',
            'falling apart', 'breaking apart', 'in pain',
            'everything feels heavy', 'so much energy', 'don\'t even recognize myself',
            'constantly overthinking', 'constantly doubting', 'not enough', 'tired',
            'worse than just ending it', 'how much of myself i have left', 'fading',
            'sobrang sad', 'malungkot', 'lungkot', 'naiyak', 'umiyak',
            'nasasaktan', 'masakit', 'sakit ng dibdib',
            // v7.4 & v7.5: Masked Depression, Smiling Depression, Severe Burnout & Anhedonia
            'ang bigat bigat',                    // overwhelming emotional weight
            'parang gusto ko lang matulog',        // burnout / escape
            'gusto ko lang matulog at hindi na magising',
            'sana matapos na',                     // hopeless fatigue
            'gusto ko na lang matapos yung araw',  // emotional exhaustion
            'matapos yung araw nang walang',
            'gaano katagal pa ba',                 // how much longer
            'di ko na alam kung kailan matatapos',
            'hindi ko alam kung kailan ako naging ganito',
            'wala na kong lakas',                  // no more strength
            'wala na akong lakas',
            'nawalan na ko ng lakas',
            'di na kaya ng katawan ko',
            'nakakapagod din pala',                // quiet realization of exhaustion
            'gaano pala ako kapagod',
            'pagod na pagod na ko sa lahat',
            'sobrang pagod ko na',
            'hindi ko na kaya',
            'ndi ko na kaya',
            'nahihirapan ako',
            'nahihirapan na ako',
            'parang walang katapusan',             // feels endless
            'kelan ba matatapos',
            'bakit ganito lagi',
            'di ko na gets ang buhay',
            'inuubos na ko nito',
            'kumakapit na lang',                   // just hanging on
            'kapag mag-isa ako naiiyak na lang',
            'pag mag-isa na ko iba na ang nararamdaman',
            'iyak nalang lagi',
            'di ko mapigilan umiyak',
            'parang hindi na ko buo',              // don't feel whole anymore
            'di na ko buo',
            'pagod na akong magpanggap',
            'tired of pretending to be okay',
            'sinusubukan ko pang maging okay',
            'lagi mong sinasabi na okay lang',     // masked depression
            'kung okay ka pa ba talaga',
            'dalhin mag-isa',                      // internalized burden
            'sanay na lang akong dalhin mag-isa',
            'hindi ko kayang sabihin directly',
            'tahimik na lang',
            'makaramdam ulit ng kahit ano',        // anhedonia / emotional numbness
            'makaramdam ulit',
            'makaramdam ng kahit ano',
            'wala na kong maramdaman',
            'wala na akong maramdaman',
            'blanko lang lahat',
            'hindi ko na makita yung sarili ko',
            'di ko na makita sarili ko',
            'pagod na pagod na yung utak ko',
            'pagod na utak ko',
        ];

        // STRICT HIGH — Physical Violence (Never downgraded by sarcasm)
        $high_physical = [
            'punched', 'kicked', 'choked', 'slapped', 'beat me up', 'pushed', 'cornered',
            'hit me', 'weapon', 'bleeding', 'bruises', 'sinapak', 'binugbog', 'sinampal',
            'binatukan', 'tinulak', 'sinabunutan', 'sinipa', 'sinakal', 'hinawakan',
            'sinuntok', 'sinaktan', 'sinasaktan', 'pinagtulungan', 'pinagmalupitan',
            'death threat', 'harassed', 'extort', 'blackmail', 'kidnap', 'strangled',
            'ginulpi', 'pananakit', 'inabuso', 'pinagbantaan', 'tatakutin',
            'dumugo', 'sugat', 'nananakit', 'tinadyakan', 'sabunot', 'dinukot', 'kinuyog', 'pinagkaisahan', 'kinuwelyuhan', 'sinungalngal',
            'nambugbog', 'pinagtripan', 'tinutukan ng itak',
            'gikulata', 'gilabayan', 'binagsol', 'ginahasa', 'gahasa',
            'pinagsamantalahan', 'pinilit', 'hinipo',
        ];

        // MEDIUM — Verbal Abuse, Cyberbullying, Masked Depression, Passive Invisibility
        $medium = [
            'ugly', 'loser', 'slut', 'whore', 'dumb', 'stupid', 'hate you',
            'bobo', 'tanga', 'pangit', 'mataba', 'pokpok', 'squatter', 'gago',
            'tarantado', 'putangina', 'inutil', 'idyota', 'manyak', 'malandi',
            'toxic', 'humiliated', 'shamed', 'bullied', 'bully',
            'pinahiya', 'tinawanan', 'siniraan', 'pinagchismisan', 'chismis',
            'cyberbully', 'doxx', 'exposed', 'na-bash',
            'walang kwenta', 'insecure', 'buang', 'salot', 'bida-bida',
            'pinagtatawanan', 'pabida', 'epal', 'plastik', 'sipsip', 'kupal', 'papansin', 'jejemon', 'feelingera', 'walang ambag',
            'binully', 'na-bully', 'na-cancel', 'na-ghost',
            'pinagsasabihan', 'pinagtawanan',
            'di naman halata', 'normal naman ako kausap pero', 'pag mag-isa na iba na',
            'okay sa labas pero sa loob hindi', 'nakangiti pero malungkot',
            'tinatago ko', 'pinipigilan ko iyak', 'nagpapanggap na okay',
            // Passive invisibility / social isolation (non-ideation — kept at MEDIUM)
            // NOTE: Disappearance ideation phrases are in $critical_passive above.
            'disappeared from everyone', 'slowly disappeared', 'never really there',
            'feel like i was never', 'talagang kasama', 'wala ka pa rin',
            'ang sakit no',
            // v7.4 & v7.5: Masked/Smiling depression & isolation language
            'ine-exclude',                         // excluded (Taglish)
            'tinatalikuran',                       // ignored / turned away from
            'ina-avoid', 'in-avoid',
            'pinag-iiwasan',
            'wala akong malapitan',                // nobody to turn to
            'wala akong mapag-usapan',
            'wala akong matakbuhan',
            'parang invisible lang ako',
            'parang ghost ako sa kanila',
            'kahit nandito ako parang wala',
            'nobody checks on me',
            'no one checks on me',
            'nobody reaches out',
            'sana may makapansin',                 // I wish someone would notice
            'sana may makapansin sa akin',
            'kahit gusto kong mapansin',
            'nakikisabay sa jokes',                // smiling depression indicators
            'tumatawa pa nga minsan',
            'parang biglang tahimik',
            'pinahihiya',                          // being humiliated
            'pinagtatawanan ng lahat',
            'inaayawan',                           // being avoided / rejected
            'wala silang ginagawa para tulungan ako',
        ];

        // 1. Process STRICT CRITICAL (Ignores Sarcasm)
        foreach ($critical_extreme as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                return ['risk_level' => 'CRITICAL', 'risk_percent' => 99];
            }
        }

        // 2. Process PASSIVE CRITICAL (Susceptible to Sarcasm)
        foreach ($critical_passive as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                if ($sarcasticContext) {
                    return ['risk_level' => 'MEDIUM', 'risk_percent' => 60];
                }
                return ['risk_level' => 'CRITICAL', 'risk_percent' => 95];
            }
        }
        foreach ($high_physical as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                // Physical abuse is NEVER a joke. Ignore sarcasm completely.
                return ['risk_level' => 'HIGH', 'risk_percent' => 85];
            }
        }
        foreach ($high_emotional as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                if ($sarcasticContext) {
                    return ['risk_level' => 'MEDIUM', 'risk_percent' => 45];
                }
                return ['risk_level' => 'HIGH', 'risk_percent' => 75];
            }
        }
        foreach ($medium as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                if ($sarcasticContext) {
                    return ['risk_level' => 'LOW', 'risk_percent' => 20];
                }
                return ['risk_level' => 'MEDIUM', 'risk_percent' => 50];
            }
        }

        // RELATIONSHIP DISTRESS — Breakup/rejection/heartbreak (NEW v7.1)
        // Processed after other categories because breakup alone = MEDIUM,
        // but it compounds with emotional signals (handled by Azure ML).
        $relationship = [
            'break up', 'breakup', 'broke up', 'heartbreak', 'heartbroken',
            'iniwan', 'hiniwalayan', 'nakipaghiwalay', 'nag-break',
            'niloko', 'chineat', 'nag-cheat', 'cheated',
            'pinagpalit', 'ginamit lang', 'pinaglaruan', 'laro lang',
            'dumped', 'rejected', 'got dumped', 'got rejected',
            'wasak puso', 'sakit ng puso', 'masakit sa puso',
            'di na niya ko mahal', 'di na ako mahal', 'hindi na mahal',
            'may iba na siya', 'may bago na siya',
            'nobody loves me', 'no one loves me', 'unloved',
            'di ako sapat', 'hindi ako sapat', 'kulang pa ba ako',
            'di na makapag move on', 'cant move on',
            'bakit di ako sapat', 'ex ko', 'my ex',
            'ghinost', 'ghosted', 'na-ghost',
            // v7.2: Subtle relationship distress — missing/longing/conflict
            'i miss her', 'i miss him', 'miss her so much', 'miss him so much',
            'love her so much', 'love him so much', 'i love her', 'i love him',
            'wanna see her', 'wanna see him', 'want to see her', 'want to see him',
            'open up to her', 'open up to him',
            'argument', 'might get mad', 'she might get mad', 'he might get mad',
            'cold to me', 'less sweet', 'kinda cold',
            'scared to open up', 'scared to talk',
            'mahal ko siya', 'miss ko siya', 'namimiss ko siya',
            'gusto ko siya makita', 'gusto ko siyang makita',
            'natatakot ako baka mag-away', 'natatakot baka magalit',
            'malamig na siya', 'parang malamig na siya',
        ];
        foreach ($relationship as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                if ($sarcasticContext) {
                    return ['risk_level' => 'LOW', 'risk_percent' => 20];
                }
                return ['risk_level' => 'MEDIUM', 'risk_percent' => 50];
            }
        }

        return ['risk_level' => 'LOW', 'risk_percent' => 10];
    }
}
