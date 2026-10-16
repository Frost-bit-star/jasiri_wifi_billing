<?php
/**
 * services/tanguard.php - PHP helper for the TunGuard userspace WireGuard server.
 *
 * Calls the TunGuard HTTP API instead of running `wg` shell commands.
 *
 * Configuration is read from .env (see config.php):
 *   TUNGARD_API_URL  default http://127.0.0.1:9000
 *   TUNGARD_API_KEY  from TunGuard dashboard: Settings -> API Key
 *
 * USAGE:
 *   require_once __DIR__ . '/../services/tanguard.php';
 *   $api = tunGuard();
 *   $api->addPeer($pubKey, '10.100.0.2/32', $deviceId);
 */

require_once __DIR__ . '/../config.php';

class TunGuardAPI
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?string $apiUrl = null, ?string $apiKey = null)
    {
        $apiUrl = $apiUrl ?? (string) env('TUNGARD_API_URL', 'http://127.0.0.1:9000');
        $this->baseUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey ?? (string) env('TUNGARD_API_KEY', '');
    }

    private function authHeader(): string
    {
        if ($this->apiKey === '') {
            return '';
        }
        return 'X-API-Key: ' . $this->apiKey . "\r\n";
    }

    private function request(string $method, string $path, ?array $data = null): ?array
    {
        $url = $this->baseUrl . $path;

        $options = [
            'method' => $method,
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n" . $this->authHeader(),
        ];

        if ($data !== null) {
            $options['content'] = json_encode($data);
        }

        $ctx = stream_context_create(['http' => $options]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return null;
        }

        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function get(string $path): ?array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $data = []): ?array
    {
        return $this->request('POST', $path, $data);
    }

    public function isHealthy(): bool
    {
        $r = $this->get('/api/health');
        return isset($r['status']) && $r['status'] === 'ok';
    }

    public function getServerPublicKey(): ?string
    {
        $r = $this->get('/api/server_key');
        if (!$r || !isset($r['public_key'])) {
            return null;
        }
        return $r['public_key'];
    }

    public function getServerListenPort(): ?int
    {
        $r = $this->get('/api/server_key');
        if (!$r || !isset($r['listen_port'])) {
            return null;
        }
        return intval($r['listen_port']);
    }

    public function addPeer(
        string $publicKey,
        string $allowedIP,
        string $deviceId = '',
        string $deviceName = '',
        string $psk = ''
    ): bool {
        $r = $this->post('/api/peer/add', [
            'public_key'    => $publicKey,
            'allowed_ip'    => $allowedIP,
            'device_id'     => $deviceId,
            'device_name'   => $deviceName,
            'preshared_key' => $psk,
        ]);
        return isset($r['success']) && $r['success'] === true;
    }

    public function removePeer(string $publicKey): bool
    {
        $r = $this->post('/api/peer/remove', [
            'public_key' => $publicKey,
        ]);
        return isset($r['success']) && $r['success'] === true;
    }

    public function getStatus(): ?array
    {
        return $this->get('/api/status');
    }

    public function getPeers(): array
    {
        $r = $this->get('/api/peers');
        return $r['peers'] ?? [];
    }

    /**
     * Map of public key => latest handshake unix timestamp (0 if never).
     */
    public function getPeerHandshakes(): array
    {
        $status = $this->getStatus();
        if (!$status || !isset($status['peers']) || !is_array($status['peers'])) {
            return [];
        }

        $peers = [];
        foreach ($status['peers'] as $peer) {
            $key = $peer['public_key'] ?? '';
            if ($key === '') {
                continue;
            }
            $peers[$key] = intval($peer['latest_handshake'] ?? 0);
        }
        return $peers;
    }

    public function peerExists(string $publicKey): bool
    {
        foreach ($this->getPeers() as $peer) {
            if (($peer['public_key'] ?? '') === $publicKey) {
                return true;
            }
        }
        return false;
    }

    public function hasRecentHandshake(string $publicKey, int $within = 180): bool
    {
        $peers = $this->getPeerHandshakes();
        if (!isset($peers[$publicKey]) || $peers[$publicKey] <= 0) {
            return false;
        }
        return (time() - $peers[$publicKey]) < $within;
    }
}

if (!function_exists('tunGuard')) {
    function tunGuard(): TunGuardAPI
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new TunGuardAPI();
        }
        return $instance;
    }
}
