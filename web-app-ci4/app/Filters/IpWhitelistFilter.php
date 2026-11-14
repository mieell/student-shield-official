<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class IpWhitelistFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $configured = trim((string) env('ADMIN_ALLOWED_IPS', ''));
        if ($configured === '') {
            return;
        }

        $allowedIps = ['127.0.0.1', '::1'];
        foreach (explode(',', $configured) as $entry) {
            $ip = trim($entry);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $allowedIps[] = $ip;
            }
        }

        $clientIp = $request->getIPAddress();
        if (!in_array($clientIp, $allowedIps, true)) {
            log_message('warning', 'Blocked admin request from non-whitelisted IP: ' . $clientIp);
            return service('response')
                ->setStatusCode(403)
                ->setBody('<h1>403 Forbidden</h1><p>Access to the administrative panel is restricted.</p>');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No action needed after the request
    }
}
