<?php

namespace App\Controllers;


class AuthController extends BaseController
{
    public function studentLoginView()
    {
        if (session()->get('isLoggedIn')) {
            $role = (int) session()->get('role');
            return redirect()->to(\App\Libraries\RoleAccess::home($role));
        }
        return view('login');
    }

    public function adminLoginView()
    {
        if (session()->get('isLoggedIn')) {
            $role = (int) session()->get('role');
            return redirect()->to(\App\Libraries\RoleAccess::home($role));
        }

        return view('login');
    }

    /**
     * Shared student ID/LRN and staff sign-in; staff verification is preserved.
     */
    public function authenticateStudent()
    {
        if (strtolower($this->request->getMethod()) === 'get') {
            return redirect()->to('/login');
        }

        $db = \Config\Database::connect();
        $name = trim((string) ($this->request->getPost('identifier') ?? $this->request->getPost('name')));
        $password = (string) $this->request->getPost('password');

        if (empty($name) || empty($password)) {
            return redirect()->back()->with('error', 'Please fill in all fields.');
        }

        $matches = $db->table('users')->select('users.*')
            ->join('students', 'students.user_id = users.id')
            ->where('users.role', 0)->groupStart()->where('students.student_id', $name)
            ->orWhere('students.lrn', $name)->groupEnd()->limit(2)->get()->getResultArray();
        // Preserve existing name-based student sign-in. Explicit ID/LRN matches take priority.
        if (!$matches) {
            $matches = $db->table('users')->where('role', 0)->where('name', $name)
                ->limit(2)->get()->getResultArray();
        }
        if (!$matches) return $this->authenticateAdmin();
        if (count($matches) !== 1) return redirect()->to('/login')->with('error', 'Invalid credentials provided.');
        $user = $matches[0];

        $pwHash = $user['password_hash'] ?? $user['passcode'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012';
        if ($user && password_verify($password, $pwHash)) {
            
            // Approval-Based Registration Check
            if (!\App\Libraries\RoleAccess::enabled($user)) {
                return redirect()->back()->with('error', 'Your account is not approved or is disabled. Please contact the guidance office.');
            }

            // Remember Me Feature
            if ($this->request->getPost('remember') === 'on') {
                $token = bin2hex(random_bytes(32));
                $db->table('users')->where('id', $user['id'])->update([
                    'remember_token' => password_hash($token, PASSWORD_BCRYPT)
                ]);
                $cookieValue = base64_encode($user['id'] . ':' . $token);
                setcookie('student_remember', $cookieValue, [
                    'expires' => time() + (86400 * 30),
                    'path' => '/',
                    'secure' => $this->shouldUseSecureCookie(),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }

            session()->regenerate();
            session()->set([
                'user_id'    => $user['id'],
                'name'       => $user['name'],
                'role'       => (int) $user['role'],
                'role_name'  => 'student',
                'auth_version' => (int) ($user['auth_version'] ?? 1),
                        'isLoggedIn' => true,
                'last_activity' => time(),
            ]);
            $this->logAudit('USER_LOGIN', 'system', $user['id']);
            return redirect()->to('/student/dashboard');
        }
        return redirect()->back()->with('error', 'Invalid credentials provided.');
    }

    /**
     * Counselors, Super Admins and Principals use the staff password and OTP workflow.
     */
    public function authenticateAdmin()
    {
        if (strtolower($this->request->getMethod()) === 'get') {
            return redirect()->to('/admin/login');
        }

        $db = \Config\Database::connect();
        $name = trim((string) ($this->request->getPost('identifier') ?? $this->request->getPost('name')));
        $password = (string) $this->request->getPost('password');

        if (empty($name) || empty($password)) {
            return redirect()->back()->with('error', 'Please fill in all fields.');
        }

        // Only staff roles may authenticate through this portal.
        $user = $db->table('users')
            ->select('users.*, CASE WHEN users.role = 1 THEN counselors.counselor_email ELSE admins.email END AS parent_email, CASE WHEN users.role = 1 THEN counselors.counselor_phone ELSE admins.phone END AS parent_phone', false)
            ->join('counselors', 'counselors.user_id = users.id', 'left')
            ->join('admins', 'admins.user_id = users.id', 'left')
            ->whereIn('users.role', [1, 2, 3])
            ->groupStart()->where('users.name', $name)->orWhere('counselors.counselor_email', $name)->orWhere('admins.email', $name)->groupEnd()
            ->limit(2)->get()->getResultArray();
        $user = count($user) === 1 ? $user[0] : null;

        // Always verify password to ensure constant-time response (timing attack mitigation)
        // Account existence is evaluated after password_verify to avoid timing differences



        // Authenticate with bcrypt only — plaintext fallback removed for security
        $pwHash = $user['password_hash'] ?? $user['passcode'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012';
        $passwordValid = password_verify($password, $pwHash);

        if ($user && $passwordValid && \App\Libraries\RoleAccess::enabled($user)) {
            $roleNames = [1 => 'counselor', 2 => 'super_admin', 3 => 'principal'];

            // Temporary production pause: OTP resumes automatically after 24 hours.
            // An explicit environment setting can end or extend the pause.
            $otpSetting = env('staffLogin.otpEnabled', null);
            $otpPaused = $otpSetting === false
                || ($otpSetting === null && time() < strtotime('2026-10-22T15:52:00+00:00'));
            if ($otpPaused) {
                $this->clearOtpSession();
                session()->regenerate();
                session()->set([
                    'user_id'       => $user['id'],
                    'name'          => $user['name'],
                    'role'          => (int) $user['role'],
                    'role_name'     => $roleNames[(int) $user['role']] ?? 'counselor',
                    'auth_version'  => (int) ($user['auth_version'] ?? 1),
                    'isLoggedIn'    => true,
                    'last_activity' => time(),
                ]);
                $this->logAudit('USER_LOGIN', 'system', $user['id']);
                return redirect()->to(\App\Libraries\RoleAccess::home((int) $user['role']));
            }

            // ── LAYER 5: TRUSTED DEVICE BYPASS ──
            if (isset($_COOKIE['admin_trusted_device'])) {
                $cookieData = base64_decode($_COOKIE['admin_trusted_device']);
                if (strpos($cookieData, ':') !== false) {
                    list($cookieUserId, $cookieToken) = explode(':', $cookieData, 2);
                    if ((int)$cookieUserId === (int)$user['id'] && password_verify($cookieToken, $user['remember_token'] ?? '')) {
                        // Trusted device recognized — direct dashboard access
                        session()->regenerate();
                        session()->set([
                            'user_id'    => $user['id'],
                            'name'       => $user['name'],
                            'role'       => (int) $user['role'],
                            'role_name'  => $roleNames[(int)$user['role']] ?? 'counselor',
                            'auth_version' => (int) ($user['auth_version'] ?? 1),
                        'isLoggedIn' => true,
                            'last_activity' => time(),
                        ]);
                        $this->logAudit('USER_LOGIN', 'system', $user['id']);
                        return redirect()->to(\App\Libraries\RoleAccess::home((int) session()->get('role')));
                    }
                }
            }

            // ── LAYER 6: TWO-FACTOR AUTHENTICATION (OTP) ──
            $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            
            // Suspend actual login, save temporary session data
            session()->set([
                'temp_user_id'   => $user['id'],
                'temp_name'      => $user['name'],
                'temp_role'      => (int) $user['role'],
                'temp_auth_version' => (int) ($user['auth_version'] ?? 1),
                'temp_role_name' => $roleNames[(int)$user['role']] ?? 'counselor',
                'otp'            => $otp,
                'otp_expires'    => time() + 300, // 5 minutes strict expiration
                'otp_attempts'     => 0,
                'otp_resend_count' => 0,
                'otp_resend_time'  => time(),
            ]);

            // Determine recipient email:
            // 1. Specific account email
            // 2. Fallback to COUNSELOR_EMAIL in .env
            // 3. Fallback to SMTP User
            $recipientEmail = !empty($user['parent_email']) ? trim($user['parent_email']) : '';
            if (empty($recipientEmail)) {
                $recipientEmail = env('COUNSELOR_EMAIL', env('email.SMTPUser', ''));
            }

            // Mask email for display on OTP page (e.g. vo***s@gmail.com)
            $maskedEmail = $recipientEmail;
            if (strpos($recipientEmail, '@') !== false) {
                list($userPart, $domainPart) = explode('@', $recipientEmail, 2);
                if (strlen($userPart) > 3) {
                    $maskedUser = substr($userPart, 0, 2) . str_repeat('*', strlen($userPart) - 3) . substr($userPart, -1);
                } else {
                    $maskedUser = substr($userPart, 0, 1) . '***';
                }
                $maskedEmail = $maskedUser . '@' . $domainPart;
            }
            session()->set('otp_masked_email', $maskedEmail);

            $this->sendOtpEmail($user, $recipientEmail, $otp);

            // Redirect to OTP verification page
            return redirect()->to('/admin/otp');
        }

        return redirect()->back()->with('error', 'Invalid credentials provided.');
    }

    public function otpView()
    {
        // Guard: Must have passed the first step of authentication
        if (!session()->has('otp')) {
            return redirect()->to('/admin/login');
        }
        return view('admin_otp');
    }

    public function verifyOtp()
    {
        if (!session()->has('otp') || !session()->has('temp_user_id')) {
            return redirect()->to('/admin/login');
        }

        $inputOtp  = trim((string)$this->request->getPost('otp'));
        $storedOtp = session()->get('otp');
        $expiresAt = (int) session()->get('otp_expires');

        // Codes must expire (5 minutes strict limit)
        if (time() > $expiresAt) {
            $this->clearOtpSession();
            return redirect()->to('/admin/login')->with('error', 'Security code has expired. Please log in again.');
        }

        // Limit incorrect codes: max 5 attempts before session invalidation
        if ($inputOtp !== $storedOtp) {
            $otpAttempts = (int) (session()->get('otp_attempts') ?? 0) + 1;
            session()->set('otp_attempts', $otpAttempts);

            if ($otpAttempts >= 5) {
                $tempUserId = session()->get('temp_user_id');
                if ($tempUserId) {
                    $this->logAudit('OTP_LOCKOUT', 'system', $tempUserId);
                }
                $this->clearOtpSession();
                return redirect()->to('/admin/login')->with('error', 'Too many failed verification attempts. Please log in again.');
            }

            $remaining = 5 - $otpAttempts;
            return redirect()->back()->with('error', "Invalid verification code. {$remaining} attempt(s) remaining.");
        }

        $verifiedAccount = $this->otpAccount((int) session()->get('temp_user_id'));
        if (!$verifiedAccount || !\App\Libraries\RoleAccess::enabled($verifiedAccount)
            || (int) $verifiedAccount['role'] !== (int) session()->get('temp_role')
            || (int) ($verifiedAccount['auth_version'] ?? 1) !== (int) (session()->get('temp_auth_version') ?? 1)) {
            $this->clearOtpSession();
            return redirect()->to('/admin/login')->with('error', 'Account access changed. Please log in again.');
        }
        // Official Authentication Authorization!
        // Check Trust Device
        if ($this->request->getPost('trust_device') == '1') {
            $token = bin2hex(random_bytes(32));
            $db = \Config\Database::connect();
            $db->table('users')->where('id', session()->get('temp_user_id'))->update([
                'remember_token' => password_hash($token, PASSWORD_BCRYPT)
            ]);
            $cookieValue = base64_encode(session()->get('temp_user_id') . ':' . $token);
            setcookie('admin_trusted_device', $cookieValue, [
                'expires' => time() + (86400 * 30),
                'path' => '/',
                'secure' => $this->shouldUseSecureCookie(),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }

        // Read temp values BEFORE regenerate() to prevent data loss
        $userId   = session()->get('temp_user_id');
        $name     = session()->get('temp_name');
        $role     = session()->get('temp_role');
        $roleName = session()->get('temp_role_name');

        // Codes work only once: immediately purge OTP session keys
        $this->clearOtpSession();

        session()->regenerate();
        session()->set([
            'user_id'       => $userId,
            'name'          => $name,
            'role'          => $role,
            'role_name'     => $roleName,
            'auth_version' => (int) ($verifiedAccount['auth_version'] ?? 1),
            'isLoggedIn'    => true,
            'last_activity' => time(),
        ]);

        $this->logAudit('USER_LOGIN', 'system', $userId);

        return redirect()->to(\App\Libraries\RoleAccess::home((int) session()->get('role')));
    }

    /**
     * Resend verification code with strict cooldown (60s) and attempt limit (max 3).
     */
    public function resendOtp()
    {
        if (!session()->has('otp') || !session()->has('temp_user_id')) {
            return redirect()->to('/admin/login');
        }

        // Enforce 60-second cooldown between resends
        $lastResend = (int) (session()->get('otp_resend_time') ?? 0);
        if (time() - $lastResend < 60) {
            $wait = 60 - (time() - $lastResend);
            return redirect()->back()->with('error', "Please wait {$wait} seconds before requesting a new code.");
        }

        // Enforce max 3 resend attempts per session
        $resendCount = (int) (session()->get('otp_resend_count') ?? 0);
        if ($resendCount >= 3) {
            $this->clearOtpSession();
            return redirect()->to('/admin/login')->with('error', 'Maximum resend limit reached. Please log in again.');
        }

        $userId = session()->get('temp_user_id');
        $db = \Config\Database::connect();
        $user = $db->table('users')
            ->select('users.*, CASE WHEN users.role = 1 THEN counselors.counselor_email ELSE admins.email END AS parent_email', false)
            ->join('counselors', 'counselors.user_id = users.id', 'left')
            ->join('admins', 'admins.user_id = users.id', 'left')
            ->where('users.id', $userId)
            ->get()->getRowArray();

        if (!$user) {
            $this->clearOtpSession();
            return redirect()->to('/admin/login')->with('error', 'Session invalid. Please log in again.');
        }

        // Invalidate prior code and issue brand new 6-digit OTP
        $newOtp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        session()->set([
            'otp'              => $newOtp,
            'otp_expires'      => time() + 300, // 5 minutes strict expiration
            'otp_attempts'     => 0,            // Reset incorrect attempts for the fresh code
            'otp_resend_time'  => time(),
            'otp_resend_count' => $resendCount + 1,
        ]);

        $recipientEmail = !empty($user['parent_email']) ? trim($user['parent_email']) : '';
        if (empty($recipientEmail)) {
            $recipientEmail = env('COUNSELOR_EMAIL', env('email.SMTPUser', ''));
        }

        $this->sendOtpEmail($user, $recipientEmail, $newOtp);

        return redirect()->to('/admin/otp')->with('message', 'A new 6-digit verification code has been sent to your email.');
    }

    /**
     * Session heartbeat ping for idle timeout reset when user clicks 'Stay Logged In'.
     */
    public function pingSession()
    {
        if (!session()->get('isLoggedIn')) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'expired']);
        }
        session()->set('last_activity', time());
        return $this->response->setJSON(['status' => 'active', 'timestamp' => time()]);
    }

    public function logout()
    {
        $role = session()->get('role');
        $userId = session()->get('user_id');
        
        if ($userId) {
            $this->logAudit('USER_LOGOUT', 'system', $userId);
            $db = \Config\Database::connect();
            if ((int) $role === 0) {
                $db->table('users')->where('id', $userId)->update(['remember_token' => null]);
            }
        }
        
        // Staff trust skips OTP after password verification; it does not keep a session signed in.
        setcookie('student_remember', '', time() - 3600, '/');

        
        // Explicitly expire session cookie on shared computers
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        $this->clearOtpSession();
        session()->destroy();
        
        if (in_array($role, [1, 2, 3], true)) {
            return redirect()->to('/admin/login');
        }
        return redirect()->to('/login');
    }

    /**
     * Clears all temporary two-factor authentication session variables.
     */
    protected function otpAccount(int $id): ?array
    {
        return \Config\Database::connect()->table('users')->where('id', $id)->get()->getRowArray();
    }

    private function clearOtpSession(): void
    {
        session()->remove([
            'otp', 'otp_expires', 'otp_attempts', 'temp_user_id',
            'temp_name', 'temp_role', 'temp_role_name', 'temp_auth_version',
            'otp_resend_count', 'otp_resend_time', 'otp_masked_email'
        ]);
    }

    /**
     * Reusable helper to dispatch OTP verification email via SMTP and log the attempt.
     */
    protected function sendOtpEmail(array $user, string $recipientEmail, string $otp): bool
    {
        $emailSent = false;
        try {
            $email = \Config\Services::email();
            $smtpUser = trim((string) env('email.SMTPUser', ''));
            $smtpPass = (string) env('email.SMTPPass', '');
            $smtpHost = env('email.SMTPHost', 'smtp.gmail.com');
            $smtpPort = (int) env('email.SMTPPort', 587);
            $smtpCrypto = env('email.SMTPCrypto', 'tls');

            $email->initialize([
                'protocol'     => 'smtp',
                'SMTPHost'     => $smtpHost,
                'SMTPUser'     => $smtpUser,
                'SMTPPass'     => $smtpPass,
                'SMTPPort'     => $smtpPort,
                'SMTPCrypto'   => $smtpCrypto,
                'SMTPTimeout'  => 10,
                'mailType'     => 'html',
                'charset'      => 'utf-8',
                'newline'      => "\r\n",
                'CRLF'         => "\r\n",
                'wordWrap'     => true,
            ]);

            $email->setFrom($smtpUser, 'StudentShield Security');
            $email->setTo($recipientEmail);
            $email->setSubject('StudentShield Security: Your 6-Digit Admin Verification Code');

            $userNameEsc = esc($user['name'] ?? 'Staff Member');
            $html = "
            <div style='background:#f4f7f5; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif;'>
                <div style='max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e2e8f0;'>
                    <div style='background: linear-gradient(135deg, #166534, #022c22); padding: 30px 20px; text-align: center;'>
                        <h1 style='color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: 0.5px;'>Student<span style='color: #F2C300;'>Shield</span></h1>
                        <p style='color: rgba(255,255,255,0.8); margin: 6px 0 0 0; font-size: 13px;'>Counselor & Admin Portal Verification</p>
                    </div>
                    <div style='padding: 35px 30px; text-align: center;'>
                        <h2 style='color: #1e293b; margin: 0 0 12px; font-size: 20px; font-weight: 600;'>Security Passcode</h2>
                        <p style='color: #64748b; margin: 0 0 24px; font-size: 14px; line-height: 1.5;'>
                            Hello <strong>{$userNameEsc}</strong>, use the one-time verification code below to authorize your session:
                        </p>
                        <div style='background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 18px 24px; margin-bottom: 24px; display: inline-block;'>
                            <span style='font-family: Courier, monospace; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #166534;'>{$otp}</span>
                        </div>
                        <p style='color: #ef4444; margin: 0 0 20px; font-size: 13px; font-weight: 500;'>
                            ⏱️ This verification code is valid for <strong>5 minutes</strong>.
                        </p>
                        <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;' />
                        <p style='color: #94a3b8; font-size: 12px; margin: 0;'>
                            If you did not attempt to sign in to StudentShield, please disregard this email and secure your account immediately.
                        </p>
                    </div>
                </div>
            </div>";

            $email->setMessage($html);
            if ($email->send(false)) {
                $emailSent = true;
                log_message('info', "OTP verification email successfully sent to {$recipientEmail}");
            } else {
                $debug = $email->printDebugger(['headers', 'subject']);
                log_message('error', "OTP Email sending failed: " . $debug);
            }
        } catch (\Throwable $e) {
            log_message('error', "OTP Email Exception: " . $e->getMessage());
        }

        try {
            $db = \Config\Database::connect();
            if ($db->tableExists('email_logs')) {
                $db->table('email_logs')->insert([
                    'student_id'      => $user['id'] ?? null,
                    'recipient_email' => $recipientEmail,
                    'alert_type'      => 'ADMIN_LOGIN_OTP',
                    'subject'         => 'Admin Verification Code',
                    'message_content' => "Admin login OTP delivery attempted for user " . ($user['name'] ?? 'Staff'),
                    'sent_status'     => $emailSent ? 'sent' : 'failed'
                ]);
            }
        } catch (\Throwable $e) {}

        return $emailSent;
    }

    public function studentRegisterView()
    {
        if (session()->get('isLoggedIn')) {
            return redirect()->to(\App\Libraries\RoleAccess::home((int) session()->get('role')));
        }
        helper(['form', 'url']);
        return view('register');
    }

    public function processStudentRegistration()
    {
        helper(['form', 'url']);
        $db = \Config\Database::connect();
        $name = trim((string) $this->request->getPost('name'));
        $lrn = trim((string) $this->request->getPost('lrn'));
        if (!preg_match('/\A[0-9]{12}\z/', $lrn)) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid 12-digit LRN.');
        }
        if ($db->table('students')->where('lrn', $lrn)->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'That LRN is already registered. Please contact the guidance office.');
        }
        $gradeLevel = trim((string) $this->request->getPost('grade_level'));
        $section = trim((string) $this->request->getPost('section'));
        $parentContact = trim((string) $this->request->getPost('parent_contact'));
        $password = (string) $this->request->getPost('password');
        $confirmPassword = (string) $this->request->getPost('password_confirm');

        // Auto-generate a unique system student ID: SS-YYYYMMDD-XXXX
        $studentId = 'SS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        // Detect if parentContact is email or phone
        $parentPhone = filter_var($parentContact, FILTER_VALIDATE_EMAIL) ? null : $parentContact;
        $parentEmail = filter_var($parentContact, FILTER_VALIDATE_EMAIL) ? $parentContact : null;

        $terms = $this->request->getPost('terms');

        if (empty($name) || empty($password)) {
            return redirect()->back()->withInput()->with('error', 'Name and Password are required.');
        }

        if (!$terms) {
            return redirect()->back()->withInput()->with('error', 'You must agree to the Terms and Conditions.');
        }

        if ($password !== $confirmPassword) {
            return redirect()->back()->withInput()->with('error', 'Passwords do not match.');
        }

        if (strlen($password) < 8) {
            return redirect()->back()->withInput()->with('error', 'Password must be at least 8 characters long.');
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return redirect()->back()->withInput()->with('error', 'Password must contain at least one uppercase letter.');
        }

        if (!preg_match('/[a-z]/', $password)) {
            return redirect()->back()->withInput()->with('error', 'Password must contain at least one lowercase letter.');
        }

        if (!preg_match('/[0-9]/', $password)) {
            return redirect()->back()->withInput()->with('error', 'Password must contain at least one number.');
        }

        if (!preg_match('/[\W_]/', $password)) {
            return redirect()->back()->withInput()->with('error', 'Password must contain at least one special character (e.g., @, #, $, etc.).');
        }

        $existingUser = $db->table('users')->where('name', $name)->get()->getRow();
        if ($existingUser) {
            return redirect()->back()->withInput()->with('error', 'That name is already registered.');
        }

        $db->transStart();
        // Insert into users table
        $db->table('users')->insert([
            'name' => $name,
            'role' => 0, // Student
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'is_active' => 0, // Pending approval
            'registration_status' => 'pending'
        ]);

        $userId = $db->insertID();

        // Insert into students table
        $db->table('students')->insert([
            'user_id' => $userId,
            'student_id' => $studentId,
            'lrn' => $lrn,
            'grade_level' => $gradeLevel,
            'section' => $section,
            'parent_phone' => $parentPhone,
            'parent_email' => $parentEmail,
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->back()->withInput()->with('error', 'Registration failed. Please try again.');
        }

        \App\Libraries\AuditTrail::record('USER_REGISTERED', 'user', (int) $userId, (int) $userId);

        return redirect()->to('/login')->with('success', 'Registration successful! Your account is pending approval by the guidance office.');
    }

    private function shouldUseSecureCookie(): bool
    {
        return $this->request->isSecure()
            || strtolower((string) $this->request->getServer('HTTP_X_FORWARDED_PROTO')) === 'https';
    }
}
