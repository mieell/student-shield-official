<?php

namespace App\Libraries;

/** iProg SMS transport. A queued request is not proof of delivery. */
class SmsGateway
{
    public function send(string $phone, string $message, string $eventKey): array
    {
        $phone = preg_replace('/[\s()\-]/', '', $phone);
        if (preg_match('/\A09\d{9}\z/D', $phone)) $phone = '63' . substr($phone, 1);
        if (preg_match('/\A\+639\d{9}\z/D', $phone)) $phone = substr($phone, 1);
        $eventId = hash('sha256', (string) env('app.baseURL', '') . "\0" . $eventKey . "\0" . $phone);
        $result = ['accepted' => false, 'message_id' => null, 'state' => 'InvalidRequest', 'http_status' => 0];
        if (!preg_match('/\A639\d{9}\z/D', $phone) || trim($eventKey) === '' || trim($message) === ''
            || strlen($message) > 160 || !preg_match('/\A[A-Za-z0-9 .,:;!?()\/+\-\r\n]+\z/D', $message)) return $result;
        $token = trim((string) env('IPROG_SMS_API_TOKEN', ''));
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            $result['state'] = 'NotConfigured';
            return $result;
        }

        // iProg does not document a client idempotency key. Reserve before POST.
        // A timeout or crash is ambiguous: do not silently send the event again.
        $dir = WRITEPATH . 'sms-events';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $result['state'] = 'StorageUnavailable';
            return $result;
        }
        $file = @fopen($dir . DIRECTORY_SEPARATOR . $eventId . '.json', 'c+');
        if (!$file || !flock($file, LOCK_EX)) {
            if ($file) fclose($file);
            $result['state'] = 'StorageUnavailable';
            return $result;
        }
        try {
            rewind($file);
            $previous = stream_get_contents($file);
            if ($previous !== '') {
                $stored = json_decode($previous, true);
                return is_array($stored) && isset($stored['state'], $stored['accepted']) ? $stored : array_merge($result, ['state' => 'Unknown']);
            }
            $unknown = array_merge($result, ['state' => 'Unknown']);
            if (fwrite($file, json_encode($unknown, JSON_THROW_ON_ERROR)) === false || !fflush($file)) return $unknown;
            try {
                $response = $this->request(['api_token' => $token, 'phone_number' => $phone, 'message' => $message]);
                $result = $unknown;
                $result['http_status'] = (int) ($response['status'] ?? 0);
                $body = json_decode((string) ($response['body'] ?? ''), true);
                if ($result['http_status'] >= 200 && $result['http_status'] < 300 && is_array($body)
                    && (int) ($body['status'] ?? 0) === 200 && is_string($body['message_id'] ?? null)
                    && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $body['message_id'])) {
                    $result['accepted'] = true;
                    $result['message_id'] = $body['message_id'];
                    $result['state'] = 'Queued';
                } elseif ($result['http_status'] >= 400 && $result['http_status'] < 500 && $result['http_status'] !== 408 && $result['http_status'] !== 429) {
                    $result['state'] = 'Rejected';
                }
            } catch (\Throwable $e) {
                $result = $unknown;
            }
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, json_encode($result, JSON_THROW_ON_ERROR)) === false || !fflush($file)) return $unknown;
            log_message($result['accepted'] ? 'info' : 'warning', 'iProg SMS: {state} (HTTP {status}).', ['state' => $result['state'], 'status' => $result['http_status']]);
            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    protected function request(array $payload): array
    {
        $body = '';
        $curl = curl_init('https://www.iprogsms.com/api/v1/sms_messages');
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_USERAGENT => 'StudentShield/1.0', CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally {
            curl_close($curl);
        }
    }
}
