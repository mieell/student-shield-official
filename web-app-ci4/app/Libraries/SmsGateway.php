<?php

namespace App\Libraries;

/** Authenticated SMSGate cloud transport. Acceptance is not a delivery receipt. */
class SmsGateway
{
    public function send(string $phone, string $message, string $eventKey): array
    {
        $phone = preg_replace('/[\s()\-]/', '', $phone);
        if (preg_match('/\A09\d{9}\z/D', $phone)) $phone = '+63' . substr($phone, 1);
        if (preg_match('/\A639\d{9}\z/D', $phone)) $phone = '+' . $phone;
        // Cloud message IDs are limited to 36 characters; retain 128 bits of identity.
        $id = 'ss-' . substr(hash('sha256', env('app.baseURL', '') . "\0" . $eventKey . "\0" . $phone), 0, 32);
        $result = ['accepted' => false, 'message_id' => $id, 'state' => 'InvalidRequest', 'http_status' => 0];

        // Short, generic ASCII templates fit the gateway's one-part demo policy.
        if (!preg_match('/\A\+639\d{9}\z/D', $phone) || trim($eventKey) === ''
            || trim($message) === '' || strlen($message) > 160
            || !preg_match('/\A[A-Za-z0-9 .,:;!?()\/+\-\r\n]+\z/D', $message)) {
            return $this->record($result);
        }

        $url = rtrim(trim((string) env('sms.GatewayUrl', '')), '/');
        $username = trim((string) env('sms.GatewayUsername', ''));
        $password = (string) env('sms.GatewayPassword', '');
        $deviceId = trim((string) env('sms.GatewayDeviceId', ''));
        $simNumber = trim((string) env('sms.GatewaySimNumber', ''));
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $username === '' || $password === '' || preg_match('/[:\r\n]/', $username)
            || preg_match('/[\r\n]/', $password) || !preg_match('/\A[A-Za-z0-9_-]{1,21}\z/D', $deviceId)
            || !in_array($simNumber, ['', '1', '2'], true)) {
            $result['state'] = 'NotConfigured';
            return $this->record($result);
        }

        $payload = [
            'id' => $id,
            'deviceId' => $deviceId,
            'textMessage' => ['text' => $message],
            'phoneNumbers' => [$phone],
            'withDeliveryReport' => true,
            'ttl' => 300,
        ];
        if ($simNumber !== '') $payload['simNumber'] = (int) $simNumber;

        try {
            $response = $this->request('POST', $url, $username, $password, $payload);
            // An ambiguous send must never be retried under a new ID.
            $duplicate = $response['status'] === 409;
            if ($duplicate) {
                $response = $this->request('GET', $url . '/' . rawurlencode($id), $username, $password);
            }
            $result['http_status'] = $response['status'];
            $result['state'] = 'Unknown';
            if (in_array($response['status'], [200, 201, 202], true)) {
                $body = json_decode($response['body'], true);
                if (is_array($body) && ($body['id'] ?? '') === $id
                    && in_array($body['state'] ?? '', ['Pending', 'Processed', 'Sent', 'Delivered', 'Failed', 'Cancelled', 'Cancelling'], true)) {
                    $result['state'] = $body['state'];
                    $result['accepted'] = in_array($body['state'], ['Pending', 'Processed', 'Sent', 'Delivered'], true);
                }
            } elseif (!$duplicate && $response['status'] >= 400 && $response['status'] < 500 && $response['status'] !== 408) {
                $result['state'] = 'Rejected';
            }
        } catch (\Throwable $error) {
            // Provider exceptions and responses can contain credentials or phone numbers.
            $result['state'] = 'Unknown';
        }
        return $this->record($result);
    }

    private function record(array $result): array
    {
        log_message($result['accepted'] ? 'info' : 'warning', 'SMS gateway {id}: {state} (HTTP {status}).', [
            'id' => $result['message_id'], 'state' => $result['state'], 'status' => $result['http_status'],
        ]);
        return $result;
    }

    protected function request(string $method, string $url, string $username, string $password, ?array $payload = null): array
    {
        $body = '';
        $curl = curl_init($url);
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $username . ':' . $password,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_USERAGENT => 'StudentShield/1.0',
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            curl_setopt_array($curl, $options);
            $ok = curl_exec($curl);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally {
            curl_close($curl);
        }
    }
}
