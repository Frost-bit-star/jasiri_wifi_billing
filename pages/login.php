<?php
// login.php

if (session_status() == PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../db/locale.php';
require_once __DIR__ . '/../auth/session.php';
authResolve();
appSetTimezone($_SESSION['timezone'] ?? $defaultTimezone);

$dbPath = __DIR__ . '/../db/routers.db';
if (!file_exists($dbPath)) {
    file_put_contents($dbPath, '');
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Ensure schema exists (runs CREATE TABLE IF NOT EXISTS, safe to call multiple times)
require_once __DIR__ . '/../db/schema.php';

// -------------------------
// Handle account registration
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$name || !$email || !$password || !$confirmPassword) {
        $regMessage = 'All fields are required.';
    } elseif ($password !== $confirmPassword) {
        $regMessage = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $regMessage = 'Password must be at least 8 characters.';
    } else {
        $check = $db->prepare("SELECT id FROM accounts WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $regMessage = 'Email already registered.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO accounts (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hashed]);
            $newId = $db->lastInsertId();
            $_SESSION['just_registered'] = true;
            $_SESSION['account_id'] = $newId;
            $_SESSION['username'] = $name;
            $_SESSION['account_email'] = $email;
            authLoginUser(['id' => $newId, 'name' => $name, 'email' => $email, 'role' => 'user', 'status' => 1]);
            header('Location: /dashboard');
            exit;
        }
    }
}

// -------------------------
// Handle account login
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'account_login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM accounts WHERE email = ?");
    $stmt->execute([$email]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        $errorMessage = 'Invalid email or password.';
    } elseif ((int)($account['status'] ?? 1) !== 1) {
        $errorMessage = 'This account has been disabled by the super admin.';
    } elseif (password_verify($password, $account['password'])) {
        authLoginUser($account);

        header('Location: /dashboard?role=user');
        exit;
    } else {
        $errorMessage = 'Invalid email or password.';
    }
}

// -------------------------
// Handle admin login (legacy)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM admins WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        $errorMessage = 'Invalid username or password.';
    } elseif ((int)($admin['status'] ?? 1) !== 1) {
        $errorMessage = 'This admin account has been disabled. Contact the super admin.';
    } elseif (password_verify($password, $admin['password'])) {
        $role = $admin['role'] ?: 'admin';
        authLoginAdmin($admin);

        // Super admin runs the platform (manages tenant admins); general staff see the WISP dashboard
        header('Location: ' . (($role === 'superadmin') ? '/admin_dashboard?role=superadmin' : '/dashboard?role=' . $role));
        exit;
    } else {
        $errorMessage = 'Invalid username or password.';
    }
}

// -------------------------
// Handle OTP request for password recovery via API
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot') {
    $email = $_POST['email'] ?? '';
    $stmt = $db->prepare("SELECT * FROM admins WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        $otp = rand(100000, 999999);
        $_SESSION['otp'] = $otp;
        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_verified'] = false;

        $payload = json_encode([
            "to" => $email,
            "subject" => "Your OTP for password recovery",
            "content" => "<p>Your OTP is: <strong>$otp</strong></p>"
        ]);

        $ch = curl_init("https://email-server-flame-zeta.vercel.app/api/send-email");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $otpMessage = "OTP sent to $email. Please check your inbox.";
        } else {
            $otpMessage = "Failed to send OTP. Try again later.";
        }

    } else {
        $otpMessage = "Email not found.";
    }
}

// -------------------------
// Handle OTP verification
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $enteredOtp = $_POST['otp'] ?? '';
    if (isset($_SESSION['otp']) && $_SESSION['otp'] == $enteredOtp) {
        $_SESSION['otp_verified'] = true;
        $otpMessage = "OTP verified! You can now reset your password.";
    } else {
        $otpMessage = "Invalid OTP.";
    }
}

// -------------------------
// Handle password reset
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($_SESSION['otp_verified'] && $newPassword === $confirmPassword) {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE admins SET password = :password WHERE email = :email");
        $stmt->execute([
            ':password' => $hashed,
            ':email' => $_SESSION['otp_email']
        ]);

        $otpMessage = "Password updated successfully!";
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_verified']);
    } else {
        $otpMessage = "Passwords do not match or OTP not verified.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · <?= htmlspecialchars(appName()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Google+Sans+Display:wght@400;500;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.tab-btn { padding: 10px 14px; cursor: pointer; border: none; background: transparent; font-weight: 500; border-bottom: 2px solid transparent; transition: all .2s; color: var(--on-surface-med); }
.tab-btn.active { color: var(--blue-500); border-bottom-color: var(--blue-500); }
.tab-content { display: none; }
.tab-content.active { display: block; }
.lds-ring, .lds-ring div { box-sizing: border-box; }
.lds-ring { display: inline-block; position: relative; width: 80px; height: 80px; }
.lds-ring div { box-sizing: border-box; display: block; position: absolute; width: 64px; height: 64px; margin: 8px; border: 8px solid currentColor; border-radius: 50%; animation: lds-ring 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite; border-color: var(--blue-500) transparent transparent transparent; }
.lds-ring div:nth-child(1){ animation-delay: -0.45s; }
.lds-ring div:nth-child(2){ animation-delay: -0.3s; }
.lds-ring div:nth-child(3){ animation-delay: -0.15s; }
@keyframes lds-ring { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }
</style>
</head>
<body>

<div id="loading" class="hidden fixed top-0 left-0 right-0 bottom-0 flex justify-center items-center z-50" style="display:none;position:fixed;inset:0;background:rgba(26,115,232,.25);align-items:center;justify-content:center;z-index:50">
    <div class="lds-ring"><div></div><div></div><div></div><div></div></div>
</div>

<div class="login-page">

<div class="login-card">

    <div class="login-logo">
        <div class="login-logo-icon">
            <svg viewBox="0 0 24 24"><path d="M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z"/></svg>
        </div>
        <div class="login-logo-text"><?= htmlspecialchars(appName()) ?></div>
    </div>

    <?php if(isset($errorMessage)): ?><div class="alert alert-danger"><svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg><span><?= htmlspecialchars($errorMessage) ?></span></div><?php endif; ?>
    <?php if(isset($regMessage)): ?><div class="alert alert-success"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg><span><?= htmlspecialchars($regMessage) ?></span></div><?php endif; ?>
    <?php if(isset($otpMessage)): ?><div class="alert alert-info"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg><span><?= htmlspecialchars($otpMessage) ?></span></div><?php endif; ?>

    <!-- Tabs -->
    <div class="tabs" style="justify-content:center;gap:4px">
        <button class="tab-btn active" data-tab="account" onclick="showTab('account')">Account Login</button>
        <button class="tab-btn" data-tab="register" onclick="showTab('register')">Register</button>
        <button class="tab-btn" data-tab="admin" onclick="showTab('admin')">Admin</button>
    </div>

    <!-- Account Login -->
    <div id="tab-account" class="tab-content active">
        <form method="POST">
            <input type="hidden" name="action" value="account_login">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" placeholder="Enter email" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input class="form-control" type="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Login</button>
        </form>
    </div>

    <!-- Register -->
    <div id="tab-register" class="tab-content">
        <form method="POST">
            <input type="hidden" name="action" value="register">
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input class="form-control" type="text" name="name" placeholder="Enter your name" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" placeholder="Enter email" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input class="form-control" type="password" name="password" placeholder="Create password (min 8 chars)" required>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input class="form-control" type="password" name="confirm_password" placeholder="Confirm password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Create Account</button>
        </form>
    </div>

    <!-- Admin Login (Legacy) -->
    <div id="tab-admin" class="tab-content">
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label class="form-label">Login as:</label>
                <div style="display:flex;gap:16px">
                    <label class="flex-row" style="gap:6px"><input type="radio" name="role" value="superadmin" required> Super Admin</label>
                    <label class="flex-row" style="gap:6px"><input type="radio" name="role" value="admin" required> Admin</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Username</label>
                <input class="form-control" type="text" name="username" placeholder="Username" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input class="form-control" type="password" name="password" placeholder="Password" required>
            </div>
            <div class="form-group" style="text-align:right">
                <button type="button" id="forgotBtn" class="btn btn-outline btn-sm">Forgot Password?</button>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Admin Login</button>
        </form>

        <form method="POST" id="forgotForm" class="mt-16 hidden" style="display:none">
            <input type="hidden" name="action" value="forgot">
            <div class="form-group">
                <label class="form-label">Email for OTP:</label>
                <input class="form-control" type="email" name="email" placeholder="Email">
            </div>
            <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center">Send OTP</button>
        </form>

        <?php if(isset($_SESSION['otp'])): ?>
        <form method="POST" class="mt-16">
            <input type="hidden" name="action" value="verify_otp">
            <div class="form-group">
                <label class="form-label">Enter OTP:</label>
                <input class="form-control" type="text" name="otp" placeholder="OTP" required>
            </div>
            <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center">Verify OTP</button>
        </form>
        <?php endif; ?>

        <?php if(isset($_SESSION['otp_verified']) && $_SESSION['otp_verified']): ?>
        <form method="POST" class="mt-16">
            <input type="hidden" name="action" value="reset_password">
            <div class="form-group">
                <label class="form-label">New Password:</label>
                <input class="form-control" type="password" name="new_password" placeholder="New Password" required>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password:</label>
                <input class="form-control" type="password" name="confirm_password" placeholder="Confirm Password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Reset Password</button>
        </form>
        <?php endif; ?>
    </div>

</div>

</div>

<script>
function showTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}
document.getElementById('forgotBtn').addEventListener('click', () => {
    document.getElementById('forgotForm').style.display = document.getElementById('forgotForm').style.display === 'block' ? 'none' : 'block';
});
</script>
</body>
</html>
