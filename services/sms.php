<?php

require_once __DIR__ . '/../config.php';

define('SMS_API_KEY', (string) env('SMS_API_KEY', 'sv_live_ba4352a71620f7d426da24beb7b0e6466bdeeae5f616999b'));
define('SMS_API_URL', (string) env('SMS_API_URL', 'https://gateway.stackverify.site/api/sms/send'));

function sendSms($number, $message) {
    $number = preg_replace('/[^0-9]/', '', $number);
    if (strlen($number) >= 10 && substr($number, 0, 1) !== '0') {
        // Already in international format without +
        $number = '+' . $number;
    } elseif (substr($number, 0, 1) === '0') {
        // Local number starting with 0 — assume Tanzania
        $number = '+255' . substr($number, 1);
    } else {
        $number = '+' . $number;
    }

    $ch = curl_init(SMS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . SMS_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'number' => $number,
            'message' => $message,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $result,
    ];
}

function sendExpiryReminders($db) {
    $stmt = $db->prepare("
        SELECT v.id, v.code, v.phone, v.used_at, v.price,
               p.days, p.hours, p.minutes
        FROM vouchers v
        LEFT JOIN plans p ON v.plan_id = p.id
        WHERE v.status = 'used'
          AND v.phone IS NOT NULL AND v.phone != ''
          AND (v.reminder_sent IS NULL OR v.reminder_sent = 0)
    ");
    $stmt->execute();
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $errors = [];

    foreach ($vouchers as $v) {
        if (empty($v['used_at'])) continue;

        $duration = ($v['days'] ?? 0) * 86400 + ($v['hours'] ?? 0) * 3600 + ($v['minutes'] ?? 0) * 60;
        if ($duration <= 0) continue;

        $usedAt = strtotime($v['used_at']);
        $endAt = $usedAt + $duration;
        $now = time();
        $remaining = $endAt - $now;

        if ($remaining > 0 && $remaining <= 300) {
            $message = "Muda wako unakaribia kikomo chake. interneti itakata hivi karibuni nunua voucher ongeza muda wako wa kuendelea.";
            $result = sendSms($v['phone'], $message);

            if ($result['success']) {
                $db->prepare("UPDATE vouchers SET reminder_sent = 1 WHERE id = :id")
                   ->execute([':id' => $v['id']]);
                $sent++;
            } else {
                $errors[] = [
                    'voucher_id' => $v['id'],
                    'phone' => $v['phone'],
                    'error' => $result['response'] ?? 'HTTP ' . $result['http_code'],
                ];
            }
        }
    }

    return ['sent' => $sent, 'errors' => $errors];
}

if (php_sapi_name() !== 'cli' && !defined('STDIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['to'], $input['message'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: to, message']);
        exit;
    }

    $result = sendSms($input['to'], $input['message']);

    if ($result['success']) {
        echo json_encode(['success' => true, 'response' => $result['response']]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $result['response']]);
    }
}
