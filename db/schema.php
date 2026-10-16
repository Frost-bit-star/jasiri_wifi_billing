<?php
// schema.php

require_once __DIR__ . '/../config.php';

$db = new PDO('sqlite:' . __DIR__ . '/routers.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// -------------------------
// Helper: add column if missing (SQLite-safe)
// -------------------------
function addColumnIfMissing(PDO $db, string $table, string $column, string $definition) {
    $cols = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $cols, true)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

// -------------------------
// Routers table
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS routers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    ip TEXT NOT NULL,
    port INTEGER DEFAULT 80,
    password TEXT NOT NULL
)
");

// Add missing router columns
addColumnIfMissing($db, 'routers', 'last_run', 'TEXT');          // last time router was processed
addColumnIfMissing($db, 'routers', 'last_qos_hash', 'TEXT');     // last QoS state hash
addColumnIfMissing($db, 'routers', 'last_mode', 'TEXT');         // 'blacklist' or 'whitelist'
addColumnIfMissing($db, 'routers', 'last_sync', 'INTEGER');      // unix timestamp of last push

// MikroTik support columns
addColumnIfMissing($db, 'routers', 'type', "TEXT DEFAULT 'tenda'");
addColumnIfMissing($db, 'routers', 'location', 'TEXT');
addColumnIfMissing($db, 'routers', 'device_id', 'TEXT');
addColumnIfMissing($db, 'routers', 'wireguard_ip', 'TEXT');
addColumnIfMissing($db, 'routers', 'provisioning_status', "TEXT DEFAULT 'offline'");
addColumnIfMissing($db, 'routers', 'last_provisioned_at', 'TEXT');
addColumnIfMissing($db, 'routers', 'provision_token', 'TEXT');
addColumnIfMissing($db, 'routers', 'wg_pubkey', 'TEXT');

// Saved wireless SSID for this router (used on printed vouchers)
addColumnIfMissing($db, 'routers', 'ssid', 'TEXT');

// Access service mode: 'hotspot' (captive portal) or 'pppoe' (PPPoE server)
addColumnIfMissing($db, 'routers', 'service_mode', "TEXT DEFAULT 'hotspot'");

// -------------------------
// Plans table
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    days INTEGER DEFAULT 0,
    hours INTEGER DEFAULT 0,
    minutes INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

// -------------------------
// Users table
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL,
    ip TEXT NOT NULL,
    mac TEXT NOT NULL,
    router_id INTEGER NOT NULL,
    plan_id INTEGER DEFAULT NULL,
    internet_access INTEGER DEFAULT 1,
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(router_id) REFERENCES routers(id),
    FOREIGN KEY(plan_id) REFERENCES plans(id)
)
");

// Ensure UNIQUE(mac, router_id)
$db->exec("
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_mac_router
ON users(mac, router_id)
");

// Add missing sync tracking columns for users
addColumnIfMissing($db, 'users', 'last_router_state', 'TEXT');  // true/false
addColumnIfMissing($db, 'users', 'last_synced_at', 'INTEGER');   // unix timestamp

// -------------------------
// Devices table (billing / active users)
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS billing (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    router_id INTEGER NOT NULL,
    plan_id INTEGER DEFAULT NULL,
    internet_access INTEGER DEFAULT 1,  -- Add internet_access column here
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    remaining_time INTEGER DEFAULT 0,
    end_at TEXT DEFAULT NULL,
    FOREIGN KEY(router_id) REFERENCES routers(id),
    FOREIGN KEY(plan_id) REFERENCES plans(id)
)
");

// Ensure UNIQUE(mac, router_id)
$db->exec("
CREATE UNIQUE INDEX IF NOT EXISTS idx_devices_mac_router
ON billing(mac, router_id)
");

// -------------------------
// Admins table (login & recovery)
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

// Insert default admin if not exists
$check = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = :username");
$check->execute([':username' => 'admin']);
if ($check->fetchColumn() == 0) {
    $insert = $db->prepare("
        INSERT INTO admins (username, password, email)
        VALUES (:username, :password, :email)
    ");
    $insert->execute([
        ':username' => 'admin',
        ':password' => password_hash('1111', PASSWORD_DEFAULT),
        ':email' => 'jnyaragita12@gmail.com'
    ]);
}

// -------------------------
// Accounts table (multi-tenant users)
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

// -------------------------
// Tokens table (API sessions)
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS tokens (
    token TEXT PRIMARY KEY,
    account_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(account_id) REFERENCES accounts(id)
)
");

// Add tenant_id to routers if missing
addColumnIfMissing($db, 'routers', 'tenant_id', 'INTEGER DEFAULT NULL');

// Dashboard currency preference per account / admin
addColumnIfMissing($db, 'accounts', 'currency', "TEXT DEFAULT 'TZS'");
addColumnIfMissing($db, 'admins', 'currency', "TEXT DEFAULT 'TZS'");

// Server timezone preference per account / admin
addColumnIfMissing($db, 'accounts', 'timezone', "TEXT DEFAULT 'Africa/Dar_es_Salaam'");
addColumnIfMissing($db, 'admins', 'timezone', "TEXT DEFAULT 'Africa/Dar_es_Salaam'");

// Country phone code for SMS (e.g. +255)
addColumnIfMissing($db, 'accounts', 'phone_code', "TEXT DEFAULT '+255'");
addColumnIfMissing($db, 'admins', 'phone_code', "TEXT DEFAULT '+255'");

// Branding: business name shown on the captive portal (falls back to 'WISP')
addColumnIfMissing($db, 'accounts', 'business_name', "TEXT DEFAULT ''");
addColumnIfMissing($db, 'admins', 'business_name', "TEXT DEFAULT ''");

// Multi-tenant admin management: the super admin controls ALL tenant accounts.
// Each account is a "general admin" running their own WISP instance.
addColumnIfMissing($db, 'accounts', 'voucher_limit', 'INTEGER DEFAULT -1');     // -1 = unlimited
addColumnIfMissing($db, 'accounts', 'status', 'INTEGER DEFAULT 1');             // 1 = active, 0 = disabled
addColumnIfMissing($db, 'accounts', 'created_by', 'INTEGER DEFAULT NULL');      // superadmin id who created them

// Platform staff admins (superadmin vs general staff) and their limits
addColumnIfMissing($db, 'admins', 'role', "TEXT DEFAULT 'admin'");           // 'superadmin' or 'admin'
addColumnIfMissing($db, 'admins', 'voucher_limit', 'INTEGER DEFAULT -1');     // -1 = unlimited
addColumnIfMissing($db, 'admins', 'status', 'INTEGER DEFAULT 1');             // 1 = active, 0 = disabled
addColumnIfMissing($db, 'admins', 'created_by', 'INTEGER DEFAULT NULL');      // superadmin id who created them

// Bootstrap: guarantee at least one superadmin exists (promote the original seed admin)
$superCount = (int)$db->query("SELECT COUNT(*) FROM admins WHERE role = 'superadmin'")->fetchColumn();
if ($superCount === 0) {
    $db->exec("UPDATE admins SET role = 'superadmin' WHERE id = (SELECT id FROM admins ORDER BY id ASC LIMIT 1)");
}

// -------------------------
// Hotspot users table (server-side mirror of ALL MikroTik hotspot/PPPoE users:
// active sessions plus dead/expired user records pulled from the router)
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS hotspot_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL,
    username TEXT NOT NULL,
    status TEXT DEFAULT 'active',          -- 'active' | 'dead' | 'disabled'
    profile TEXT DEFAULT NULL,
    limit_uptime TEXT DEFAULT NULL,
    uptime TEXT DEFAULT NULL,
    bytes_in INTEGER DEFAULT 0,
    bytes_out INTEGER DEFAULT 0,
    disabled INTEGER DEFAULT 0,
    comment TEXT DEFAULT NULL,
    first_seen TEXT DEFAULT NULL,
    last_seen TEXT DEFAULT NULL,
    last_sync TEXT DEFAULT NULL,
    FOREIGN KEY(router_id) REFERENCES routers(id),
    UNIQUE(router_id, username)
)
");

// Add missing hotspot_users columns if script re-run
addColumnIfMissing($db, 'hotspot_users', 'status', "TEXT DEFAULT 'active'");
addColumnIfMissing($db, 'hotspot_users', 'profile', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'hotspot_users', 'limit_uptime', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'hotspot_users', 'uptime', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'hotspot_users', 'bytes_in', 'INTEGER DEFAULT 0');
addColumnIfMissing($db, 'hotspot_users', 'bytes_out', 'INTEGER DEFAULT 0');
addColumnIfMissing($db, 'hotspot_users', 'disabled', 'INTEGER DEFAULT 0');
addColumnIfMissing($db, 'hotspot_users', 'comment', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'hotspot_users', 'first_seen', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'hotspot_users', 'last_seen', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'hotspot_users', 'last_sync', 'TEXT DEFAULT NULL');

// -------------------------
// Add missing columns for devices table if script re-run
// -------------------------
addColumnIfMissing($db, 'billing', 'remaining_time', 'INTEGER DEFAULT 0');
addColumnIfMissing($db, 'billing', 'end_at', 'TEXT');

// -------------------------
// Ensure `internet_access` column exists in `billing` table
addColumnIfMissing($db, 'billing', 'internet_access', 'INTEGER DEFAULT 1');

// Columns used by billing APIs, add_user, and billuser
addColumnIfMissing($db, 'billing', 'name', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'billing', 'phone_number', 'TEXT DEFAULT NULL');

// -------------------------
// Vouchers table
// -------------------------
$db->exec("
CREATE TABLE IF NOT EXISTS vouchers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    plan_id INTEGER DEFAULT NULL,
    router_id INTEGER DEFAULT NULL,
    phone TEXT DEFAULT NULL,
    price REAL DEFAULT 0,
    status TEXT DEFAULT 'active',
    customer_name TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TEXT DEFAULT NULL,
    expires_at TEXT DEFAULT NULL,
    FOREIGN KEY(plan_id) REFERENCES plans(id),
    FOREIGN KEY(router_id) REFERENCES routers(id)
)
");

// Attribute voucher creation to a tenant account (used for per-tenant usage limits)
addColumnIfMissing($db, 'vouchers', 'created_by', 'INTEGER DEFAULT NULL');
addColumnIfMissing($db, 'vouchers', 'customer_name', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'vouchers', 'used_mac', 'TEXT DEFAULT NULL');
addColumnIfMissing($db, 'vouchers', 'reminder_sent', 'INTEGER DEFAULT 0');
addColumnIfMissing($db, 'vouchers', 'is_capped', 'INTEGER DEFAULT 0');

// -------------------------
if (php_sapi_name() === 'cli') {
    echo "Database schema verified and updated with MikroTik + voucher support.\n";
}
?>
