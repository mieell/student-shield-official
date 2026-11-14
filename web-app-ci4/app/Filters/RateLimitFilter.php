<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

/**
 * Rate Limit Filter — prevents brute-force login and OTP guessing attacks.
 * Allows max 5 login attempts per IP within 5 minutes.
 * Allows max 5 OTP verification attempts per IP within 5 minutes.
 * Allows max 3 OTP resend attempts per IP within 5 minutes.
 * Uses file-based tracking (no extra DB table needed, compatible with shared PHP hosting).
 */
class RateLimitFilter implements FilterInterface
{
    private const MAX_LOGIN_ATTEMPTS     = 5;
    private const MAX_OTP_ATTEMPTS       = 5;
    private const MAX_RESEND_ATTEMPTS    = 3;
    private const MAX_CONSENT_ATTEMPTS   = 10;
    private const MAX_REGISTER_ATTEMPTS  = 10;
    private const MAX_DIRECTORY_ATTEMPTS = 60;
    private const LOCKOUT_SECONDS        = 300; // 5 minutes
    private const STORAGE_DIR            = WRITEPATH . 'rate_limits/';

    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtolower($request->getMethod());
        $uriPath = trim($request->getUri()->getPath(), '/');
        $isDirectoryLookup = str_starts_with($uriPath, 'report/bullying/directory');

        // Rate-limit POST submissions and authenticated student directory searches
        if ($method !== 'post' && !$isDirectoryLookup) {
            return;
        }

        try {
            $ip = $request->getIPAddress();

            // Determine rate limiting bucket based on target action
            if (str_contains($uriPath, 'resend-otp')) {
                $action = 'resend';
                $maxAttempts = self::MAX_RESEND_ATTEMPTS;
                $actionLabel = 'resend requests';
            } elseif (str_contains($uriPath, 'verify-otp')) {
                $action = 'otp';
                $maxAttempts = self::MAX_OTP_ATTEMPTS;
                $actionLabel = 'verification attempts';
            } elseif (str_starts_with($uriPath, 'guardian-consent')) {
                $action = 'guardian_consent';
                $maxAttempts = self::MAX_CONSENT_ATTEMPTS;
                $actionLabel = 'consent verification attempts';
            } elseif (str_starts_with($uriPath, 'register/process')) {
                $action = 'register';
                $maxAttempts = self::MAX_REGISTER_ATTEMPTS;
                $actionLabel = 'registration attempts';
            } elseif ($isDirectoryLookup) {
                $action = 'directory';
                $maxAttempts = self::MAX_DIRECTORY_ATTEMPTS;
                $actionLabel = 'directory lookup requests';
            } else {
                $action = 'login';
                $maxAttempts = self::MAX_LOGIN_ATTEMPTS;
                $actionLabel = 'login attempts';
            }

            $key = md5($ip . '_' . $action);
            $file = self::STORAGE_DIR . $key . '.json';

            // Ensure directory exists
            if (!is_dir(self::STORAGE_DIR)) {
                @mkdir(self::STORAGE_DIR, 0755, true);
            }

            if (!is_dir(self::STORAGE_DIR)) {
                return;
            }

            // Read existing attempts
            $data = ['attempts' => 0, 'first_attempt' => time()];
            if (file_exists($file)) {
                $data = json_decode(@file_get_contents($file), true) ?: $data;

                // Reset if lockout window has passed
                if ((time() - $data['first_attempt']) > self::LOCKOUT_SECONDS) {
                    $data = ['attempts' => 0, 'first_attempt' => time()];
                }
            }

            // Check if locked out
            if ($data['attempts'] >= $maxAttempts) {
                $remaining = self::LOCKOUT_SECONDS - (time() - $data['first_attempt']);
                $minutes = max(1, (int) ceil($remaining / 60));
                $errorMessage = "Too many {$actionLabel}. Please try again in {$minutes} minute(s).";

                if ($action === 'directory' || ($request instanceof \CodeIgniter\HTTP\IncomingRequest && $request->isAJAX())) {
                    return service('response')
                        ->setStatusCode(429)
                        ->setJSON(['status' => 'error', 'message' => $errorMessage]);
                }

                $redirect = redirect();
                if ($request->getServer('HTTP_REFERER')) {
                    return $redirect->back()->with('error', $errorMessage);
                }

                if ($action === 'guardian_consent') {
                    return $redirect->to('/guardian-consent')->with('error', $errorMessage);
                }

                if ($action === 'register') {
                    return $redirect->to('/register')->with('error', $errorMessage);
                }

                $target = (strpos($uriPath, 'admin') !== false) ? '/admin/login' : '/login';
                return $redirect->to($target)->with('error', $errorMessage);
            }

            // Increment attempt count
            $data['attempts']++;
            @file_put_contents($file, json_encode($data), LOCK_EX);
        } catch (\Throwable $e) {
            log_message('error', 'RateLimitFilter error: ' . $e->getMessage());
            return;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        try {
            $ip = $request->getIPAddress();
            $uriPath = trim($request->getUri()->getPath(), '/');

            if (str_starts_with($uriPath, 'guardian-consent') && $response->getStatusCode() === 200) {
                $consentFile = self::STORAGE_DIR . md5($ip . '_guardian_consent') . '.json';
                if (file_exists($consentFile)) {
                    @unlink($consentFile);
                }
                return;
            }

            $isAuthEndpoint = str_contains($uriPath, 'login') || str_contains($uriPath, 'verify-otp') || str_contains($uriPath, 'resend-otp');

            // On fully successful login, clear auth rate limit buckets for this IP
            if ($isAuthEndpoint && session()->get('isLoggedIn')) {
                foreach (['login', 'otp', 'resend', ''] as $action) {
                    $file = self::STORAGE_DIR . ($action ? md5($ip . '_' . $action) : md5($ip)) . '.json';
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
            }
            // On successful admin password entry (transitioning to OTP step), clear login rate limit
            elseif ($isAuthEndpoint && session()->has('otp') && session()->has('temp_user_id')) {
                $loginFile = self::STORAGE_DIR . md5($ip . '_login') . '.json';
                if (file_exists($loginFile)) {
                    @unlink($loginFile);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'RateLimitFilter after() error: ' . $e->getMessage());
        }
    }
}
