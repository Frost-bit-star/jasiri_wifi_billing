<?php
// /auth/session.php

require_once __DIR__ . '/../config.php';
//
// Multi-identity session handling.
//
// This app historically used a single flat $_SESSION where the user (WISP
// account) login and the admin/superadmin login wrote to the same keys
// (logged_in, role, username, account_id, admin_id, ...). Because each
// browser tab shares one PHP session cookie, logging in as admin in one tab
// overwrote the user identity in every tab -> "session leaks".
//
// Fix: keep a name-spaced backup of each identity under
//   $_SESSION['identities'][ <role> ]
// and expose the currently-requested identity on the flat keys that the rest
// of the app already reads. The "current" role for a given request is picked
// up from ?role= / POST role when provided (the front-end pins each tab's
// role into the URL), otherwise it falls back to $_SESSION['active_role'].

if (!function_exists('authValidRoles')) {
    function authValidRoles(): array {
        return ['user', 'admin', 'superadmin'];
    }
}

if (!function_exists('authIsValidRole')) {
    function authIsValidRole($role): bool {
        return in_array($role, authValidRoles(), true);
    }
}

/**
 * Return the role this request should act as.
 * Precedence: explicit ?role= / POST role -> $_SESSION['active_role'] -> 'admin'
 */
if (!function_exists('authResolveRequestedRole')) {
    function authResolveRequestedRole(): string {
        if (isset($_GET['role']) && authIsValidRole($_GET['role'])) {
            return $_GET['role'];
        }
        if (isset($_POST['role']) && authIsValidRole($_POST['role'])) {
            return $_POST['role'];
        }
        $active = $_SESSION['active_role'] ?? null;
        return authIsValidRole($active) ? $active : 'admin';
    }
}

/**
 * The flat keys shared across the app that represent the "current" identity.
 */
if (!function_exists('authIdentityKeys')) {
    function authIdentityKeys(): array {
        return [
            'logged_in', 'role', 'username',
            'admin_id', 'account_id', 'account_email',
            'currency', 'timezone', 'phone_code',
        ];
    }
}

/**
 * Snapshot the current flat session identity into a namespaced backup for $role.
 */
if (!function_exists('authSnapshot')) {
    function authSnapshot(string $role): void {
        if (!authIsValidRole($role)) return;
        $snap = [];
        foreach (authIdentityKeys() as $k) {
            if (array_key_exists($k, $_SESSION)) {
                $snap[$k] = $_SESSION[$k];
            }
        }
        $_SESSION['identities'][$role] = $snap;
    }
}

/**
 * Load the namespaced identity for $role onto the flat keys.
 */
if (!function_exists('authLoad')) {
    function authLoad(string $role): void {
        if (!authIsValidRole($role)) return;
        $snap = $_SESSION['identities'][$role] ?? [];
        foreach (authIdentityKeys() as $k) {
            if (array_key_exists($k, $snap)) {
                $_SESSION[$k] = $snap[$k];
            } else {
                unset($_SESSION[$k]);
            }
        }
        $_SESSION['active_role'] = $role;
    }
}

/**
 * Make the flat session reflect the identity for the requested role, swapping
 * out whichever identity was active before so both identities stay intact.
 * Call right after session_start() at the top of a request.
 */
if (!function_exists('authResolve')) {
    function authResolve(): void {
        $role = authResolveRequestedRole();

        // Migration: the first time this runs on a session that was created by
        // the old single-identity code, preserve the existing login before the
        // flat keys are re-scoped to the requested role.
        if (!isset($_SESSION['identities'])) {
            if (!empty($_SESSION['logged_in'])) {
                $legacyRole = $_SESSION['role'] ?? 'admin';
                $legacyRole = authIsValidRole($legacyRole) ? $legacyRole : 'admin';
                authSnapshot($legacyRole);
                $_SESSION['active_role'] = $legacyRole;
                $role = $legacyRole;
            }
        }

        $prevActive = $_SESSION['active_role'] ?? null;
        if (authIsValidRole($prevActive) && $prevActive !== $role) {
            authSnapshot($prevActive);
        }

        authLoad($role);
    }
}

/**
 * Store a user (WISP account) identity as the active one.
 */
if (!function_exists('authLoginUser')) {
    function authLoginUser(array $account): void {
        authLoginDelta('user', [
            'logged_in'     => true,
            'role'          => 'user',
            'username'      => $account['name'],
            'account_id'    => (int)$account['id'],
            'account_email' => $account['email'] ?? '',
            'currency'      => $account['currency'] ?: 'TZS',
            'timezone'      => $account['timezone'] ?? null,
            'phone_code'    => $account['phone_code'] ?? '+255',
        ]);
    }
}

/**
 * Store an admin (or superadmin) identity as the active one.
 */
if (!function_exists('authLoginAdmin')) {
    function authLoginAdmin(array $admin): void {
        authLoginDelta($admin['role'] ?: 'admin', [
            'logged_in'  => true,
            'role'       => $admin['role'] ?: 'admin',
            'username'   => $admin['username'] ?? '',
            'admin_id'   => (int)$admin['id'],
            'currency'   => $admin['currency'] ?: 'TZS',
            'timezone'   => $admin['timezone'] ?? null,
            'phone_code' => $admin['phone_code'] ?? '+255',
        ]);
    }
}

/**
 * Apply a login: write the given fields onto the flat session and into the
 * identity backup for $role, and set the active role.
 */
if (!function_exists('authLoginDelta')) {
    function authLoginDelta(string $role, array $delta): void {
        // Put the identity on the flat keys so existing reads keep working.
        foreach ($delta as $k => $v) {
            $_SESSION[$k] = $v;
        }
        // Persist into the namespaced backup for this role.
        authSnapshot($role);
        $_SESSION['active_role'] = $role;

        if (isset($_SESSION['timezone'])) {
            appSetTimezone($_SESSION['timezone']);
        }
    }
}

/**
 * Clear ONLY the currently-active identity (role + its flat keys + its backup),
 * leaving the other identities intact.
 */
if (!function_exists('authLogoutActive')) {
    function authLogoutActive(): void {
        $role = $_SESSION['active_role'] ?? null;
        if (authIsValidRole($role)) {
            unset($_SESSION['identities'][$role]);
        }
        foreach (authIdentityKeys() as $k) {
            unset($_SESSION[$k]);
        }
        unset($_SESSION['active_role']);
    }
}
