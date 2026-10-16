<?php
ob_start();
$pageTitle = 'Settings';
$activePage = 'settings';

require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/../db/locale.php';
// schema.php defines $db (single shared connection)

$role    = $_SESSION['role'] ?? 'user';
$accountId = $_SESSION['account_id'] ?? null;

$msgSuccess = '';
$msgError   = '';

// Load current profile
if ($role === 'user' && $accountId) {
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->execute([$accountId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    $idCol = 'id';
} else {
    $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$_SESSION['username'] ?? '']);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -------------------------
    // Update profile (email, name, currency)
    // -------------------------
    if ($action === 'profile' && $profile) {
        $currentPass = $_POST['current_password'] ?? '';

        if (!password_verify($currentPass, $profile['password'])) {
            $msgError = 'Current password is incorrect.';
        } else {
            $newEmail = strtolower(trim($_POST['email'] ?? ''));
            $newName  = trim($_POST['name'] ?? '');
            $newCurrency = $_POST['currency'] ?? 'TZS';
            if (!array_key_exists($newCurrency, $currencyOptions)) {
                $newCurrency = 'TZS';
            }
            $newTimezone = appValidTimezone($_POST['timezone'] ?? '');
            $newPhoneCode = $_POST['phone_code'] ?? '+255';
            if (!array_key_exists($newPhoneCode, $phoneCodeOptions)) {
                $newPhoneCode = '+255';
            }
            $newBusinessName = trim($_POST['business_name'] ?? '');
            if (mb_strlen($newBusinessName) > 40) {
                $newBusinessName = mb_substr($newBusinessName, 0, 40);
            }

            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $msgError = 'Please enter a valid email address.';
            } elseif ($role === 'user' && empty($newName)) {
                $msgError = 'Name is required.';
            } else {
                // Email uniqueness check
                if ($role === 'user') {
                    $stmt = $db->prepare("SELECT id FROM accounts WHERE email = ? AND id != ?");
                    $stmt->execute([$newEmail, $profile['id']]);
                    if ($stmt->fetch()) {
                        $msgError = 'Email is already registered to another account.';
                    }
                } else {
                    $stmt = $db->prepare("SELECT username FROM admins WHERE email = ? AND username != ?");
                    $stmt->execute([$newEmail, $profile['username']]);
                    if ($stmt->fetch()) {
                        $msgError = 'Email is already in use.';
                    }
                }

                if (empty($msgError)) {
                    if ($role === 'user') {
                        $db->prepare("UPDATE accounts SET name = ?, email = ?, currency = ?, timezone = ?, phone_code = ?, business_name = ? WHERE id = ?")
                           ->execute([$newName, $newEmail, $newCurrency, $newTimezone, $newPhoneCode, $newBusinessName, $profile['id']]);
                        $_SESSION['username'] = $newName;
                    } else {
                        $db->prepare("UPDATE admins SET email = ?, currency = ?, timezone = ?, phone_code = ?, business_name = ? WHERE username = ?")
                           ->execute([$newEmail, $newCurrency, $newTimezone, $newPhoneCode, $newBusinessName, $profile['username']]);
                    }
                    $_SESSION['account_email'] = $newEmail;
                    $_SESSION['currency'] = $newCurrency;
                    $_SESSION['timezone'] = $newTimezone;
                    $_SESSION['phone_code'] = $newPhoneCode;
                    authSnapshot($role);
                    date_default_timezone_set($newTimezone);
                    $msgSuccess = 'Profile updated. Currency is now ' . $newCurrency . ' and timezone is now ' . $newTimezone . '.';
                    $profile['email'] = $newEmail;
                    $profile['currency'] = $newCurrency;
                    $profile['timezone'] = $newTimezone;
                    $profile['business_name'] = $newBusinessName;
                    if ($role === 'user') $profile['name'] = $newName;
                }
            }
        }
    }

    // -------------------------
    // Change password
    // -------------------------
    if ($action === 'password' && $profile) {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPass, $profile['password'])) {
            $msgError = 'Current password is incorrect.';
        } elseif (strlen($newPass) < 4) {
            $msgError = 'New password must be at least 4 characters.';
        } elseif ($newPass !== $confirmPass) {
            $msgError = 'New password and confirmation do not match.';
        } else {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            if ($role === 'user') {
                $db->prepare("UPDATE accounts SET password = ? WHERE id = ?")->execute([$hashed, $profile['id']]);
            } else {
                $db->prepare("UPDATE admins SET password = ? WHERE username = ?")->execute([$hashed, $profile['username']]);
            }
            $msgSuccess = 'Password updated successfully.';
        }
    }
}

include __DIR__ . '/../components/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><i class="fas fa-cog"></i> Settings</h1>
        <p class="page-subtitle">Manage your profile, password and dashboard currency</p>
    </div>
</div>

<?php if ($msgSuccess): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msgSuccess) ?></div>
<?php endif; ?>
<?php if ($msgError): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($msgError) ?></div>
<?php endif; ?>

<div class="form-row">
    <!-- Profile -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-user"></i> Profile</span>
        </div>
        <div class="card-body">
            <p class="card-subtitle">Update your name, email and dashboard currency. Enter your current password to save.</p>
            <form method="POST">
                <input type="hidden" name="action" value="profile">
                <?php if ($role === 'user'): ?>
                <div class="form-group">
                    <label class="form-label" for="profileName">Full Name</label>
                    <input type="text" class="form-control" id="profileName" name="name" value="<?= htmlspecialchars($profile['name'] ?? '') ?>" required>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label" for="profileEmail">Email Address</label>
                    <input type="email" class="form-control" id="profileEmail" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="profileCurrency">Dashboard Currency</label>
                    <select class="form-control" id="profileCurrency" name="currency" onchange="filterTimezones()">
                        <?php foreach ($currencyOptions as $code => $label): ?>
                            <option value="<?= $code ?>" <?= (($profile['currency'] ?? 'TZS') === $code) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--on-surface-med);">Applied to your dashboard, revenue and vouchers. Saved for your account.</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="profileTimezone">Server Timezone</label>
                    <select class="form-control" id="profileTimezone" name="timezone">
                        <?php foreach (appTimezonesForCurrency($profile['currency'] ?? 'TZS') as $tz): ?>
                            <option value="<?= htmlspecialchars($tz) ?>" <?= ((($profile['timezone'] ?? $defaultTimezone) === $tz) ? 'selected' : '') ?>>
                                <?= appTimezoneLabel($tz) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--on-surface-med);">Used for all server dates/times across the entire app. Filtered by your currency.</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="profilePhoneCode">Country Phone Code</label>
                    <select class="form-control" id="profilePhoneCode" name="phone_code">
                        <?php foreach ($phoneCodeOptions as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= (($profile['phone_code'] ?? '+255') === $code) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--on-surface-med);">Used for SMS delivery. Customers on the captive portal will enter their number with this prefix.</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="profileBusinessName">Business Name</label>
                    <input type="text" class="form-control" id="profileBusinessName" name="business_name" maxlength="40" value="<?= htmlspecialchars($profile['business_name'] ?? '') ?>" placeholder="e.g. OneLink">
                    <small style="color:var(--on-surface-med);">Shown as the brand on the captive portal page. Leave empty to use the default placeholder "<?= htmlspecialchars(appName()) ?>".</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="profileCurrentPass">Current Password</label>
                    <input type="password" class="form-control" id="profileCurrentPass" name="current_password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Profile</button>
            </form>
        </div>
    </div>

    <!-- Password -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-lock"></i> Change Password</span>
        </div>
        <div class="card-body">
            <p class="card-subtitle">Enter your current password, then a new one.</p>
            <form method="POST">
                <input type="hidden" name="action" value="password">
                <div class="form-group">
                    <label class="form-label" for="passCurrent">Current Password</label>
                    <input type="password" class="form-control" id="passCurrent" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label class="form-label" for="passNew">New Password</label>
                    <input type="password" class="form-control" id="passNew" name="new_password" required autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label" for="passConfirm">Confirm New Password</label>
                    <input type="password" class="form-control" id="passConfirm" name="confirm_password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>

<?php
$tzMapJson = [];
foreach ($currencyOptions as $code => $_label) {
    $tzMapJson[$code] = array_map(function ($tz) {
        return ['value' => $tz, 'label' => strip_tags(appTimezoneLabel($tz))];
    }, appTimezonesForCurrency($code));
}
?>
<script>
const CURRENCY_TIMEZONES = <?= json_encode($tzMapJson, JSON_UNESCAPED_SLASHES) ?>;
const currentTZ = <?= json_encode($_SESSION['timezone'] ?? $profile['timezone'] ?? $defaultTimezone, JSON_UNESCAPED_SLASHES) ?>;

function filterTimezones() {
    const currency = document.getElementById('profileCurrency').value;
    const select = document.getElementById('profileTimezone');
    const tzs = CURRENCY_TIMEZONES[currency] || CURRENCY_TIMEZONES['TZS'];
    select.innerHTML = '';
    let foundCurrent = false;
    tzs.forEach(function (tz) {
        const opt = document.createElement('option');
        opt.value = tz.value;
        opt.textContent = tz.label;
        if (tz.value === currentTZ) {
            opt.selected = true;
            foundCurrent = true;
        }
        select.appendChild(opt);
    });
    if (!foundCurrent && tzs.length) {
        select.selectedIndex = 0;
    }
}
</script>
<?php ob_end_flush(); ?>
