<?php
// config.php - loads .env and exposes app-wide configuration.
//
// Every entry point should `require_once` this file before using appName(),
// appUrl(), appHost(), env(), or any TunGuard settings.

if (defined('ONELINK_CONFIG_LOADED')) {
    return;
}
define('ONELINK_CONFIG_LOADED', true);

/**
 * Minimal .env loader. Values already present in the real environment win.
 */
function onelink_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) === false) {
            putenv("$key=$value");
        }
        $_ENV[$key] = $value;
        if (!isset($_SERVER[$key])) {
            $_SERVER[$key] = $value;
        }
    }
}

onelink_load_env(__DIR__ . '/.env');

if (!function_exists('env')) {
    /**
     * Read an environment value, treating empty strings as "unset".
     */
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('appName')) {
    function appName(): string
    {
        return (string) env('APP_NAME', 'OneLink');
    }
}

if (!function_exists('appScheme')) {
    function appScheme(): string
    {
        $scheme = parse_url((string) env('APP_URL', ''), PHP_URL_SCHEME);
        if ($scheme) {
            return $scheme;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (
            $host === '' ||
            str_starts_with($host, '127.') ||
            str_starts_with($host, 'localhost') ||
            preg_match('/^192\.168\./', $host) ||
            preg_match('/^10\./', $host)
        ) {
            return 'http';
        }
        return 'https';
    }
}

if (!function_exists('appHost')) {
    /**
     * Host used for internal/provisioning links. Prefers APP_URL from .env and
     * falls back to the current request host.
     */
    function appHost(): string
    {
        $host = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
        if (!empty($host)) {
            return $host;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            return $host;
        }
        return 'localhost';
    }
}

if (!function_exists('appUrl')) {
    /**
     * Absolute URL rooted at APP_URL. With no path, returns the base URL.
     */
    function appUrl(string $path = ''): string
    {
        $base = rtrim((string) env('APP_URL', ''), '/');
        if ($base === '') {
            $base = appScheme() . '://' . appHost();
        }

        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }
}
