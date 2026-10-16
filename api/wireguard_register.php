<?php

require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/../services/tanguard.php';

// Routers request ?format=plain so they can read the raw assigned IP directly.
$plain = (($_GET['format'] ?? '') === 'plain');

header($plain ? 'Content-Type: text/plain' : 'Content-Type: application/json');

// In plain mode the router only understands a bare IP on success; anything else
// (empty body) signals failure.
function wg_respond($ok, array $data = []) {
    global $plain;
    if ($plain) {
        echo $ok ? (string) ($data['wireguard_ip'] ?? '') : '';
    } else {
        echo json_encode($ok
            ? array_merge(['success' => true], $data)
            : array_merge(['success' => false], $data));
    }
    exit;
}

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

// Pick a free IP that neither TunGuard nor a registered router already holds.
function wg_free_ip(PDO $db, array $exclude = []): ?string {
    $used = [];
    foreach (tunGuard()->getPeers() as $peer) {
        $allowed = $peer['allowed_ip'] ?? '';
        if ($allowed === '') continue;
        $addr = trim(explode(',', $allowed)[0]);
        $addr = trim(explode('/', $addr)[0]);
        if (filter_var($addr, FILTER_VALIDATE_IP)) $used[] = $addr;
    }
    $stmt = $db->query("SELECT wireguard_ip FROM routers WHERE wireguard_ip IS NOT NULL AND wireguard_ip != '0.0.0.0' AND type='mikrotik' AND provisioning_status = 'registered'");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ip) $used[] = $ip;
    foreach ($exclude as $e) $used[] = trim(explode('/', $e)[0]);
    $used = array_unique($used);
    for ($i = 2; $i < 254; $i++) {
        $ip = "10.100.0.$i";
        if (!in_array($ip, $used)) return $ip;
    }
    return null;
}

wg_log("--- REGISTER REQUEST ---");
wg_log("Device: $device");
wg_log("Pubkey: $pubkey");

if (!$device || !$pubkey) {
    $err = 'missing device or pubkey';
    wg_log("REJECTED: $err");
    wg_respond(false, ['error' => $err]);
}

$stmt = $db->prepare("
    UPDATE routers 
    SET wg_pubkey = :pubkey
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
    $requested = trim(explode('/', $router['wireguard_ip'])[0]);
    $tg = tunGuard();
    $assigned = null;

    // First try the IP we planned for at provision time.
    $r = $tg->addPeerDetailed($pubkey, $requested . '/32', $device, $device);
    if (isset($r['success']) && $r['success'] === true) {
        $assigned = $requested;
        wg_log("TunGuard addPeer OK for $pubkey -> $requested/32");
    } else {
        $err = (string) ($r['error'] ?? '');
        wg_log("TunGuard addPeer failed for $pubkey -> $requested/32 ($err)");

        // On conflict (or if we can't tell), pick a fresh free IP and retry.
        $exclude = [$requested];
        for ($attempt = 0; $attempt < 5 && $assigned === null; $attempt++) {
            $fresh = wg_free_ip($db, $exclude);
            if ($fresh === null) break;
            $r = $tg->addPeerDetailed($pubkey, $fresh . '/32', $device, $device);
            if (isset($r['success']) && $r['success'] === true) {
                $assigned = $fresh;
                wg_log("TunGuard addPeer retry OK for $pubkey -> $fresh/32");
                break;
            }
            $err = (string) ($r['error'] ?? '');
            wg_log("TunGuard addPeer retry failed for $pubkey -> $fresh/32 ($err)");
            $exclude[] = $fresh;
            if (!TunGuardAPI::isIpConflict($r)) break; // not a conflict; stop
        }
    }

    if ($assigned !== null) {
        // Persist the IP TunGuard actually accepted and mark the router fully
        // registered. We only flip provisioning_status once the peer exists.
        $db->prepare("UPDATE routers SET wireguard_ip = :ip, provisioning_status = 'registered' WHERE device_id = :device")
           ->execute([':ip' => $assigned, ':device' => $device]);
        if ($assigned !== $requested) {
            wg_log("Updated router $device wireguard_ip: $requested -> $assigned");
        }
        wg_log("SUCCESS: $device assigned $assigned/32");
        wg_respond(true, [
            'device' => $device,
            'pubkey' => $pubkey,
            'wireguard_ip' => $assigned,
        ]);
    }

    wg_log("ERROR: exhausted retries for $device");
    wg_respond(false, [
        'error' => 'failed to allocate a free WireGuard IP',
        'device' => $device,
        'pubkey' => $pubkey,
    ]);
}

wg_log("ERROR: no wireguard_ip for device_id=$device");

wg_respond(false, [
    'error' => 'no wireguard_ip for device',
    'device' => $device,
    'pubkey' => $pubkey
]);
