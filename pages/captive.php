<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/../db/locale.php';
require_once __DIR__ . '/../api/mikrotik_api.php';

$appCurrencySymbol = $appCurrencyMap[$_SESSION['currency'] ?? 'TZS'] ?? 'TSh';
appSetTimezone($_SESSION['timezone'] ?? $defaultTimezone);

$routerDeviceId = $_GET['router'] ?? '';
$redirURL = $_GET['url'] ?? appUrl('/success');
$error = '';
$success = '';
$autoLogin = false;
$showPhoneForm = false;

$rawMAC = trim($_GET['mac'] ?? '');
if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $rawMAC)) {
    $clientMAC = $rawMAC;
} else {
    $clientMAC = '';
}

if (empty($clientMAC) && isset($_SERVER['HTTP_CLIENT_MAC'])) {
    $candidate = trim($_SERVER['HTTP_CLIENT_MAC']);
    if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $candidate)) {
        $clientMAC = $candidate;
    }
}

$stmt = $db->prepare("SELECT * FROM routers WHERE device_id = :did AND type = 'mikrotik'");
$stmt->execute([':did' => $routerDeviceId]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    $stmt = $db->query("SELECT * FROM routers WHERE type = 'mikrotik' LIMIT 1");
    $router = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Use the router owner's configured timezone so activation timestamps are correct
$phoneCode = '+255';
$businessName = '';
if ($router && !empty($router['tenant_id'])) {
    $tzStmt = $db->prepare("SELECT timezone, phone_code, business_name FROM accounts WHERE id = ?");
    $tzStmt->execute([$router['tenant_id']]);
    $tenantRow = $tzStmt->fetch(PDO::FETCH_ASSOC);
    if ($tenantRow) {
        if ($tenantRow['timezone']) appSetTimezone($tenantRow['timezone']);
        if ($tenantRow['phone_code']) $phoneCode = $tenantRow['phone_code'];
        if (!empty(trim($tenantRow['business_name'] ?? ''))) $businessName = trim($tenantRow['business_name']);
    }
}

// Fall back to an admin's business name, then a generic placeholder
if ($businessName === '') {
    try {
        $bnStmt = $db->query("SELECT TRIM(business_name) FROM admins WHERE business_name IS NOT NULL AND TRIM(business_name) != '' ORDER BY CASE WHEN role = 'superadmin' THEN 0 ELSE 1 END, id ASC LIMIT 1");
        $businessName = (string)$bnStmt->fetchColumn();
    } catch (Exception $e) {
        $businessName = '';
    }
}
if ($businessName === '') {
    $businessName = appName();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $router) {
    $voucherCode = preg_replace('/\s+/', '', trim($_POST['voucher_code'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $mac = trim($_POST['mac'] ?? $clientMAC);
    $step = $_POST['step'] ?? '1';

    // Build full international number from country code + local digits
    $submittedCode = trim($_POST['phone_code'] ?? $phoneCode);
    $localDigits = preg_replace('/[^0-9]/', '', $phone);
    $fullPhone = $submittedCode . $localDigits;

    if ($step === '1') {
        if (empty($voucherCode)) {
            $error = 'Please enter a voucher code';
        } else {
            $stmt = $db->prepare("SELECT v.*, p.name as plan_name, p.days, p.hours, p.minutes FROM vouchers v LEFT JOIN plans p ON v.plan_id = p.id WHERE v.code = :code AND v.router_id = :rid");
            $stmt->execute([':code' => $voucherCode, ':rid' => $router['id']]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                $error = 'Invalid voucher code';
            } elseif ($voucher['status'] !== 'active') {
                $error = 'Voucher already used or expired';
            } elseif ($voucher['expires_at'] && strtotime($voucher['expires_at']) < time()) {
                $error = 'Voucher has expired';
            } else {
                $_SESSION['pending_voucher'] = $voucher;
                $_SESSION['pending_mac'] = $mac;
                $showPhoneForm = true;
                $voucherCode = $voucher['code'];
            }
        }
    } elseif ($step === '2') {
        $voucher = $_SESSION['pending_voucher'] ?? null;
        $mac = $_SESSION['pending_mac'] ?? $mac;

        if (!$voucher) {
            $error = 'Session expired, please enter your voucher code again';
            unset($_SESSION['pending_voucher'], $_SESSION['pending_mac']);
        } elseif (empty($localDigits) || strlen($localDigits) < 9) {
            $error = 'Weka namba kamili ya simu (angalau tarakimu 9)';
            $_SESSION['pending_mac'] = $mac;
            $showPhoneForm = true;
            $voucherCode = $voucher['code'];
        } else {
            $phone = $fullPhone;
            $voucherCode = $voucher['code'];
            $duration = ($voucher['days'] ?? 0) * 86400 + ($voucher['hours'] ?? 0) * 3600 + ($voucher['minutes'] ?? 0) * 60;
            if ($duration <= 0) $duration = 3600;
            $hours = floor($duration / 3600);
            $mins = floor(($duration % 3600) / 60);
            $uptime = "${hours}h${mins}m0s";

            try {
                $apiIP = !empty($router['wireguard_ip']) ? $router['wireguard_ip'] : $router['ip'];
                $apiPort = intval($router['port'] ?: 8729);
                $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
                $api->connect();
                $profile = null;
                if (!empty($voucher['is_capped'])) {
                    $api->ensureCappedProfile('1M/500k');
                    $profile = 'capped';
                }
                $api->addHotspotUser('hotspot1', $voucher['code'], $voucher['code'], $uptime, null, $profile);
                $api->close();
            } catch (Exception $e) {
                $error = 'Network error — could not activate voucher. Please try again.';
                $_SESSION['pending_voucher'] = $voucher;
                $_SESSION['pending_mac'] = $mac;
                $showPhoneForm = true;
                $voucherCode = $voucher['code'];
            }

            if (empty($error)) {
                $stmt = $db->prepare("UPDATE vouchers SET status = 'used', used_at = :ts, used_mac = :mac, router_id = :rid, phone = :phone, expires_at = :end_at WHERE id = :id");
                $stmt->execute([
                    ':ts' => date('Y-m-d H:i:s'),
                    ':end_at' => date('Y-m-d H:i:s', time() + $duration),
                    ':mac' => $mac,
                    ':rid' => $router['id'],
                    ':phone' => $phone,
                    ':id' => $voucher['id'],
                ]);
                $autoLogin = true;
                $success = 'Voucher activated! Connecting you now...';
            }

            if (empty($error)) {
                unset($_SESSION['pending_voucher'], $_SESSION['pending_mac']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($businessName) ?> - Unganisha</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, #f0f7ff 0%, #d6ecff 100%); 
            color: #111111;
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            padding: 20px 0;
        }
        .card { 
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 24px; 
            padding: 36px 28px; 
            max-width: 400px; 
            width: 90%; 
            box-shadow: 0 20px 40px rgba(0, 102, 204, 0.08), 0 1px 3px rgba(0, 0, 0, 0.03); 
        }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo-icon-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(214, 236, 255, 0.8);
            border-radius: 50%;
            margin-bottom: 12px;
            box-shadow: 0 8px 16px rgba(0, 122, 255, 0.06);
        }
        .logo svg { width: 26px; height: 26px; fill: #0066cc; }
        .logo h1 { font-size: 24px; color: #111; font-weight: 700; letter-spacing: -0.5px; }
        .logo p { color: #666; font-size: 13px; margin-top: 4px; font-weight: 400; }
        
        .suggestions {
            margin-bottom: 22px;
        }
        .suggestions-title {
            font-size: 11px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
        }
        .chips-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .chip {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(214, 236, 255, 0.8);
            border-radius: 12px;
            padding: 12px 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .chip:hover {
            border-color: #0066cc;
            background: rgba(255, 255, 255, 0.95);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 102, 204, 0.08);
        }
        .chip .time {
            display: block;
            font-size: 11px;
            color: #666;
            margin-bottom: 3px;
            font-weight: 500;
        }
        .chip .price {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #0066cc;
        }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 11px; color: #555; margin-bottom: 6px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; }
        .form-group input { 
            width: 100%; 
            padding: 15px 16px; 
            border: 1.5px solid rgba(200, 225, 250, 0.8); 
            border-radius: 14px; 
            font-size: 18px; 
            text-align: center; 
            letter-spacing: 4px; 
            font-weight: 600; 
            outline: none; 
            background: rgba(255, 255, 255, 0.9);
            color: #111;
            transition: all 0.25s ease; 
        }
        .form-group input:focus { border-color: #0066cc; background: #ffffff; box-shadow: 0 0 0 4px rgba(0, 102, 204, 0.1); }
        .btn { 
            width: 100%; 
            padding: 15px; 
            background: #0066cc; 
            color: white; 
            border: none; 
            border-radius: 14px; 
            font-size: 15px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); 
            box-shadow: 0 4px 12px rgba(0, 102, 204, 0.2);
        }
        .btn:hover { background: #0052a3; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0, 102, 204, 0.3); }
        .btn:active { transform: translateY(0); }
        
        .alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; text-align: center; }
        .alert-error { background: rgba(255, 245, 245, 0.9); color: #c53030; border: 1px solid rgba(254, 215, 215, 0.8); }
        .alert-success { background: rgba(240, 255, 244, 0.9); color: #22543d; border: 1px solid rgba(198, 246, 213, 0.8); }
        
        .support-info {
            margin-top: 20px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding-top: 16px;
        }
        .support-info span { font-weight: 600; color: #111; }
        .footer { text-align: center; margin-top: 14px; font-size: 11px; color: #888; letter-spacing: 0.3px; }

        /* Connecting Loader */
        .connecting-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.96);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .earth-loader {
            --watercolor: #3344c1;
            --landcolor: #7cc133;
            width: 7.5em;
            height: 7.5em;
            background-color: var(--watercolor);
            position: relative;
            overflow: hidden;
            border-radius: 50%;
            box-shadow:
                inset 0em 0.5em rgba(255,255,255,0.25),
                inset 0em -0.5em rgba(0,0,0,0.25);
            border: solid 0.15em white;
        }

        .connecting-overlay p {
            margin-top:20px;
            color:#3344c1;
            font-size:18px;
            font-weight:600;
        }

        .earth-loader svg:nth-child(1) {
            position:absolute;
            bottom:-2em;
            width:7em;
            animation:round1 5s infinite linear .75s;
        }

        .earth-loader svg:nth-child(2) {
            position:absolute;
            top:-3em;
            width:7em;
            animation:round1 5s infinite linear;
        }

        .earth-loader svg:nth-child(3) {
            position:absolute;
            top:-2.5em;
            width:7em;
            animation:round2 5s infinite linear;
        }

        .earth-loader svg:nth-child(4) {
            position:absolute;
            bottom:-2.2em;
            width:7em;
            animation:round2 5s infinite linear .75s;
        }

        @keyframes round1 {
            0% { left:-2em; transform:rotate(0deg); }
            50% { left:-6em; transform:rotate(25deg); }
            100% { left:7em; transform:rotate(-25deg); }
        }

        @keyframes round2 {
            0% { left:5em; transform:rotate(0deg); }
            50% { left:-7em; transform:rotate(25deg); }
            100% { left:8em; transform:rotate(-25deg); }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <div class="logo-icon-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61l1.42-1.42C5.64 15.05 5 13.6 5 12c0-3.87 3.13-7 7-7s7 3.13 7 7c0 1.6-.64 3.05-1.39 4.19l1.42 1.42C20.26 16.07 21 14.12 21 12c0-4.97-4.03-9-9-9zm0 4c-2.76 0-5 2.24-5 5 0 1.18.42 2.26 1.12 3.11l1.42-1.42C9.17 13.34 9 12.69 9 12c0-1.66 1.34-3 3-3s3 1.34 3 3c0 .69-.17 1.34-.54 1.69l1.42 1.42C18.58 14.26 19 13.18 19 12c0-2.76-2.24-5-5-5zm0 4c-.55 0-1 .45-1 1 0 .28.11.53.29.71l1.42 1.42c.18.18.43.29.71.29.55 0 1-.45 1-1 0-.55-.45-1-1-1zm0 4a3.001 3.001 0 0 1-2.83-2H14.83A3.001 3.001 0 0 1 12 15z"/></svg>
            </div>
            <h1><?= htmlspecialchars($businessName) ?></h1>
            <p><?= $showPhoneForm ? 'Thibitisha namba yako ya simu' : 'Weka namba ya vocha yako kuingia mtandaoni' ?></p>
        </div>

        <?php if (!$showPhoneForm): ?>
        <div class="suggestions">
            <div class="suggestions-title">Nunua Vocher</div>
            <div class="chips-grid">
                <div class="chip">
                    <span class="time">Masaa 12</span>
                    <span class="price">500 <?= htmlspecialchars($appCurrencySymbol) ?></span>
                </div>
                <div class="chip">
                    <span class="time">Masaa 24</span>
                    <span class="price">1000 <?= htmlspecialchars($appCurrencySymbol) ?></span>
                </div>
                <div class="chip">
                    <span class="time">Siku 7</span>
                    <span class="price">5000 <?= htmlspecialchars($appCurrencySymbol) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php if (!empty($autoLogin)): ?>
            <form id="autoLogin" action="http://10.10.0.1/login" method="post">
                <input type="hidden" name="username" value="<?= htmlspecialchars($voucherCode ?? '') ?>">
                <input type="hidden" name="password" value="<?= htmlspecialchars($voucherCode ?? '') ?>">
                <input type="hidden" name="dst" value="<?= htmlspecialchars($redirURL) ?>">
            </form>
            <script>setTimeout(function(){ document.getElementById('autoLogin').submit(); }, 2000);</script>
            <?php endif; ?>
        <?php elseif ($showPhoneForm): ?>
        <!-- Step 2: Phone number confirmation -->
        <div class="alert" style="background: rgba(240, 248, 255, 0.9); color: #004080; border: 1px solid rgba(184, 218, 255, 0.8); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; text-align: center; font-size: 14px;">
            <strong>Voucher <?= htmlspecialchars($voucherCode) ?></strong> ni sahihi. Tafadhali weka namba yako ya simu.
        </div>
        <form method="POST" action="?router=<?= urlencode($routerDeviceId) ?>&mac=<?= urlencode($clientMAC) ?>">
            <div class="form-group">
                <label>Namba ya Simu</label>
                <div style="display:flex;align-items:center;gap:0;">
                    <span style="background:#e8f0fe;border:1px solid #ccc;border-right:none;border-radius:8px 0 0 8px;padding:10px 10px;font-weight:600;color:#333;white-space:nowrap;"><?= htmlspecialchars($phoneCode) ?></span>
                    <input type="tel" name="phone" placeholder="758 224 994" required autofocus
                        style="border-radius:0 8px 8px 0;flex:1;"
                        pattern="[1-9][0-9]{8,11}"
                        title="Weka namba kamili ya simu bila sifuri ya mwanzo"
                        oninput="this.value = this.value.replace(/[^0-9]/g,'').replace(/^0+/,'')">
                </div>
                <input type="hidden" name="phone_code" value="<?= htmlspecialchars($phoneCode) ?>">
                <small style="color:#666;">Weka namba yako ya simu bila kitambulisho cha nchi</small>
            </div>
            <input type="hidden" name="mac" value="<?= htmlspecialchars($clientMAC) ?>">
            <input type="hidden" name="step" value="2">
            <button type="submit" class="btn">Thibitisha na Unganisha</button>
        </form>
        <?php else: ?>
        <form method="POST" action="?router=<?= urlencode($routerDeviceId) ?>&mac=<?= urlencode($clientMAC) ?>">
            <div class="form-group">
                <label>Namba ya Vocha</label>
                <input type="text" id="voucherInput" name="voucher_code" placeholder="0000 0000" maxlength="11" required autofocus
                    oninput="this.value = this.value.replace(/[^0-9]/g,'').replace(/(.{4})/g,'$1 ').trim()">
            </div>
            <input type="hidden" name="mac" value="<?= htmlspecialchars($clientMAC) ?>">
            <input type="hidden" name="step" value="1">
            <button type="submit" class="btn">Unganisha</button>
        </form>
        <div style="text-align:center;margin-top:12px;">
            <button type="button" id="scanQrBtn" class="btn" style="background:#555;font-size:13px;padding:8px 16px;box-shadow:none;" onclick="startQrScan()">
                <i class="fas fa-qrcode"></i> Skani Kiotomatiki
            </button>
        </div>
        <div id="qr-reader" style="width:100%;max-width:300px;margin:12px auto 0;display:none;border-radius:10px;overflow:hidden;"></div>
        <?php endif; ?>

        <div class="support-info">
            Una tatizo? Wasiliana na huduma kwa wateja: <span>0758244994</span>
        </div>

        <div class="footer">Imeywezeshwa na <?= htmlspecialchars($businessName) ?></div>
    </div>

    <div class="connecting-overlay" id="connectingOverlay">
        <div class="earth-loader">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
                <circle cx="100" cy="100" r="90" fill="#7CC133"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
                <circle cx="100" cy="100" r="90" fill="#7CC133"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
                <circle cx="100" cy="100" r="90" fill="#7CC133"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
                <circle cx="100" cy="100" r="90" fill="#7CC133"/>
            </svg>
        </div>
        <p>Inaunganisha <?= htmlspecialchars($businessName) ?>...</p>
    </div>

    <script src="/assets/js/html5-qrcode.min.js"></script>
    <script>
        let html5QrCode = null;
        function startQrScan() {
            var reader = document.getElementById('qr-reader');
            if (reader.style.display === 'none') {
                if (typeof Html5Qrcode === 'undefined') {
                    reader.innerHTML = '<p style="color:#c00;font-size:13px;padding:12px;">Programu ya skani haipatikani. Weka namba ya vocha mkononi.</p>';
                    reader.style.display = 'block';
                    setTimeout(function() { reader.style.display = 'none'; reader.innerHTML = ''; }, 4000);
                    return;
                }
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    reader.innerHTML = '<p style="color:#c00;font-size:13px;padding:12px;">Camera haipatikani kwenye kivinjari hiki. Weka namba ya vocha mkononi.</p>';
                    reader.style.display = 'block';
                    setTimeout(function() { reader.style.display = 'none'; reader.innerHTML = ''; }, 4000);
                    return;
                }
                reader.style.display = 'block';
                reader.innerHTML = '';
                document.getElementById('scanQrBtn').innerHTML = '<i class="fas fa-times"></i> Funga';
                html5QrCode = new Html5Qrcode('qr-reader');
                html5QrCode.start(
                    { facingMode: 'environment' },
                    { fps: 5, qrbox: { width: 200, height: 200 } },
                    function onScanSuccess(decodedText) {
                        var code = decodedText.replace(/[^0-9]/g, '').substring(0, 8);
                        if (code.length === 8) {
                            var formatted = code.substring(0,4) + ' ' + code.substring(4);
                            document.getElementById('voucherInput').value = formatted;
                            html5QrCode.stop().then(function() { html5QrCode.clear(); }).catch(function(){});
                            reader.style.display = 'none';
                            document.getElementById('scanQrBtn').innerHTML = '<i class="fas fa-qrcode"></i> Skani Kiotomatiki';
                        }
                    }
                ).catch(function(err) {
                    reader.innerHTML = '<p style="color:#c00;font-size:13px;padding:12px;">Camera haipatikani. Weka namba ya vocha mkononi.</p>';
                    setTimeout(function() { reader.style.display = 'none'; reader.innerHTML = ''; }, 4000);
                    document.getElementById('scanQrBtn').innerHTML = '<i class="fas fa-qrcode"></i> Skani Kiotomatiki';
                });
            } else {
                if (html5QrCode) {
                    html5QrCode.stop().then(function() { html5QrCode.clear(); }).catch(function(){});
                }
                reader.style.display = 'none';
                reader.innerHTML = '';
                document.getElementById('scanQrBtn').innerHTML = '<i class="fas fa-qrcode"></i> Skani Kiotomatiki';
            }
        }
    </script>
    <script>
        const loginForm = document.querySelector("form[method='POST']");
        if (loginForm) {
            const connectBtn = loginForm.querySelector(".btn");
            loginForm.addEventListener("submit", function(){
                connectBtn.disabled = true;
                connectBtn.innerHTML = "Inaunganisha...";
                document.getElementById("connectingOverlay").style.display = "flex";
            });
        }
    </script>
</body>
</html>
