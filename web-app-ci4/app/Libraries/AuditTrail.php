<?php
namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;

final class AuditTrail
{
    public static function record(string $action, string $type, int $id, ?int $actor = null): void
    {
        try {
            $request = service('request');
            $ip = $request instanceof IncomingRequest ? $request->getIPAddress() : null;
            // Forwarded headers are used only through CI's configured trusted proxyIPs.
            $agent = $request instanceof IncomingRequest ? $request->getUserAgent()->getAgentString() : '';
            $db = \Config\Database::connect();
            ManilaTime::syncDatabaseSession($db);
            $db->table('audit_logs')->insert([
                'user_id' => $actor ?? session()->get('user_id') ?? session()->get('temp_user_id'), 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id,
                'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'user_agent' => mb_substr($agent, 0, 255), 'created_at' => ManilaTime::now(),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Audit write failed: {message}', ['message' => $e->getMessage()]);
        }
    }
}
