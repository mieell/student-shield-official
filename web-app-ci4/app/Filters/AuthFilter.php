<?php
namespace App\Filters;

use App\Libraries\AuditTrail;
use App\Libraries\RoleAccess;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    protected function account(int $id): ?array
    {
        return \Config\Database::connect()->table('users')->where('id', $id)->get()->getRowArray();
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $path = trim($request->getUri()->getPath(), '/');
        $staff = preg_match('#^(admin|counselor|principal)(/|$)#', $path) === 1;
        $json = str_contains($path, '/api/') || $request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'json');
        if (!session()->get('isLoggedIn')) {
            $cookie = $staff ? '' : ($_COOKIE['student_remember'] ?? '');
            $parts = explode(':', (string) base64_decode($cookie, true), 2);
            if (count($parts) === 2 && ctype_digit($parts[0])) {
                try {
                    $user = $this->account((int) $parts[0]);
                    if ($user && RoleAccess::enabled($user) && ($staff ? (int) $user['role'] > 0 : (int) $user['role'] === 0)
                        && !empty($user['remember_token']) && password_verify($parts[1], $user['remember_token'])) {
                        session()->regenerate();
                        session()->set(['user_id' => $user['id'], 'name' => $user['name'], 'role' => (int) $user['role'],
                            'auth_version' => (int) ($user['auth_version'] ?? 1), 'isLoggedIn' => true, 'last_activity' => time()]);
                        AuditTrail::record('TRUSTED_DEVICE_LOGIN', 'system', (int) $user['id']);
                    }
                } catch (\Throwable $e) { log_message('error', 'Remembered account lookup failed.'); }
            }
        }
        if (!session()->get('isLoggedIn')) {
            if ($json) return service('response')->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Please log in again.']);
            return redirect()->to($staff ? '/admin/login' : '/login');
        }
        $last = (int) session()->get('last_activity');
        if ($last && time() - $last > 900) {
            AuditTrail::record('SESSION_IDLE_TIMEOUT', 'system', (int) session()->get('user_id'));
            session()->destroy();
            if ($json) return service('response')->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Session expired due to inactivity. Please log in again.']);
            return redirect()->to($staff ? '/admin/login' : '/login');
        }
        // Revalidate on every protected request: deactivation and role changes revoke existing sessions.
        try { $user = $this->account((int) session()->get('user_id')); }
        catch (\Throwable $e) {
            return service('response')->setStatusCode(503)->setJSON(['status' => 'error', 'message' => 'Account verification unavailable.']);
        }
        if (!$user || !RoleAccess::enabled($user) || (int) $user['role'] !== (int) session()->get('role')
            || (int) ($user['auth_version'] ?? 1) !== (int) session()->get('auth_version')) {
            session()->destroy();
            return service('response')->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Account access changed. Please log in again.']);
        }
        session()->set(['last_activity' => time(), 'name' => $user['name']]);
        service('response')->setHeader('Cache-Control', 'private, no-store');
        if (!RoleAccess::allows((int) $user['role'], $path)) {
            if ((int) session()->get('denial_logged_at') < time() - 60) {
                AuditTrail::record('ACCESS_DENIED', 'system', (int) $user['id']);
                session()->set('denial_logged_at', time());
            }
            if (!$json && strtolower($request->getMethod()) === 'get') {
                return redirect()->to(RoleAccess::home((int) $user['role']));
            }
            return service('response')->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Your role cannot access this resource.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'private, no-store');
    }
}
