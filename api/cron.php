<?php
// api/cron.php - Single worker replacing PM2
// Handles: expired users, router sync, QoS push
// Triggered by web UI or app, uses lock file to prevent overlapping runs

header('Content-Type: application/json');

$dbPath = __DIR__ . '/../db/routers.db';
$lockFile = sys_get_temp_dir() . '/wifibilling_cron.lock';
$statusFile = sys_get_temp_dir() . '/wifibilling_cron_status.json';

$action = $_GET['action'] ?? 'run';

// -------------------------
// STATUS CHECK
// -------------------------
if ($action === 'status') {
    $status = ['running' => false, 'last_run' => null, 'last_result' => null];
    if (file_exists($statusFile)) {
        $status = json_decode(file_get_contents($statusFile), true) ?: $status;
    }
    $status['running'] = file_exists($lockFile);
    echo json_encode(['success' => true, 'status' => $status]);
    exit;
}

// -------------------------
// STOP
// -------------------------
if ($action === 'stop') {
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
    echo json_encode(['success' => true, 'message' => 'Worker stopped']);
    exit;
}

// -------------------------
// RUN
// -------------------------

// Check if already running
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 300) {
        echo json_encode(['success' => false, 'error' => 'Worker already running', 'lock_age_seconds' => $lockAge]);
        exit;
    }
    // Lock is stale (>5 min), remove it
    @unlink($lockFile);
}

// Create lock
file_put_contents($lockFile, getmypid());

// Ensure cleanup on exit
register_shutdown_function(function () use ($lockFile) {
    if (file_exists($lockFile)) @unlink($lockFile);
});

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/../db/schema.php';

    $results = [];

    // ==========================
    // STEP 1: Mark expired users
    // ==========================
    $expiredStmt = $db->prepare("
        SELECT u.id, u.mac, u.router_id, u.hostname, b.end_at
        FROM users u
        JOIN billing b ON u.mac = b.mac AND u.router_id = b.router_id
        WHERE b.end_at <= datetime('now', 'localtime')
          AND u.internet_access = 1
    ");
    $expiredStmt->execute();
    $expiredUsers = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

    $expiredCount = 0;
    if (!empty($expiredUsers)) {
        foreach ($expiredUsers as $user) {
            $db->prepare("UPDATE users SET internet_access = 0 WHERE id = ?")->execute([$user['id']]);
            $db->prepare("UPDATE billing SET internet_access = 0 WHERE mac = ? AND router_id = ?")
               ->execute([$user['mac'], $user['router_id']]);
            $expiredCount++;
        }
    }

    $results['expired'] = [
        'count' => $expiredCount,
        'message' => $expiredCount > 0 ? "$expiredCount users expired" : "No expired users"
    ];

    // ==========================
    // STEP 1b: Expire MikroTik hotspot users
    // RouterOS only disconnects sessions when limit-uptime runs out and keeps
    // the /ip/hotspot/user record forever, so we remove + disconnect from here
    // once the voucher's plan window (used_at + duration) is over.
    // ==========================
    require_once __DIR__ . '/mikrotik_api.php';

    $usedVouchersStmt = $db->query("
        SELECT v.id, v.code, v.router_id, v.used_at, v.expires_at,
               (p.days * 86400 + p.hours * 3600 + p.minutes * 60) AS plan_seconds
        FROM vouchers v
        JOIN plans p ON v.plan_id = p.id
        JOIN routers r ON v.router_id = r.id
        WHERE v.status = 'used' AND r.type = 'mikrotik'
    ");
    $usedVouchers = $usedVouchersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group all used vouchers by router so we can resolve each router's tenant
    // timezone before computing expiry (used_at is stored in local clock time).
    $vouchersByRouter = [];
    foreach ($usedVouchers as $v) {
        $vouchersByRouter[(int)$v['router_id']][] = $v;
    }

    $mtkResults = [];
    foreach ($vouchersByRouter as $routerId => $vouchers) {
        $rStmt = $db->prepare("SELECT * FROM routers WHERE id = ?");
        $rStmt->execute([$routerId]);
        $router = $rStmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) continue;

        // Resolve tenant timezone: used_at/expires_at are stored in the tenant's
        // local clock, so parse them with that timezone or expiry shifts by the
        // UTC offset (e.g. Africa/Dar_es_Salaam = UTC+3 -> 3h late).
        $routerTz = 'UTC';
        if (!empty($router['tenant_id'])) {
            $tzStmt = $db->prepare("SELECT timezone FROM accounts WHERE id = ?");
            $tzStmt->execute([$router['tenant_id']]);
            $tz = $tzStmt->fetchColumn();
            if ($tz) $routerTz = $tz;
        }
        date_default_timezone_set($routerTz);
        $now = time();

        // A used voucher is expired when its plan window (used_at + duration) is
        // over - regardless of whether the user actually connected. Fall back to
        // expires_at only when used_at/plan are not available.
        $expiredVouchers = [];
        foreach ($vouchers as $v) {
            $isExpired = false;
            if ((int)$v['plan_seconds'] > 0 && !empty($v['used_at'])) {
                $isExpired = (strtotime($v['used_at']) + (int)$v['plan_seconds']) <= $now;
            } elseif (!empty($v['expires_at'])) {
                $isExpired = strtotime($v['expires_at']) <= $now;
            }
            if ($isExpired) $expiredVouchers[] = $v;
        }

        // Persist expiry: mark the voucher 'expired' and correct expires_at to
        // the real plan end so past_users displays it and we don't re-scan it.
        $markStmt = $db->prepare("UPDATE vouchers SET status = 'expired', expires_at = ? WHERE id = ?");

        if (empty($expiredVouchers)) {
            $mtkResults[] = [
                'router_id' => $routerId,
                'name' => $router['name'],
                'status' => 'cleaned',
                'expired' => 0,
                'removed' => 0,
                'disconnected' => 0,
                'failed' => [],
            ];
            continue;
        }

        $apiIP = !empty($router['wireguard_ip']) && $router['wireguard_ip'] !== '0.0.0.0' ? $router['wireguard_ip'] : $router['ip'];
        $apiPort = intval($router['port'] ?: 8728);
        $removed = 0;
        $disconnected = 0;
        $failed = [];

        try {
            $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
            $api->connect();

            // Fetch router's current user records + active sessions ONCE,
            // then only issue remove commands for codes actually present.
            $userIdsByName = [];
            foreach ($api->getHotspotUsers() as $u) {
                $name = $u['name'] ?? '';
                if ($name !== '' && isset($u['.id'])) $userIdsByName[$name] = $u['.id'];
            }
            $activeIdsByName = [];
            foreach ($api->getHotspotActiveUsers() as $a) {
                $name = $a['user'] ?? '';
                if ($name !== '' && isset($a['.id'])) $activeIdsByName[$name] = $a['.id'];
            }

            foreach ($expiredVouchers as $v) {
                $code = $v['code'];
                try {
                    if (isset($userIdsByName[$code])) {
                        $api->command(['/ip/hotspot/user/remove', '=.id=' . $userIdsByName[$code]]);
                        $removed++;
                    }
                    if (isset($activeIdsByName[$code])) {
                        $api->command(['/ip/hotspot/active/remove', '=.id=' . $activeIdsByName[$code]]);
                        $disconnected++;
                    }
                } catch (Exception $e) {
                    $failed[] = $code;
                }
            }

            $api->close();
        } catch (Exception $e) {
            $mtkResults[] = [
                'router_id' => $routerId,
                'name' => $router['name'],
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
            continue;
        }

        // Router pass succeeded: persist the expiry now so past_users shows the
        // real plan end and we don't re-scan these vouchers on every run.
        foreach ($expiredVouchers as $v) {
            $planEnd = (int)$v['plan_seconds'] > 0 && !empty($v['used_at'])
                ? strtotime($v['used_at']) + (int)$v['plan_seconds']
                : strtotime($v['expires_at']);
            $markStmt->execute([date('Y-m-d H:i:s', $planEnd), $v['id']]);
        }

        $mtkResults[] = [
            'router_id' => $routerId,
            'name' => $router['name'],
            'status' => 'cleaned',
            'expired' => count($expiredVouchers),
            'removed' => $removed,
            'disconnected' => $disconnected,
            'failed' => $failed,
        ];
    }

    // Restore a stable timezone for the rest of the worker (status/date output).
    date_default_timezone_set('UTC');

    $results['mikrotik_expired'] = $mtkResults;

    // ==========================
    // STEP 2: Sync routers + push QoS
    // ==========================
    require_once __DIR__ . '/../auth/config.php';

    function cron_curl_post($url, $data, $cookie, $referer = null) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => "Mozilla/5.0"
        ]);
        $res = curl_exec($ch);
        if ($res === false) throw new Exception('cURL POST error: ' . curl_error($ch));
        curl_close($ch);
        return $res;
    }

    function cron_curl_get($url, $cookie) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => "Mozilla/5.0"
        ]);
        $res = curl_exec($ch);
        if ($res === false) throw new Exception('cURL GET error: ' . curl_error($ch));
        curl_close($ch);
        return $res;
    }

    function cron_is_online($ip, $port = 80, $timeout = 2) {
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($fp) { fclose($fp); return true; }
        return false;
    }

    // STEP 2 only applies to Tenda routers (QoS sync over HTTP).
    // MikroTik routers are handled via the API in STEP 1b, so skip them here.
    $routers = $db->query("SELECT * FROM routers WHERE type != 'mikrotik' OR type IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $routerResults = [];

    foreach ($routers as $routerData) {
        $routerId = $routerData['id'];
        $ip       = $routerData['ip'];
        $port     = $routerData['port'] ?: 80;
        $password = $routerData['password'];
        $routerUrl = "http://$ip" . ($port != 80 ? ":$port" : "");

        try {
            if (!cron_is_online($ip, $port)) {
                $routerResults[] = ['router_id' => $routerId, 'name' => $routerData['name'], 'status' => 'offline'];
                continue;
            }

            $cookie = createCookieFile();

            // Login to router
            cron_curl_post("$routerUrl/login/Auth", ["password" => base64_encode($password)], $cookie);

            // Fetch online devices
            $qosJson = cron_curl_get("$routerUrl/goform/getQos?random=" . microtime(true) . "&modules=onlineList,blackList", $cookie);
            $qosData = json_decode($qosJson, true) ?: [];

            // Sync new devices to DB
            $online = $qosData['onlineList'] ?? [];
            foreach ($online as $dev) {
                $mac = strtoupper($dev['qosListMac'] ?? '');
                if (!$mac) continue;
                $hostname = $dev['qosListHostname'] ?? 'unknown';
                $ipAddr = $dev['qosListIP'] ?? '';

                $check = $db->prepare("SELECT id FROM users WHERE mac = ? AND router_id = ?");
                $check->execute([$mac, $routerId]);
                if (!$check->fetch()) {
                    $db->prepare("INSERT INTO users (hostname, ip, mac, router_id, internet_access, connected_at) VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)")
                       ->execute([$hostname, $ipAddr, $mac, $routerId]);
                } else {
                    $db->prepare("UPDATE users SET hostname = ?, ip = ? WHERE mac = ? AND router_id = ?")
                       ->execute([$hostname, $ipAddr, $mac, $routerId]);
                }
            }

            // Get all users for this router and build QoS list
            $stmt = $db->prepare("SELECT * FROM users WHERE router_id = ?");
            $stmt->execute([$routerId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $onlineList = [];
            foreach ($users as $u) {
                $mac = strtoupper($u['mac']);
                $hostname = $u['hostname'] ?: 'unknown';
                $upLimit = ((int)$u['internet_access'] === 1) ? 10240 : 1;
                $downLimit = $upLimit;
                $onlineList[] = "$hostname\t$hostname\t$mac\t$upLimit\t$downLimit\ttrue";
            }

            // Push QoS to router
            if (!empty($onlineList)) {
                cron_curl_post("$routerUrl/goform/setQos", [
                    'module1'       => 'onlineList',
                    'onlineList'    => implode("\n", $onlineList),
                    'onlineListLen' => count($onlineList),
                    'qosEn'         => '1',
                    'qosAccessEn'   => '1'
                ], $cookie, "$routerUrl/index.html");

                cron_curl_post("$routerUrl/goform/save", ['random' => time()], $cookie, "$routerUrl/index.html");
            }

            if (file_exists($cookie)) @unlink($cookie);

            $throttled = count(array_filter($users, fn($x) => (int)$x['internet_access'] === 0));
            $routerResults[] = [
                'router_id'   => $routerId,
                'name'        => $routerData['name'],
                'status'      => 'synced',
                'devices'     => count($users),
                'throttled'   => $throttled
            ];

            // Update last_run
            $db->prepare("UPDATE routers SET last_run = datetime('now', 'localtime') WHERE id = ?")->execute([$routerId]);

        } catch (Exception $e) {
            $routerResults[] = ['router_id' => $routerId, 'name' => $routerData['name'], 'status' => 'error', 'error' => $e->getMessage()];
        }
    }

    $results['routers'] = $routerResults;

    // ==========================
    // STEP 3: Send SMS expiry reminders for MikroTik vouchers
    // ==========================
    try {
        require_once __DIR__ . '/../services/sms.php';
        $reminderResult = sendExpiryReminders($db);
        $results['sms_reminders'] = $reminderResult;
    } catch (Exception $e) {
        $results['sms_reminders'] = ['sent' => 0, 'error' => $e->getMessage()];
    }

    // Save status
    $status = [
        'running'     => false,
        'last_run'    => date('Y-m-d H:i:s'),
        'last_result' => $results
    ];
    file_put_contents($statusFile, json_encode($status));

    echo json_encode(['success' => true, 'results' => $results]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (file_exists($lockFile)) @unlink($lockFile);
}
