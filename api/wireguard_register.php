<?php

require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/../services/tanguard.php';

header('Content-Type: application/json');

$device = '';
$pubkey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $parts = explode('&', $rawBody);
        foreach ($parts as $part) {
            $eqPos = strpos($part, '=');
            if ($eqPos !== false) {
                $key = substr($part, 0, $eqPos);
                $value = substr($part, $eqPos + 1);
                if ($key === 'device') $device = $value;
                if ($key === 'pubkey') $pubkey = $value;
            }
        }
    }
}

if (empty($device)) $device = $_GET['device'] ?? '';
if (empty($pubkey)) $pubkey = $_GET['pubkey'] ?? '';

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/wireguard_register.log';

function wg_log($msg) {
    global $logFile;
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
}

wg_log("--- REGISTER REQUEST ---");
wg_log("Device: $device");
wg_log("Pubkey: $pubkey");

if (!$device || !$pubkey) {
    $err = 'missing device or pubkey';
    wg_log("REJECTED: $err");
    echo json_encode(['error' => $err]);
    exit;
}

$stmt = $db->prepare("
    UPDATE routers 
    SET wg_pubkey = :pubkey,
        provisioning_status = 'registered'
    WHERE device_id = :device
");

$stmt->execute([
    ':pubkey' => $pubkey,
    ':device' => $device
]);

$matched = $stmt->rowCount();
wg_log("DB update: $matched row(s) matched");

if ($matched === 0) {
    wg_log("WARNING: no router with device_id=$device");
}

$ip = $db->prepare("
    SELECT wireguard_ip 
    FROM routers 
    WHERE device_id = :device
");

$ip->execute([
    ':device' => $device
]);

$router = $ip->fetch(PDO::FETCH_ASSOC);

if ($router && !empty($router['wireguard_ip'])) {
    $allowed = $router['wireguard_ip'];

    // Register the router as a peer on the TunGuard server via its API.
    $added = tunGuard()->addPeer($pubkey, $allowed . '/32', $device, $device);

    if ($added) {
        wg_log("TunGuard addPeer OK for $pubkey -> $allowed/32");
    } else {
        wg_log("ERROR: TunGuard addPeer failed for $pubkey -> $allowed/32");
    }
} else {
    wg_log("ERROR: no wireguard_ip for device_id=$device");
}

wg_log("--- DONE ---");

echo json_encode([
    'success' => true,
    'device' => $device,
    'pubkey' => $pubkey
]);
