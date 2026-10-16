<?php
// api/v1/auth.php - Register and Login for both web and app
require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = getInput();
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // -------------------------
    // REGISTER
    // -------------------------
    case 'register':
        $name     = trim($input['name'] ?? '');
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        if (!$name || !$email || !$password || !$confirmPassword) {
            respond(['success' => false, 'error' => 'Name, email and password are required'], 400);
        } elseif ($password !== $confirmPassword) {
            respond(['success' => false, 'error' => 'Passwords do not match'], 400);
        }

        if (strlen($password) < 4) {
            respond(['success' => false, 'error' => 'Password must be at least 4 characters'], 400);
        }

        $db = db();

        $check = $db->prepare("SELECT id FROM accounts WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            respond(['success' => false, 'error' => 'Email already registered'], 409);
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO accounts (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $hashed]);
        $accountId = $db->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO tokens (token, account_id) VALUES (?, ?)")->execute([$token, $accountId]);

        respond([
            'success'   => true,
            'message'   => 'Account created successfully',
            'token'     => $token,
            'account_id'=> (int)$accountId,
            'name'      => $name,
            'email'     => $email
        ]);
        break;

    // -------------------------
    // LOGIN
    // -------------------------
    case 'login':
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            respond(['success' => false, 'error' => 'Email and password are required'], 400);
        }

        $db = db();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account || !password_verify($password, $account['password'])) {
            respond(['success' => false, 'error' => 'Invalid email or password'], 401);
        }

        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO tokens (token, account_id) VALUES (?, ?)")->execute([$token, $account['id']]);

        respond([
            'success'    => true,
            'token'      => $token,
            'account_id' => (int)$account['id'],
            'name'       => $account['name'],
            'email'      => $account['email']
        ]);
        break;

    // -------------------------
    // LOGOUT
    // -------------------------
    case 'logout':
        $accountId = getAccountId();
        $db = db();
        $db->prepare("DELETE FROM tokens WHERE account_id = ?")->execute([$accountId]);
        respond(['success' => true, 'message' => 'Logged out']);
        break;

    // -------------------------
    // CHECK TOKEN (validate session)
    // -------------------------
    case 'me':
        $accountId = getAccountId();
        $db = db();
        $stmt = $db->prepare("SELECT id, name, email, created_at FROM accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            respond(['success' => false, 'error' => 'Account not found'], 404);
        }
        respond(['success' => true, 'account' => $account]);
        break;

    default:
        respond(['success' => false, 'error' => 'Invalid action. Use: register, login, logout, me'], 400);
}
