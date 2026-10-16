<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/mikrotik_api.php';
require_once __DIR__ . '/../services/tanguard.php';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function generateDeviceId() {
    return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        mt_rand(0, 0xffffffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffffffffffff)
    );
}

function generateProvisionToken() {
    return bin2hex(random_bytes(16));
}

function getServerHost() {
    return appHost();
}

function getServerScheme() {
    return appScheme();
}

function getServerWgPort() {
    $port = tunGuard()->getServerListenPort();
    if (!empty($port)) {
        return intval($port);
    }
    return 13231;
}

function getWireGuardEndpoint() {
    $endpoint = trim((string) env('WG_ENDPOINT', ''));
    if ($endpoint !== '') {
        return $endpoint;
    }
    return appHost();
}

function getServerPublicKey() {
    $key = trim((string) tunGuard()->getServerPublicKey());
    return $key !== '' ? $key : null;
}

function assignWireGuardIP($db) {
    // IPs already recorded locally.
    $stmt = $db->query("SELECT wireguard_ip FROM routers WHERE wireguard_ip IS NOT NULL AND type='mikrotik'");
    $used = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Also avoid anything TunGuard has already allocated, even if it is not
    // in our own DB (e.g. peers added via the TunGuard dashboard).
    foreach (tunGuard()->getPeers() as $peer) {
        $allowed = $peer['allowed_ip'] ?? '';
        if ($allowed === '') {
            continue;
        }
        // allowed_ip may be "10.100.0.5/32" or a list; take the first address.
        $addr = trim(explode(',', $allowed)[0]);
        $addr = trim(explode('/', $addr)[0]);
        if (filter_var($addr, FILTER_VALIDATE_IP)) {
            $used[] = $addr;
        }
    }

    for ($i = 2; $i < 254; $i++) {
        $ip = "10.100.0.$i";
        if (!in_array($ip, $used)) return $ip;
    }
    return null;
}

function addWireGuardPeer($peerPubKey, $allowedIP) {
    return tunGuard()->addPeer($peerPubKey, "$allowedIP/32");
}

function removeWireGuardPeer($peerPubKey) {
    return tunGuard()->removePeer($peerPubKey);
}

function generateProvisionScript($db, $routerId, $name, $wireguardIP, $deviceId, $mode = 'hotspot') {
    $mode = in_array($mode, ['hotspot', 'pppoe'], true) ? $mode : 'hotspot';
    $listenPort = getServerWgPort();
    $timestamp = date('Ymd_His');
    $serverHost = getServerHost();
    $serverScheme = getServerScheme();
    $serverPubKey = getServerPublicKey();
    $wgEndpoint = getWireGuardEndpoint();
    $hasWG = !empty($serverPubKey) && !empty($wireguardIP) && $wireguardIP !== '0.0.0.0';

    $script = "# OneLink Auto-Provisioning Script\n";
    $script .= "# Device: $name - $deviceId\n";
    $script .= "# Generated: " . date('Y-m-d H:i:s') . " UTC\n";
    $script .= "# Service Mode: $mode\n";
    $script .= "# Client WireGuard IP: $wireguardIP\n\n";

    $script .= "/system identity set name=\"$name\"\n\n";

    $script .= ":do { /interface bridge remove [find name=onelink-bridge] } on-error={}\n";
    $script .= "/interface bridge add comment=\"OneLink Bridge\" name=onelink-bridge\n";
    $script .= "# --- Wireless Configuration ---\n";
    $script .= "/interface wireless security-profiles set [find default=yes] mode=none\n";
    $script .= "/interface wireless set [find default-name=wlan1] disabled=no mode=ap-bridge ssid=\"OneLink\" security-profile=default\n";
    $script .= ":do { /interface bridge port remove [find interface=wlan1] } on-error={}\n";
    $script .= "/interface bridge port add bridge=onelink-bridge interface=wlan1\n\n";

    $script .= ":do { /ip address remove [find interface=onelink-bridge] } on-error={}\n";
    $script .= "/ip address add address=10.10.0.1/24 comment=\"Added by OneLink\" interface=onelink-bridge\n";
    $script .= ":do { /ip pool remove [find name=onelink-pool] } on-error={}\n";
    $script .= "/ip pool add name=onelink-pool ranges=10.10.0.2-10.10.0.254\n";
    if ($mode !== 'pppoe') {
        $script .= ":do { /ip dhcp-server remove [find name=onelink-dhcp] } on-error={}\n";
        $script .= "/ip dhcp-server add address-pool=onelink-pool disabled=no interface=onelink-bridge name=onelink-dhcp\n";
        $script .= ":do { /ip dhcp-server network remove [find address~\"10.10.0.0\"] } on-error={}\n";
        $script .= "/ip dhcp-server network add address=10.10.0.0/24 dns-server=10.10.0.1,8.8.8.8,8.8.4.4 gateway=10.10.0.1\n";
    }
    $script .= "\n";

    $script .= ":do { /radius remove [find service=hotspot] } on-error={}\n";
    $script .= ":do { /radius remove [find service=ppp] } on-error={}\n";
    $script .= "/radius add address=10.100.0.1 secret=\"onelink123\" service=hotspot authentication-port=1812 accounting-port=1813 timeout=3s realm=\"$deviceId\" comment=\"OneLink RADIUS\"\n";
    $script .= "/radius add address=10.100.0.1 secret=\"onelink123\" service=ppp authentication-port=1812 accounting-port=1813 timeout=3s realm=\"$deviceId\" comment=\"OneLink RADIUS PPP\"\n";
    $script .= "/radius incoming set accept=yes port=3799\n\n";

    if ($hasWG) {
        $script .= ":do { /interface wireguard remove [find name=onelink-wg] } on-error={}\n";
        $script .= "/interface wireguard add mtu=1420 name=onelink-wg listen-port=13241\n\n";

        $script .= ":do { /ip address remove [find interface=onelink-wg] } on-error={}\n";
        $script .= "/ip address add address=$wireguardIP/24 interface=onelink-wg\n\n";

        $script .= ":do { /interface wireguard peers remove [find interface=onelink-wg] } on-error={}\n";
        $script .= "/interface wireguard peers add interface=onelink-wg public-key=\"$serverPubKey\" endpoint-address=$wgEndpoint endpoint-port=$listenPort allowed-address=10.100.0.0/24 persistent-keepalive=25s\n\n";

        $script .= ":local wgPub [/interface wireguard get [find name=onelink-wg] public-key]\n";
        $script .= ":local url \"{$serverScheme}://{$serverHost}/api/wireguard_register.php\"\n";
        $script .= ":local postData (\"device={$deviceId}&pubkey=\" . \$wgPub)\n";
        $script .= ":local r [/tool fetch mode=https url=\$url http-method=post http-data=\$postData http-header-field=\"Content-Type: application/x-www-form-urlencoded\" keep-result=no as-value]\n";
        $script .= ":log info (\"WG REGISTER: \" . (\$r->\"status\"))\n\n";
    } else {
        $script .= "# WireGuard skipped (server not configured)\n\n";
    }

    $serverSubnet = '192.168.0.0/16';
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    $serverIP = preg_replace('/:\d+$/', '', $requestHost);
    if (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $serverIP, $m)) {
        $serverSubnet = $m[1] . '.0/24';
    }

    if ($hasWG) {
        $script .= "/ip service set api-ssl address=10.100.0.0/24 disabled=no port=8729\n";
        $script .= "/ip service set ssh address=$serverSubnet,10.100.0.0/24 disabled=no\n";
        $script .= "/ip service set www address=10.100.0.0/24 disabled=no port=8080\n\n";
    } else {
        $script .= "/ip service set api-ssl address=0.0.0.0/0 disabled=no port=8729\n";
        $script .= "/ip service set ssh address=0.0.0.0/0 disabled=no\n";
        $script .= "/ip service set www address=0.0.0.0/0 disabled=no port=8080\n\n";
    }

    $apiUser = 'onelink-api';

    // Reuse the existing password if the router already exists
    $stmt = $db->prepare("SELECT password FROM routers WHERE id = ?");
    $stmt->execute([$routerId]);
    $apiPass = $stmt->fetchColumn();

    if (empty($apiPass)) {
        $apiPass = 'api_' . bin2hex(random_bytes(4));

        $stmt = $db->prepare("UPDATE routers SET password = ? WHERE id = ?");
        $stmt->execute([$apiPass, $routerId]);
    }

    $script .= ":do { /user remove [find name=$apiUser] } on-error={}\n";
    $script .= "/user add name=$apiUser password=\"$apiPass\" group=full comment=\"OneLink Management API\"\n\n";

    $fwSrc = $hasWG ? "src-address=10.100.0.0/24" : "src-address=0.0.0.0/0";
    $fwSrcExtra = $hasWG ? "src-address=$serverSubnet" : "";
    $fwRules = [
        ["Allow WireGuard", "protocol=udp dst-port=13241 src-address=10.100.0.1"],
        ["Allow API", "protocol=tcp dst-port=8728 $fwSrc"],
        ["Allow REST (www)", "protocol=tcp dst-port=8080 $fwSrc"],
        ["Allow SSH from WireGuard", "protocol=tcp dst-port=22 $fwSrc"],
        ["Allow SNMP from WireGuard", "protocol=udp dst-port=161 $fwSrc"],
        ["Allow RADIUS CoA from server", "dst-port=3799 protocol=udp src-address=10.100.0.0/24"],
    ];
    foreach ($fwRules as [$comment, $params]) {
        $script .= ":do { /ip firewall filter remove [find chain=input comment=\"$comment\"] } on-error={}\n";
        $script .= "/ip firewall filter add action=accept chain=input comment=\"$comment\" $params\n";
    }
    if ($hasWG && !empty($fwSrcExtra)) {
        $extraComments = [
            ["Allow API from LAN", "protocol=tcp dst-port=8728 $fwSrcExtra"],
            ["Allow WWW from LAN", "protocol=tcp dst-port=8080 $fwSrcExtra"],
            ["Allow SSH from LAN", "protocol=tcp dst-port=22 $fwSrcExtra"],
        ];
        foreach ($extraComments as [$comment, $params]) {
            $script .= ":do { /ip firewall filter remove [find chain=input comment=\"$comment\"] } on-error={}\n";
            $script .= "/ip firewall filter add action=accept chain=input comment=\"$comment\" $params\n";
        }
    }
    $script .= "\n";

    $script .= "/snmp set enabled=yes\n";
    $script .= ":do { /snmp community remove [find name=onelink] } on-error={}\n";
    $script .= "/snmp community add name=onelink addresses=10.100.0.0/24\n\n";

    $script .= ":do { /ip firewall nat remove [find comment=\"OneLink Internet Access\"] } on-error={}\n";
    $script .= "/ip firewall nat add action=masquerade chain=srcnat comment=\"OneLink Internet Access\"\n\n";

    if ($mode === 'pppoe') {
        $script .= "# --- PPPoE Server (WISP) ---\n";
        $script .= ":do { /ppp profile remove [find name=onelink-pppoe] } on-error={}\n";
        $script .= "/ppp profile add name=onelink-pppoe local-address=10.10.0.1 remote-address=onelink-pool dns-server=10.10.0.1,8.8.8.8,8.8.4.4 comment=\"OneLink PPPoE Profile\"\n";
        $script .= ":do { /interface pppoe-server server remove [find name=onelink-pppoe] } on-error={}\n";
        $script .= "/interface pppoe-server server add name=onelink-pppoe interface=onelink-bridge service-name=onelink default-profile=onelink-pppoe authentication=pap,chap,mschap1,mschap2 one-session-per-host=yes max-mtu=1480 max-mru=1480 keepalive-timeout=60\n";
        $script .= "/interface pppoe-server server enable [find name=\"onelink-pppoe\"]\n\n";
        $script .= "# PPPoE user accounts are managed via RADIUS (service=ppp) by the OneLink server\n\n";
    } else {
        $script .= "# --- Hotspot / Captive Portal ---\n";
        $script .= ":do { /ip hotspot profile remove [find name=hs-prof1] } on-error={}\n";
        $script .= "/ip hotspot profile add name=hs-prof1 hotspot-address=10.10.0.1 dns-name=onelink.local login=http-pap\n\n";

        $script .= ":do { /ip hotspot remove [find name=hotspot1] } on-error={}\n";
        $script .= "/ip hotspot add name=hotspot1 interface=onelink-bridge address-pool=onelink-pool profile=hs-prof1\n";
        $script .= "/ip hotspot enable [find name=\"hotspot1\"]\n\n";

        $script .= ":do { /ip hotspot walled-garden remove [find dst-host~\"$serverHost\"] } on-error={}\n";
        $script .= "/ip hotspot walled-garden add action=allow dst-host=$serverHost\n";
        $script .= "/ip hotspot walled-garden add action=allow dst-host=*.$serverHost\n\n";

        $script .= ":do { /ip dns static remove [find name=onelink.local] } on-error={}\n";
        $script .= "/ip dns static add name=onelink.local address=10.10.0.1 ttl=1m\n\n";
    }

    $script .= "/ip dns set allow-remote-requests=yes servers=8.8.8.8,8.8.4.4\n\n";

    if ($mode === 'hotspot') {
        $script .= "/tool fetch mode={$serverScheme} url=\"{$serverScheme}://{$serverHost}/api/hotspot_login.php?device={$deviceId}\" dst-path=hotspot/login.html keep-result=yes as-value\n\n";
    }

    $script .= "/log info \"OneLink provisioning completed successfully\"\n";
    $script .= "/log info \"Device ID: $deviceId\"\n";
    $script .= "/log info \"WireGuard Client IP: $wireguardIP\"\n\n";

    $script .= ":put \"======================================\"\n";
    $script .= ":put \"OneLink Provisioning Complete!\"\n";
    $script .= ":put \"======================================\"\n";
    $script .= ":put \"Device ID: $deviceId\"\n";
    $script .= ":put \"Client IP: $wireguardIP\"\n";
    $script .= ":put \"Server IP: 10.100.0.1\"\n";
    $script .= ":put \"======================================\"\n";

    return [
        'script' => $script,
        'api_user' => $apiUser,
        'api_pass' => $apiPass,
        'listen_port' => $listenPort,
        'timestamp' => $timestamp,
    ];
}

function getWgPeerData() {
    $peers = [];
    foreach (tunGuard()->getPeerHandshakes() as $key => $timestamp) {
        $peers[$key] = ['handshake' => $timestamp];
    }
    return $peers;
}

function isRouterOnlineWg($router) {
    if (empty($router['wg_pubkey'])) return false;
    if (empty($router['wireguard_ip'])) return false;

    $peers = getWgPeerData();
    $pubkey = $router['wg_pubkey'];
    if (!isset($peers[$pubkey])) return false;

    $handshake = $peers[$pubkey]['handshake'];
    if ($handshake <= 0) return false;
    return (time() - $handshake) < 180;
}

function peerExists($peerPubKey) {
    $peers = getWgPeerData();
    return isset($peers[$peerPubKey]);
}

function checkWgHandshake($peerPubKey) {
    $peers = getWgPeerData();
    if (!isset($peers[$peerPubKey])) return false;
    $handshake = $peers[$peerPubKey]['handshake'];
    if ($handshake <= 0) return false;
    return (time() - $handshake) < 180;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'register';

    if ($action === 'register') {
        $name = trim($input['name'] ?? '');
        $location = trim($input['location'] ?? '');
        $lanIP = trim($input['lan_ip'] ?? '');

        if (empty($name)) {
            jsonResponse(['error' => 'Device name is required'], 400);
        }

        $serverPubKey = getServerPublicKey();

        $deviceId = generateDeviceId();
        $provisionToken = generateProvisionToken();

        $serverPubKeyNeeded = !empty($serverPubKey);
        if ($serverPubKeyNeeded) {
            $wireguardIP = assignWireGuardIP($db);
            if (!$wireguardIP) {
                jsonResponse(['error' => 'No available WireGuard IPs (10.100.0.2-254 exhausted)'], 500);
            }
        } else {
            $wireguardIP = null;
        }

        $tenantId = $_SESSION['account_id'] ?? null;
        $storedIP = !empty($lanIP) ? $lanIP : '0.0.0.0';

        $stmt = $db->prepare("
            INSERT INTO routers (name, ip, port, password, type, location, device_id, wireguard_ip, provisioning_status, provision_token, tenant_id)
            VALUES (:name, :ip, 8728, '12345678', 'mikrotik', :location, :device_id, :wireguard_ip, 'pending', :provision_token, :tenant_id)
        ");
        $stmt->execute([
            ':name' => $name,
            ':ip' => $storedIP,
            ':location' => $location,
            ':device_id' => $deviceId,
            ':wireguard_ip' => $wireguardIP ?? '0.0.0.0',
            ':provision_token' => $provisionToken,
            ':tenant_id' => $tenantId,
        ]);

        $routerId = $db->lastInsertId();

        $provData = generateProvisionScript($db, $routerId, $name, $wireguardIP, $deviceId);

        if (isset($provData['error'])) {
            jsonResponse(['error' => $provData['error']], 500);
        }

        jsonResponse([
            'success' => true,
            'router_id' => $routerId,
            'device_id' => $deviceId,
            'provision_token' => $provisionToken,
            'wireguard_ip' => $wireguardIP,
            'server_public_key' => $serverPubKey,
            'api_user' => $provData['api_user'],
            'api_pass' => $provData['api_pass'],
            'timestamp' => $provData['timestamp'],
            'fetch_command' => "/tool fetch mode={$serverScheme} url=\"{$serverScheme}://" . getServerHost() . "/provision/$provisionToken\" dst-path=onelink_{$provData['timestamp']}.rsc; :delay 2s; /import onelink_{$provData['timestamp']}.rsc;",
        ]);
    }

    if ($action === 'check_status') {
        $routerId = $input['router_id'] ?? null;
        if (!$routerId) {
            jsonResponse(['error' => 'router_id required'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
        $stmt->execute([':id' => $routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$router) {
            jsonResponse(['error' => 'Router not found'], 404);
        }

        $isOnline = isRouterOnlineWg($router);

        $newStatus = $isOnline ? 'online' : 'offline';
        $stmt = $db->prepare("UPDATE routers SET provisioning_status = :status WHERE id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $routerId]);

        jsonResponse([
            'router_id' => (int)$routerId,
            'status' => $newStatus,
            'wireguard_ip' => $router['wireguard_ip'],
        ]);
    }

    if ($action === 'save_config') {
        $routerId = $input['router_id'] ?? null;
        if (!$routerId) {
            jsonResponse(['error' => 'router_id required'], 400);
        }

        $serviceMode = $input['services']['service_mode'] ?? 'hotspot';
        if (!in_array($serviceMode, ['hotspot', 'pppoe'], true)) {
            $serviceMode = 'hotspot';
        }

        $stmt = $db->prepare("UPDATE routers SET provisioning_status = 'online', last_provisioned_at = :ts, service_mode = :mode WHERE id = :id");
        $stmt->execute([':ts' => date('Y-m-d H:i:s'), ':id' => $routerId, ':mode' => $serviceMode]);

        jsonResponse(['success' => true, 'message' => 'Configuration saved']);
    }

    if ($action === 'delete') {
        $routerId = $input['router_id'] ?? null;
        if (!$routerId) {
            jsonResponse(['error' => 'router_id required'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
        $stmt->execute([':id' => $routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$router) {
            jsonResponse(['error' => 'Router not found'], 404);
        }

        if (!empty($router['wg_pubkey'])) {
            removeWireGuardPeer($router['wg_pubkey']);
        }

        $stmt = $db->prepare("DELETE FROM routers WHERE id = :id");
        $stmt->execute([':id' => $routerId]);

        jsonResponse(['success' => true, 'message' => 'Device deleted']);
    }

    if ($action === 'configure_wireless') {
        $routerId = intval($input['router_id'] ?? 0);
        $ssid = trim($input['ssid'] ?? '');
        $security = $input['security'] ?? 'open';

        if (!$routerId || empty($ssid)) {
            jsonResponse(['error' => 'router_id and ssid required'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
        $stmt->execute([':id' => $routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$router) {
            jsonResponse(['error' => 'Router not found'], 404);
        }

        $apiIP = !empty($router['wireguard_ip']) ? $router['wireguard_ip'] : $router['ip'];
        $apiPort = intval($router['port'] ?: 8729);

        try {
            $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
            $api->connect();

            if ($security === 'open') {
                try { $api->setWirelessProfile('onelink-open', 'none', 'none'); } catch (Exception $e) { /* some RouterOS versions reject this */ }
                $api->setWireless($ssid, 'onelink-open', 'ap-bridge');
            } else {
                $api->setWireless($ssid, 'default', 'ap-bridge');
            }

            $api->setBridgePort('wlan1', 'onelink-bridge');
            $api->close();

            jsonResponse(['success' => true, 'message' => "Wireless configured: $ssid"]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'API connection failed: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['error' => 'Unknown action'], 400);
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $tenantId = $_SESSION['account_id'] ?? null;
        $role = $_SESSION['role'] ?? null;

        if ($role === 'superadmin') {
            $stmt = $db->query("SELECT * FROM routers WHERE type = 'mikrotik' ORDER BY id DESC");
        } else {
            $stmt = $db->prepare("SELECT * FROM routers WHERE type = 'mikrotik' AND tenant_id = :tid ORDER BY id DESC");
            $stmt->execute([':tid' => $tenantId]);
        }

        $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routers as &$r) {
            $r['online'] = isRouterOnlineWg($r);

            $newStatus = $r['online'] ? 'online' : $r['provisioning_status'];
            if ($newStatus !== $r['provisioning_status']) {
                $stmt = $db->prepare("UPDATE routers SET provisioning_status = :status WHERE id = :id");
                $stmt->execute([':status' => $newStatus, ':id' => $r['id']]);
                $r['provisioning_status'] = $newStatus;
            }
        }

        jsonResponse(['routers' => $routers]);
    }

    if ($action === 'get') {
        $routerId = $_GET['router_id'] ?? null;
        if (!$routerId) jsonResponse(['error' => 'router_id required'], 400);

        $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
        $stmt->execute([':id' => $routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) jsonResponse(['error' => 'Router not found'], 404);

        jsonResponse(['router' => $router]);
    }

    if ($action === 'provision_script') {
        $token = $_GET['token'] ?? '';
        if (empty($token)) {
            jsonResponse(['error' => 'Token required'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM routers WHERE provision_token = :token AND type = 'mikrotik'");
        $stmt->execute([':token' => $token]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$router) {
            jsonResponse(['error' => 'Invalid token'], 404);
        }

        $provData = generateProvisionScript($db, $router['id'], $router['name'], $router['wireguard_ip'], $router['device_id'], $router['service_mode'] ?? 'hotspot');

        if (isset($provData['error'])) {
            jsonResponse(['error' => $provData['error']], 500);
        }

        $stmt = $db->prepare("UPDATE routers SET provisioning_status = 'provisioning', last_provisioned_at = :ts WHERE id = :id");
        $stmt->execute([
            ':ts' => date('Y-m-d H:i:s'),
            ':id' => $router['id'],
        ]);

        header('Content-Type: text/plain');
        echo $provData['script'];
        exit;
    }

    if ($action === 'check_status') {
        $routerId = $_GET['router_id'] ?? null;
        if (!$routerId) {
            jsonResponse(['error' => 'router_id required'], 400);
        }

        if (ctype_digit($routerId)) {
            $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
            $stmt->execute([':id' => $routerId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM routers WHERE (device_id = :id OR provision_token = :id) AND type = 'mikrotik'");
            $stmt->execute([':id' => $routerId]);
        }
        $router = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$router) {
            jsonResponse(['error' => 'Router not found'], 404);
        }

        $isOnline = isRouterOnlineWg($router);

        $newStatus = $isOnline ? 'online' : $router['provisioning_status'];
        if ($newStatus !== $router['provisioning_status']) {
            $stmt = $db->prepare("UPDATE routers SET provisioning_status = :status WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $routerId]);
        }

        jsonResponse([
            'router_id' => (int)$routerId,
            'status' => $newStatus,
            'wireguard_ip' => $router['wireguard_ip'],
        ]);
    }

    if ($action === 'wg_status') {
        $status = tunGuard()->getStatus();
        jsonResponse([
            'success' => !empty($status),
            'wg_output' => $status ? json_encode($status) : '',
        ]);
    }

    if ($action === 'bandwidth') {
        $routerId = $_GET['router_id'] ?? null;
        if (!$routerId) jsonResponse(['error' => 'router_id required'], 400);

        $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
        $stmt->execute([':id' => $routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) jsonResponse(['error' => 'Router not found'], 404);

        $apiIP = !empty($router['wireguard_ip']) ? $router['wireguard_ip'] : $router['ip'];
        $apiPort = intval($router['port'] ?: 8729);

        try {
            $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
            $api->connect();

            $active = $api->getHotspotActiveUsers();
            $api->close();

            jsonResponse(['success' => true, 'active_users' => $active]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'API connection failed: ' . $e->getMessage()], 500);
        }
    }

    if ($action === 'resources') {
        $routerId = $_GET['router_id'] ?? null;
        if (!$routerId) jsonResponse(['error' => 'router_id required'], 400);

        $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
        $stmt->execute([':id' => $routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) jsonResponse(['error' => 'Router not found'], 404);

        $apiIP = !empty($router['wireguard_ip']) ? $router['wireguard_ip'] : $router['ip'];
        $apiPort = intval($router['port'] ?: 8729);

        try {
            $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
            $api->connect();

            $resources = $api->getSystemResources();
            $active = $api->getHotspotActiveUsers();
            $interfaces = $api->getInterfaceStats();
            $api->close();

            jsonResponse([
                'success' => true,
                'resources' => $resources[0] ?? [],
                'active_users' => $active,
                'interfaces' => $interfaces,
            ]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'API connection failed: ' . $e->getMessage()], 500);
        }
    }

    if ($action === 'dashboard_stats') {
        $tenantId = $_SESSION['account_id'] ?? null;
        $role = $_SESSION['role'] ?? null;

        if ($role === 'superadmin') {
            $routers = $db->query("SELECT * FROM routers WHERE type = 'mikrotik' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare("SELECT * FROM routers WHERE type = 'mikrotik' AND tenant_id = :tid ORDER BY id DESC");
            $stmt->execute([':tid' => $tenantId]);
            $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $onlineDevices = 0;
        $totalDevices = count($routers);
        $deviceStats = [];

        foreach ($routers as $r) {
            $isOn = isRouterOnlineWg($r);
            if ($isOn) $onlineDevices++;
            $deviceStats[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'online' => $isOn,
                'location' => $r['location'] ?? '',
                'wireguard_ip' => $r['wireguard_ip'] ?? '',
            ];
        }

        $totalRevenue = $db->query("SELECT COALESCE(SUM(price), 0) FROM vouchers WHERE status = 'used'")->fetchColumn();
        $monthRevenue = $db->query("SELECT COALESCE(SUM(price), 0) FROM vouchers WHERE status = 'used' AND used_at >= date('now', 'start of month')")->fetchColumn();
        $todayRevenue = $db->query("SELECT COALESCE(SUM(price), 0) FROM vouchers WHERE status = 'used' AND date(used_at) = date('now')")->fetchColumn();

        $totalVouchers = $db->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
        $monthVouchers = $db->query("SELECT COUNT(*) FROM vouchers WHERE status = 'used' AND used_at >= date('now', 'start of month')")->fetchColumn();
        $activeVouchers = $db->query("SELECT COUNT(*) FROM vouchers WHERE status = 'active'")->fetchColumn();

        $revenueByMonth = $db->query("
            SELECT strftime('%Y-%m', used_at) as month, SUM(price) as revenue, COUNT(*) as count
            FROM vouchers WHERE status = 'used'
            GROUP BY strftime('%Y-%m', used_at)
            ORDER BY month DESC LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'total_devices' => $totalDevices,
            'online_devices' => $onlineDevices,
            'offline_devices' => $totalDevices - $onlineDevices,
            'devices' => $deviceStats,
            'total_revenue' => floatval($totalRevenue),
            'month_revenue' => floatval($monthRevenue),
            'today_revenue' => floatval($todayRevenue),
            'total_vouchers' => $totalVouchers,
            'month_vouchers' => $monthVouchers,
            'active_vouchers' => $activeVouchers,
            'revenue_by_month' => $revenueByMonth,
        ]);
    }

    if ($action === 'platform_stats') {
        if (($_SESSION['role'] ?? '') !== 'superadmin') {
            jsonResponse(['error' => 'Forbidden'], 403);
        }

        $routers = $db->query("SELECT * FROM routers WHERE type = 'mikrotik' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $onlineDevices = 0;
        foreach ($routers as $r) {
            if (isRouterOnlineWg($r)) $onlineDevices++;
        }

        $totalAdmins = $db->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
        $activeAdmins = $db->query("SELECT COUNT(*) FROM accounts WHERE status = 1")->fetchColumn();
        $disabledAdmins = $totalAdmins - $activeAdmins;

        $totalRevenue = $db->query("SELECT COALESCE(SUM(price), 0) FROM vouchers WHERE status = 'used'")->fetchColumn();
        $monthRevenue = $db->query("SELECT COALESCE(SUM(price), 0) FROM vouchers WHERE status = 'used' AND used_at >= date('now', 'start of month')")->fetchColumn();
        $todayRevenue = $db->query("SELECT COALESCE(SUM(price), 0) FROM vouchers WHERE status = 'used' AND date(used_at) = date('now')")->fetchColumn();

        $totalVouchers = $db->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
        $monthVouchers = $db->query("SELECT COUNT(*) FROM vouchers WHERE status = 'used' AND used_at >= date('now', 'start of month')")->fetchColumn();
        $activeVouchers = $db->query("SELECT COUNT(*) FROM vouchers WHERE status = 'active'")->fetchColumn();

        $revenueByMonth = $db->query("
            SELECT strftime('%Y-%m', used_at) as month, SUM(price) as revenue, COUNT(*) as count
            FROM vouchers WHERE status = 'used'
            GROUP BY strftime('%Y-%m', used_at)
            ORDER BY month DESC LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);

        $tenants = $db->query("
            SELECT a.id, a.name, a.email, a.voucher_limit, a.status, a.created_at,
                   (SELECT COUNT(*) FROM routers r WHERE r.tenant_id = a.id) AS routers_count,
                   (SELECT COUNT(*) FROM vouchers v WHERE v.created_by = a.id) AS vouchers_count,
                   (SELECT COUNT(*) FROM vouchers v WHERE v.created_by = a.id AND v.status = 'used') AS vouchers_used,
                   (SELECT COALESCE(SUM(price), 0) FROM vouchers v WHERE v.created_by = a.id AND v.status = 'used') AS revenue
            FROM accounts a
            ORDER BY revenue DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tenants as &$t) {
            $t['voucher_limit'] = (int)$t['voucher_limit'];
            $t['status'] = (int)$t['status'];
            $t['routers_count'] = (int)$t['routers_count'];
            $t['vouchers_count'] = (int)$t['vouchers_count'];
            $t['vouchers_used'] = (int)$t['vouchers_used'];
            $t['revenue'] = floatval($t['revenue']);
        }

        $recentVouchers = $db->query("
            SELECT v.id, v.code, v.status, v.price, v.used_at, v.created_at, a.name AS account_name
            FROM vouchers v
            LEFT JOIN accounts a ON a.id = v.created_by
            ORDER BY v.created_at DESC, v.id DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'total_admins' => (int)$totalAdmins,
            'active_admins' => (int)$activeAdmins,
            'disabled_admins' => (int)$disabledAdmins,
            'total_routers' => count($routers),
            'online_routers' => $onlineDevices,
            'offline_routers' => count($routers) - $onlineDevices,
            'total_revenue' => floatval($totalRevenue),
            'month_revenue' => floatval($monthRevenue),
            'today_revenue' => floatval($todayRevenue),
            'total_vouchers' => (int)$totalVouchers,
            'month_vouchers' => (int)$monthVouchers,
            'active_vouchers' => (int)$activeVouchers,
            'revenue_by_month' => $revenueByMonth,
            'tenants' => $tenants,
            'recent_vouchers' => $recentVouchers,
        ]);
    }

    jsonResponse(['error' => 'Invalid request'], 400);
}
