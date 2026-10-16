<?php
ob_start();
$pageTitle = 'Users';
$activePage = 'users';

// Include header and sidebar
include __DIR__ . '/../components/header.php';

// Connect to SQLite Database
$db = new PDO('sqlite:' . __DIR__ . '/../db/routers.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Tenant scope
$tenantId = $_SESSION['account_id'] ?? null;
$isAdmin  = ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin');

// Fetch routers and plans scoped by tenant
$routerCols = "id, name, type, wireguard_ip, ip, port, password, provisioning_status, service_mode";
if ($isAdmin) {
    $routers = $db->query("SELECT $routerCols FROM routers")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("SELECT $routerCols FROM routers WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$plans = $db->query("SELECT * FROM plans")->fetchAll(PDO::FETCH_ASSOC);

// Function to format byte counts
function fmtBytes($b) {
    $b = (float)$b;
    if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024, 1) . ' KB';
    return $b . ' B';
}

// Normalize a MAC address to a canonical uppercase hex string (e.g. AABBCCDDEEFF)
function normalizeMac($mac) {
    return strtoupper(preg_replace('/[^A-F0-9]/', '', str_replace(':', '', $mac)));
}

// Sync ALL users from a MikroTik router into the server database.
// Pulls both the currently connected sessions (active) and every user record
// on the router (dead/expired included) and stores the snapshot in the
// `hotspot_users` table. Returns the live sessions plus the stored users.
function syncMikrotikUsers($db, $router) {
    $mode = ($router['service_mode'] ?? 'hotspot') === 'pppoe' ? 'pppoe' : 'hotspot';

    if (($router['provisioning_status'] ?? 'offline') !== 'online') {
        return ['ok' => false, 'mode' => $mode, 'offline' => true];
    }

    require_once __DIR__ . '/../api/mikrotik_api.php';

    $apiIP = !empty($router['wireguard_ip']) && $router['wireguard_ip'] !== '0.0.0.0' ? $router['wireguard_ip'] : $router['ip'];
    $apiPort = intval($router['port'] ?: 8729);

    try {
        $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
        $api->connect();

        if ($mode === 'pppoe') {
            $sessions = $api->getPppActiveUsers();
            $allUsers = $api->getPppSecrets();
        } else {
            $sessions = $api->getHotspotActiveUsers();
            $allUsers = $api->getHotspotUsers();
        }
        $api->close();

        // Usernames with a live session right now
        $activeNames = [];
        foreach ($sessions as $s) {
            $n = $s['user'] ?? $s['name'] ?? '';
            if ($n !== '') $activeNames[$n] = true;
        }

        $now = date('Y-m-d H:i:s');
        $upsert = $db->prepare("
            INSERT INTO hotspot_users
                (router_id, username, status, profile, limit_uptime, uptime, bytes_in, bytes_out, disabled, comment, first_seen, last_seen, last_sync)
            VALUES
                (:router_id, :username, :status, :profile, :limit_uptime, :uptime, :bytes_in, :bytes_out, :disabled, :comment, :now, :now, :now)
            ON CONFLICT(router_id, username) DO UPDATE SET
                status       = excluded.status,
                profile      = excluded.profile,
                limit_uptime = excluded.limit_uptime,
                uptime       = excluded.uptime,
                bytes_in     = excluded.bytes_in,
                bytes_out    = excluded.bytes_out,
                disabled     = excluded.disabled,
                comment      = excluded.comment,
                last_seen    = excluded.last_seen,
                last_sync    = excluded.last_sync
        ");

        $stored = [];
        foreach ($allUsers as $u) {
            $username = $u['name'] ?? $u['user'] ?? '';
            if ($username === '') continue;

            $disabled = ($u['disabled'] ?? 'false') === 'true' || ($u['disabled'] ?? 'no') === 'yes' ? 1 : 0;
            $status = isset($activeNames[$username]) ? 'active' : ($disabled ? 'disabled' : 'dead');

            $upsert->execute([
                ':router_id'   => $router['id'],
                ':username'    => $username,
                ':status'      => $status,
                ':profile'     => $u['profile'] ?? null,
                ':limit_uptime'=> $u['limit-uptime'] ?? null,
                ':uptime'      => $u['uptime'] ?? null,
                ':bytes_in'    => (int)($u['bytes-in'] ?? 0),
                ':bytes_out'   => (int)($u['bytes-out'] ?? 0),
                ':disabled'    => $disabled,
                ':comment'     => $u['comment'] ?? null,
                ':now'         => $now,
            ]);

            $stored[] = [
                'username'      => $username,
                'status'        => $status,
                'profile'       => $u['profile'] ?? null,
                'limit_uptime'  => $u['limit-uptime'] ?? null,
                'uptime'        => $u['uptime'] ?? null,
                'bytes_in'      => (int)($u['bytes-in'] ?? 0),
                'bytes_out'     => (int)($u['bytes-out'] ?? 0),
                'disabled'      => $disabled,
                'comment'       => $u['comment'] ?? null,
            ];
        }

        // Update routers.last_sync marker
        $db->prepare("UPDATE routers SET last_sync = :t WHERE id = :id")
            ->execute([':t' => time(), ':id' => $router['id']]);

        return [
            'ok'       => true,
            'mode'     => $mode,
            'sessions' => $sessions,
            'users'    => $stored,
        ];
    } catch (Exception $e) {
        return ['ok' => false, 'mode' => $mode, 'error' => $e->getMessage()];
    }
}

// Function to format remaining time
function formatRemainingTime($seconds) {
    if ($seconds <= 0) return "0d 0h 0m 0s";
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return "{$days}d {$hours}h {$minutes}m {$secs}s";
}

// Parse a MikroTik duration string ("26m7s", "1h23m", "2d03:45:12") into seconds
function parseMikrotikDuration($str) {
    $str = trim((string)$str);
    if ($str === '' || $str === '-' || $str === '0' || $str === '0s' || $str === '0m') return null;

    // Colon formats: "HH:MM:SS" or "XdHH:MM:SS"
    if (preg_match('/^(\d+)d\s*(\d+):(\d+):(\d+)$/i', $str, $m)) {
        return ((int)$m[1] * 86400) + ((int)$m[2] * 3600) + ((int)$m[3] * 60) + (int)$m[4];
    }
    if (preg_match('/^(\d+):(\d+):(\d+)$/', $str, $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
    }

    // Unit style: "26m7s", "1h23m", "1w2d3h4m5s"
    $sec = 0;
    if (preg_match_all('/(\d+)\s*(w|d|h|m|s)/i', $str, $m, PREG_SET_ORDER)) {
        foreach ($m as $t) {
            $v = (int)$t[1];
            switch (strtolower($t[2])) {
                case 'w': $sec += $v * 604800; break;
                case 'd': $sec += $v * 86400; break;
                case 'h': $sec += $v * 3600; break;
                case 'm': $sec += $v * 60; break;
                case 's': $sec += $v; break;
            }
        }
        return $sec;
    }
    return null;
}

// Timing for a connected MikroTik session. When the username is a voucher
// code, derive everything from the plan: used time since used_at, the ending
// time (used_at + plan duration) and the remaining time. Falls back to the
// router's own session-time-left when there is no matching voucher.
function getMikrotikSessionTiming($db, $routerId, $session) {
    $now = time();
    $username = $session['user'] ?? $session['name'] ?? null;

    $routerLeft = parseMikrotikDuration($session['session-time-left'] ?? '');

    if ($username !== null) {
        $stmt = $db->prepare("
            SELECT v.used_at, p.days, p.hours, p.minutes
            FROM vouchers v
            LEFT JOIN plans p ON v.plan_id = p.id
            WHERE v.code = ? AND v.router_id = ? AND v.status = 'used'
            ORDER BY v.used_at DESC LIMIT 1
        ");
        $stmt->execute([$username, $routerId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($v && $v['used_at']) {
            $total = ((int)$v['days'] * 86400) + ((int)$v['hours'] * 3600) + ((int)$v['minutes'] * 60);
            $startTs = strtotime($v['used_at']);
            if ($total > 0 && $startTs) {
                $endTs = $startTs + $total;
                return [
                    'start_ts'          => $startTs,
                    'end_ts'            => $endTs,
                    'used_seconds'      => max(0, $now - $startTs),
                    'remaining_seconds' => max(0, $endTs - $now),
                    'source'            => 'plan',
                ];
            }
        }
    }

    if ($routerLeft !== null) {
        return [
            'start_ts'          => null,
            'end_ts'            => $now + $routerLeft,
            'used_seconds'      => null,
            'remaining_seconds' => $routerLeft,
            'source'            => 'router',
        ];
    }

    return [
        'start_ts'          => null,
        'end_ts'            => null,
        'used_seconds'      => null,
        'remaining_seconds' => null,
        'source'            => null,
    ];
}

// Fetch every device the router knows about: whitelist (allowed/active),
// blacklist (blocked/expired/dead) and online clients.
// Reaches the local /auth/v2.php API over HTTP(S) using the current host and
// forwards the browser session so the request is scoped to the logged-in tenant.
function getRouterDevices($routerId) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = "$scheme://$host/auth/v2.php";
    $payload = json_encode([
        "action" => "get_users",
        "router_id" => $routerId
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_COOKIE => session_name() . '=' . session_id(),
        CURLOPT_TIMEOUT => 10
    ]);

    // The sub-request needs to read this same PHP session (for tenant scoping),
    // so release the session lock before calling it or v2.php would block.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $res = curl_exec($ch);
    curl_close($ch);

    // Re-acquire the session lock for the rest of the page.
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    if ($res === false) {
        return ['whitelist' => [], 'blacklist' => [], 'online' => []];
    }

    $data = json_decode($res, true);
    $result = $data['results'][0] ?? [];

    return [
        'whitelist' => $result['whitelist'] ?? [],
        'blacklist' => $result['blacklist'] ?? [],
        'online'    => $result['online_clients'] ?? []
    ];
}

// Fetch whitelist MACs only (used by sync)
function getRouterWhitelist($routerId) {
    return array_keys(getRouterDevices($routerId)['whitelist']);
}

// Handle POST actions (plan change, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['user_id'], $_POST['new_plan_id'])) {
        $userId = $_POST['user_id'];
        $newPlanId = $_POST['new_plan_id'];

        // Fetch the selected plan
        $newPlanStmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
        $newPlanStmt->execute([$newPlanId]);
        $newPlan = $newPlanStmt->fetch(PDO::FETCH_ASSOC);

        $durationInSeconds = ($newPlan['days'] ?? 0) * 86400
                           + ($newPlan['hours'] ?? 0) * 3600
                           + ($newPlan['minutes'] ?? 0) * 60;

        // Fetch user's created_at
        $userStmt = $db->prepare("SELECT created_at FROM billing WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $createdAt = strtotime($user['created_at']);

        // Calculate end_at
        $endAt = date('Y-m-d H:i:s', $createdAt + $durationInSeconds);

        // Update billing table and restore internet access
        $updateStmt = $db->prepare("UPDATE billing SET plan_id = ?, remaining_time = ?, end_at = ?, internet_access = 1 WHERE id = ?");
        $updateStmt->execute([$newPlanId, $durationInSeconds, $endAt, $userId]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['delete_user_id'])) {
        $deleteUserId = $_POST['delete_user_id'];
        $deleteStmt = $db->prepare("DELETE FROM billing WHERE id = ?");
        $deleteStmt->execute([$deleteUserId]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Sync Logic
    if (isset($_POST['sync_router_id'])) {
        $routerId = $_POST['sync_router_id'];
        $stmtRouter = $db->prepare("SELECT * FROM routers WHERE id = ?");
        $stmtRouter->execute([$routerId]);
        $router = $stmtRouter->fetch(PDO::FETCH_ASSOC);

        if ($router) {
            // Fetch whitelist and compare
            $whitelistMacs = array_map(function($mac) {
                return strtoupper(preg_replace('/[^A-F0-9]/', '', str_replace(':', '', $mac)));
            }, getRouterWhitelist($router['id']));

            $stmt = $db->prepare("
                SELECT b.*, p.name AS plan_name, p.days, p.hours, p.minutes
                FROM billing b
                JOIN plans p ON b.plan_id = p.id
                WHERE b.router_id = ?
                ORDER BY b.created_at DESC
            ");
            $stmt->execute([$router['id']]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recalculate DB MACs
            $dbMacs = array_map(function($u) {
                return strtoupper(preg_replace('/[^A-F0-9]/', '', str_replace(':', '', $u['mac'])));
            }, $users);

            $missingInDb = array_diff($whitelistMacs, $dbMacs);
            $extraInDb   = array_diff($dbMacs, $whitelistMacs);

            //  Add missing
            foreach ($missingInDb as $mac) {
                $stmtCheck = $db->prepare("SELECT COUNT(*) FROM billing WHERE mac = ? AND router_id = ?");
                $stmtCheck->execute([$mac, $router['id']]);

                if ($stmtCheck->fetchColumn() == 0) {
                    $stmtInsert = $db->prepare("
                        INSERT INTO billing 
                        (name, phone_number, mac, router_id, plan_id, created_at, end_at, internet_access)
                        VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'), 1)
                    ");
                    $stmtInsert->execute([
                        'Auto-Added Device',
                        '',
                        $mac,
                        $router['id'],
                        1
                    ]);
                }
            }

            //  Disable extra (DO NOT DELETE)
            foreach ($extraInDb as $mac) {
                $stmtUpdate = $db->prepare("
                    UPDATE billing 
                    SET internet_access = 0 
                    WHERE mac = ? AND router_id = ?
                ");
                $stmtUpdate->execute([$mac, $router['id']]);
            }

            //  Reload page after sync
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Users by Router</h1>
        <p class="page-subtitle">
            <span id="time"></span> <span id="ampm"></span> &middot; <span id="date"></span>
        </p>
    </div>
</div>

<?php foreach ($routers as $router): ?>
    <?php if (($router['type'] ?? 'tenda') === 'mikrotik'):
        $mtk = syncMikrotikUsers($db, $router);
        $mtkStatus = $router['provisioning_status'] ?? 'offline';
        $mtkChip = $mtkStatus === 'online' ? 'active' : ($mtkStatus === 'provisioning' || $mtkStatus === 'pending' ? 'pending' : 'inactive');
        $mtkChipLabel = $mtkStatus === 'provisioning' || $mtkStatus === 'pending' ? 'pending' : $mtkStatus;
    ?>
    <div class="card" style="margin-bottom:24px">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-router"></i> <?php echo htmlspecialchars($router['name']); ?>
                    <span class="chip <?php echo $mtkChip; ?>"><span class="chip-dot"></span><?php echo htmlspecialchars($mtkChipLabel); ?></span>
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-outline btn-sm mtk-cleanup-btn"><i class="fas fa-broom"></i> Run cleanup</button>
                    <button class="btn btn-outline btn-sm" onclick="location.reload()"><i class="fas fa-sync"></i> Refresh</button>
                </div>
            </div>
        <div class="card-body">
            <?php if (!$mtk['ok']): ?>
                <div class="alert alert-warning" style="margin:16px">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php if (!empty($mtk['offline'])): ?>
                        Router is offline — no connected users available.
                    <?php else: ?>
                        Could not reach this router: <?php echo htmlspecialchars($mtk['error'] ?? 'unknown error'); ?>
                    <?php endif; ?>
                </div>
            <?php else:
                $mtkSessions = $mtk['sessions'] ?? []; ?>
                <div class="alert alert-info" style="margin:16px">
                    <i class="fas fa-plug"></i>
                    <strong><?php echo count($mtkSessions); ?> connected user(s)</strong> via <?php echo $mtk['mode'] === 'pppoe' ? 'PPPoE' : 'Hotspot'; ?> RADIUS
                </div>
                <?php if ($mtkSessions): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>IP Address</th>
                                    <th>MAC / Caller ID</th>
                                    <th>Uptime</th>
                                    <th>Used Time</th>
                                    <th>Remaining Time</th>
                                    <th>Ends At</th>
                                    <th>Traffic In</th>
                                    <th>Traffic Out</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mtkSessions as $s):
                                    $tm = getMikrotikSessionTiming($db, $router['id'], $s); ?>
                                <tr>
                                    <td style="font-weight:500"><?php echo htmlspecialchars($s['user'] ?? $s['name'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($s['address'] ?? '—'); ?></td>
                                    <td><code><?php echo htmlspecialchars($s['mac-address'] ?? $s['caller-id'] ?? '—'); ?></code></td>
                                    <td><?php echo htmlspecialchars($s['uptime'] ?? '—'); ?></td>
                                    <td>
                                        <?php if ($tm['used_seconds'] !== null): ?>
                                            <span class="time-used" data-live="1" data-start="<?php echo $tm['start_ts']; ?>"><?php echo formatRemainingTime($tm['used_seconds']); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--on-surface-med)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($tm['remaining_seconds'] !== null): ?>
                                            <span class="remaining-time" data-remain-until="<?php echo time() + $tm['remaining_seconds']; ?>"><?php echo formatRemainingTime($tm['remaining_seconds']); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--on-surface-med)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($tm['end_ts']): ?>
                                            <span title="<?php echo htmlspecialchars(date('Y-m-d H:i:s', $tm['end_ts'])); ?>"><?php echo date('D, M j Y H:i', $tm['end_ts']); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--on-surface-med)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(fmtBytes($s['bytes-in'] ?? 0)); ?></td>
                                    <td><?php echo htmlspecialchars(fmtBytes($s['bytes-out'] ?? 0)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:40px">
                        <div class="empty-state-icon"><i class="fas fa-wifi"></i></div>
                        <h3>No connected users</h3>
                        <p>No active sessions right now on this router.</p>
                    </div>
                <?php endif; ?>

                <?php $mtkUsers = $mtk['users'] ?? [];
                if ($mtkUsers):
                    $actN = $deadN = $disN = 0;
                    foreach ($mtkUsers as $mu) {
                        if ($mu['status'] === 'active') $actN++;
                        elseif ($mu['status'] === 'disabled') $disN++;
                        else $deadN++;
                    } ?>
                    <div style="border-top:1px solid var(--border);margin-top:16px;padding-top:16px">
                        <div style="display:flex;align-items:center;gap:8px;margin:0 16px 8px">
                            <i class="fas fa-database"></i>
                            <strong>All Users Stored on Server</strong>
                            <span style="color:var(--on-surface-med);font-size:13px">(<?php echo count($mtkUsers); ?> total &middot; <?php echo $actN; ?> active &middot; <?php echo $deadN; ?> dead &middot; <?php echo $disN; ?> disabled)</span>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Status</th>
                                        <th>Profile</th>
                                        <th>Limit Uptime</th>
                                        <th>Uptime</th>
                                        <th>Traffic In</th>
                                        <th>Traffic Out</th>
                                        <th>Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mtkUsers as $mu):
                                        $muChip = $mu['status'] === 'active' ? 'active' : ($mu['status'] === 'disabled' ? 'inactive' : 'expired');
                                        $muLabel = ucfirst($mu['status']); ?>
                                    <tr>
                                        <td style="font-weight:500"><?php echo htmlspecialchars($mu['username']); ?></td>
                                        <td><span class="chip <?php echo $muChip; ?>"><span class="chip-dot"></span><?php echo $muLabel; ?></span></td>
                                        <td><?php echo htmlspecialchars($mu['profile'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($mu['limit_uptime'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($mu['uptime'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars(fmtBytes($mu['bytes_in'] ?? 0)); ?></td>
                                        <td><?php echo htmlspecialchars(fmtBytes($mu['bytes_out'] ?? 0)); ?></td>
                                        <td><?php echo htmlspecialchars($mu['comment'] ?? '—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php continue; endif; ?>

    <div class="card" style="margin-bottom:24px">
        <div class="card-header">
            <div class="card-title">
                <?php echo htmlspecialchars($router['name']); ?>
            </div>
            <form method="POST" style="margin:0">
                <input type="hidden" name="sync_router_id" value="<?php echo $router['id']; ?>">
                <button class="btn btn-outline btn-sm">Sync Router</button>
            </form>
        </div>

        <?php
        $now = time();

        // All billing records for this router (active, disabled and expired)
        $stmt = $db->prepare("
            SELECT b.*, p.name AS plan_name, p.days, p.hours, p.minutes
            FROM billing b
            LEFT JOIN plans p ON b.plan_id = p.id
            WHERE b.router_id = ?
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$router['id']]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Used vouchers for this router (linked to a device by used_mac)
        $stmtV = $db->prepare("
            SELECT v.*, p.name AS plan_name, p.days, p.hours, p.minutes
            FROM vouchers v
            LEFT JOIN plans p ON v.plan_id = p.id
            WHERE v.router_id = ? AND v.status = 'used' AND v.used_mac IS NOT NULL AND v.used_mac != ''
        ");
        $stmtV->execute([$router['id']]);
        $vouchers = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        // Every device the router currently knows about
        $routerDevices = getRouterDevices($router['id']);
        $wlByNorm = [];
        foreach ($routerDevices['whitelist'] as $m => $host) $wlByNorm[normalizeMac($m)] = ['mac' => $m, 'host' => $host];
        $blByNorm = [];
        foreach ($routerDevices['blacklist'] as $m => $host) $blByNorm[normalizeMac($m)] = ['mac' => $m, 'host' => $host];

        // Index billing + vouchers by normalized MAC
        $billingByMac = [];
        foreach ($users as $u) $billingByMac[normalizeMac($u['mac'])] = $u;
        $voucherByMac = [];
        foreach ($vouchers as $v) $voucherByMac[normalizeMac($v['used_mac'])] = $v;

        $dbMacs = array_keys($billingByMac);
        $whitelistMacs = array_keys($wlByNorm);
        $blacklistMacs = array_keys($blByNorm);
        $missingInDb = array_diff($whitelistMacs, $dbMacs);
        $extraInDb   = array_diff($dbMacs, $whitelistMacs);

        // Merge everything into one list: DB users + whitelist-only + blacklist-only
        $rows = [];
        $seenMacs = [];

        foreach ($users as $u) {
            $norm = normalizeMac($u['mac']);
            $v = $voucherByMac[$norm] ?? null;
            $endTs = $u['end_at'] ? strtotime($u['end_at']) : null;
            $startTs = ($v && $v['used_at']) ? strtotime($v['used_at']) : strtotime($u['created_at']);
            $startTs = $startTs ?: $now;
            $isActive = (int)$u['internet_access'] === 1 && $endTs && $endTs > $now;
            $isExpired = $endTs && $endTs <= $now;
            $isDisabled = !$isActive && !$isExpired && (int)$u['internet_access'] === 0;

            if ($isActive) {
                $usedSec = max(0, $now - $startTs);
                $remainSec = max(0, $endTs - $now);
                $chip = 'active'; $label = 'Active';
            } elseif ($isExpired) {
                $usedSec = max(0, $endTs - $startTs);
                $remainSec = 0;
                $chip = 'expired'; $label = 'Expired';
            } elseif ($isDisabled) {
                $usedSec = $startTs ? max(0, ($endTs ? min($now, $endTs) : $now) - $startTs) : null;
                $remainSec = 0;
                $chip = 'inactive'; $label = 'Disabled';
            } else {
                $usedSec = $startTs ? max(0, $now - $startTs) : null;
                $remainSec = 0;
                $chip = 'inactive'; $label = 'Inactive';
            }

            $rows[] = [
                'billing_id'   => $u['id'],
                'mac'          => $u['mac'],
                'name'         => $u['name'] ?: ($v['customer_name'] ?? ($wlByNorm[$norm]['host'] ?? $blByNorm[$norm]['host'] ?? 'Unknown')),
                'phone_number' => $u['phone_number'] ?: ($v['phone'] ?? ''),
                'plan_id'      => $u['plan_id'],
                'plan_name'    => $u['plan_name'] ?: ($v['plan_name'] ?? null),
                'days'         => $u['days'] ?? ($v['days'] ?? 0),
                'hours'        => $u['hours'] ?? ($v['hours'] ?? 0),
                'minutes'      => $u['minutes'] ?? ($v['minutes'] ?? 0),
                'used_at'      => $v['used_at'] ?? null,
                'created_at'   => $u['created_at'],
                'end_at'       => $u['end_at'],
                'start_ts'     => $startTs,
                'used_seconds' => $usedSec,
                'remaining_seconds' => $remainSec,
                'is_active'    => $isActive,
                'chip'         => $chip,
                'status_label' => $label,
            ];
            $seenMacs[$norm] = true;
        }

        // Devices on the router whitelist but not in the DB (on router, never billed)
        foreach ($wlByNorm as $norm => $wl) {
            if (isset($seenMacs[$norm])) continue;
            $seenMacs[$norm] = true;
            $v = $voucherByMac[$norm] ?? null;
            $startTs = ($v && $v['used_at']) ? strtotime($v['used_at']) : null;

            $rows[] = [
                'billing_id'   => null,
                'mac'          => $wl['mac'],
                'name'         => $wl['host'] ?: ($v['customer_name'] ?? 'Unknown'),
                'phone_number' => $v['phone'] ?? '',
                'plan_id'      => null,
                'plan_name'    => $v['plan_name'] ?? null,
                'days'         => $v['days'] ?? 0,
                'hours'        => $v['hours'] ?? 0,
                'minutes'      => $v['minutes'] ?? 0,
                'used_at'      => $v['used_at'] ?? null,
                'created_at'   => null,
                'end_at'       => null,
                'start_ts'     => $startTs,
                'used_seconds' => $startTs ? max(0, $now - $startTs) : null,
                'remaining_seconds' => null,
                'is_active'    => false,
                'chip'         => 'info',
                'status_label' => 'On Router (Not Billed)',
            ];
        }

        // Devices on the router blacklist (blocked, not active)
        foreach ($blByNorm as $norm => $bl) {
            if (isset($seenMacs[$norm])) continue;
            $seenMacs[$norm] = true;
            $rows[] = [
                'billing_id'   => null,
                'mac'          => $bl['mac'],
                'name'         => $bl['host'] ?: 'Unknown',
                'phone_number' => '',
                'plan_id'      => null,
                'plan_name'    => null,
                'days'         => 0,
                'hours'        => 0,
                'minutes'      => 0,
                'used_at'      => null,
                'created_at'   => null,
                'end_at'       => null,
                'start_ts'     => null,
                'used_seconds' => null,
                'remaining_seconds' => null,
                'is_active'    => false,
                'chip'         => 'inactive',
                'status_label' => 'Blocked',
            ];
        }
        ?>

        <div class="alert alert-info" style="margin:16px">
            <svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
            <span>
                <strong>Sync Status:</strong>
                Whitelist: <?php echo count($whitelistMacs); ?> |
                Database: <?php echo count($dbMacs); ?> |
                Missing in DB: <?php echo count($missingInDb); ?> |
                Extra in DB: <?php echo count($extraInDb); ?>
            </span>
        </div>

        <?php if ($rows): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone Number</th>
                            <th>MAC Address</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Time Used</th>
                            <th>Remaining Time</th>
                            <th>Created At</th>
                            <th>Ends At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $planDuration = ($row['days'] ?? 0) . "d " . ($row['hours'] ?? 0) . "h " . ($row['minutes'] ?? 0) . "m";
                            $startTs = $row['start_ts'] ?? null;
                            $usedSeconds = $row['used_seconds'] ?? null;
                        ?>
                        <tr id="user-<?php echo $row['billing_id'] ?? ''; ?>"
                            data-router-id="<?php echo $router['id']; ?>"
                            data-mac="<?php echo htmlspecialchars($row['mac']); ?>">
                            <td style="font-weight:500"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['phone_number']); ?></td>
                            <td><code><?php echo htmlspecialchars($row['mac']); ?></code></td>
                            <td><?php echo $row['plan_name'] ? htmlspecialchars($row['plan_name']) . ' <span style="color:var(--on-surface-med);font-size:11px">(' . $planDuration . ')</span>' : '—'; ?></td>
                            <td>
                                <span class="chip <?php echo $row['chip']; ?>"><span class="chip-dot"></span><?php echo htmlspecialchars($row['status_label']); ?></span>
                            </td>
                            <td class="time-used" <?php if ($startTs): ?>data-start="<?php echo $startTs; ?>"<?php if (!empty($row['is_active'])): ?> data-live="1"<?php endif; endif; ?> style="font-size:12px">
                                <?php echo $usedSeconds !== null ? formatRemainingTime($usedSeconds) : '—'; ?>
                            </td>
                            <td class="remaining-time" <?php echo $row['end_at'] ? 'data-end="' . $row['end_at'] . '"' : ''; ?> style="font-size:12px">
                                <?php echo $row['end_at'] ? formatRemainingTime($row['remaining_seconds'] ?? 0) : '—'; ?>
                            </td>
                            <td style="font-size:12px;color:var(--on-surface-med)"><?php echo $row['created_at'] ?? '—'; ?></td>
                            <td style="font-size:12px;color:var(--on-surface-med)"><?php echo $row['end_at'] ?? '—'; ?></td>
                            <td>
                                <?php if (!empty($row['billing_id'])): ?>
                                <div class="td-actions">
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="user_id" value="<?php echo $row['billing_id']; ?>">
                                        <select name="new_plan_id" class="form-control" style="padding:5px 10px;font-size:12px">
                                            <?php foreach ($plans as $plan): ?>
                                                <option value="<?php echo $plan['id']; ?>" <?php echo $plan['id'] == $row['plan_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($plan['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">Change Plan</button>
                                    </form>
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="delete_user_id" value="<?php echo $row['billing_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="width:100%" onclick="return confirm('Delete this user?')">Delete</button>
                                    </form>
                                    <form method="POST" class="mb-0 throttle-form">
                                        <input type="hidden" name="throttle_user_id" value="<?php echo $row['billing_id']; ?>">
                                        <input type="number" name="upload_speed" class="form-control" style="padding:5px 10px;font-size:12px" placeholder="Upload (kbps)" step="0.01" required>
                                        <input type="number" name="download_speed" class="form-control" style="padding:5px 10px;font-size:12px" placeholder="Download (kbps)" step="0.01" required>
                                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%">Throttle</button>
                                    </form>
                                    <button class="btn btn-primary btn-sm unthrottle-btn" style="width:100%"
                                        data-router-id="<?php echo $router['id']; ?>"
                                        data-mac="<?php echo $row['mac']; ?>">Unthrottle</button>
                                </div>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--on-surface-med)">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding:40px">
                <div class="empty-state-icon"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></div>
                <h3>No users found</h3>
                <p>No users found for this router.</p>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<script>
// Real-time clock
function fmtDur(sec) {
    sec = Math.max(0, Math.floor(sec));
    let d = Math.floor(sec / 86400);
    let h = Math.floor((sec % 86400) / 3600);
    let m = Math.floor((sec % 3600) / 60);
    let s = sec % 60;
    return `${d}d ${h}h ${m}m ${s}s`;
}

function updateClock() {
    const now = new Date();

    // 12-hour format
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, "0");
    const ampm = hours >= 12 ? "PM" : "AM";
    hours = hours % 12 || 12;

    document.getElementById("time").textContent = `${hours}:${minutes}`;
    document.getElementById("ampm").textContent = ampm;

    // Full date
    const dateOptions = { weekday: "long", month: "long", day: "numeric" };
    document.getElementById("date").textContent = now.toLocaleDateString(undefined, dateOptions);

    const nowSec = Math.floor(now.getTime() / 1000);

    // Update remaining time
    document.querySelectorAll('.remaining-time[data-end]').forEach(td => {
        const endAt = new Date(td.dataset.end);
        if (isNaN(endAt.getTime())) return;
        td.textContent = fmtDur((endAt.getTime() / 1000) - nowSec);
    });

    // Update time used for live (active) users
    document.querySelectorAll('.time-used[data-live]').forEach(td => {
        const start = parseInt(td.dataset.start, 10);
        if (!start) return;
        td.textContent = fmtDur(nowSec - start);
    });

    // Count down remaining time for connected MikroTik sessions
    document.querySelectorAll('.remaining-time[data-remain-until]').forEach(td => {
        const until = parseInt(td.dataset.remainUntil, 10);
        if (isNaN(until)) return;
        td.textContent = fmtDur(until - nowSec);
    });
}

// Initial run
updateClock();
setInterval(updateClock, 1000);

// Manual MikroTik cleanup of expired users
document.querySelectorAll('.mtk-cleanup-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('Remove all expired MikroTik users and disconnect their sessions?')) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning...';
        try {
            const res = await fetch('/api/vouchers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cleanup_expired' })
            });
            const json = await res.json();
            if (json.success && json.cleanup) {
                const c = json.cleanup;
                alert(`Cleanup done: ${c.expired} expired across ${c.routers} router(s), ${c.removed} user(s) removed, ${c.disconnected} session(s) disconnected.${c.failed.length ? '\nFailed: ' + c.failed.join(', ') : ''}`);
            } else {
                alert(json.error || 'Cleanup failed');
            }
        } catch (err) {
            console.error(err);
            alert('Error running cleanup');
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-broom"></i> Run cleanup';
        location.reload();
    });
});

// Manual unthrottle
document.querySelectorAll('.unthrottle-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const mac = btn.dataset.mac;
        try {
            const res = await fetch('/auth/v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'unthrottle_device', mac })
            });
            const json = await res.json();
            if (json.success) alert(`Device ${mac} unthrottled`);
            else alert(json.message || 'Failed to unthrottle device');
            location.reload();
        } catch (err) {
            console.error(err);
            alert('Error unthrottling device');
        }
    });
});

// Throttle form submission with JS
document.querySelectorAll('.throttle-form').forEach(form => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const row = form.closest('tr');
        const mac = row.dataset.mac;
        const routerId = row.dataset.routerId;

        const upload = form.querySelector('[name="upload_speed"]').value;
        const download = form.querySelector('[name="download_speed"]').value;

        try {
            const res = await fetch(
                `/auth/throttle.php?action=set_throttle&router_id=${routerId}&mac=${mac}&up=${upload}&down=${download}`
            );

            const result = await res.json();

            if (result.success) {
                alert(` Throttle set for ${mac}`);
            } else {
                alert(` Failed: ${result.error || 'Unknown error'}`);
            }

        } catch (err) {
            console.error(err);
            alert(' Error applying throttle');
        }
    });
});
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
<?php ob_end_flush(); ?>
