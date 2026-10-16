<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/mikrotik_api.php';

function wirelessJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getRouter($db, $routerId) {
    $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id AND type = 'mikrotik'");
    $stmt->execute([':id' => $routerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function connectToRouter($router) {
    $apiIP = !empty($router['wireguard_ip']) ? $router['wireguard_ip'] : $router['ip'];
    $apiPort = intval($router['port'] ?: 8729);
    $api = new MikroTikAPI($apiIP, $apiPort, 'onelink-api', $router['password'] ?? '');
    $api->connect();
    return $api;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'get';
    $routerId = $_GET['router_id'] ?? null;

    if ($action === 'get') {
        if (!$routerId) {
            wirelessJson(['error' => 'router_id required'], 400);
        }

        $router = getRouter($db, $routerId);
        if (!$router) {
            wirelessJson(['error' => 'Router not found'], 404);
        }

        try {
            $api = connectToRouter($router);
            $ssid = $api->getWirelessSsid();
            $securityProfile = $api->getWirelessSecurityProfile();
            $api->close();

            wirelessJson([
                'success' => true,
                'reachable' => true,
                'ssid' => $ssid ?: ($router['ssid'] ?? ''),
                'security_profile' => $securityProfile,
                'saved_ssid' => $router['ssid'] ?? '',
            ]);
        } catch (Exception $e) {
            wirelessJson([
                'success' => true,
                'reachable' => false,
                'ssid' => $router['ssid'] ?? '',
                'security_profile' => '',
                'saved_ssid' => $router['ssid'] ?? '',
                'message' => 'Router unreachable, using saved config',
            ]);
        }
    }

    wirelessJson(['error' => 'Invalid action'], 400);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'configure') {
        $routerId = intval($input['router_id'] ?? 0);
        $ssid = trim($input['ssid'] ?? '');

        if (!$routerId || empty($ssid)) {
            wirelessJson(['error' => 'router_id and ssid required'], 400);
        }

        if (strlen($ssid) > 32) {
            wirelessJson(['error' => 'SSID must be 32 characters or less'], 400);
        }

        $router = getRouter($db, $routerId);
        if (!$router) {
            wirelessJson(['error' => 'Router not found'], 404);
        }

        try {
            $api = connectToRouter($router);
            $api->setWirelessSsid($ssid, 'ap-bridge');
            $api->close();

            $stmt = $db->prepare("UPDATE routers SET ssid = :ssid WHERE id = :id");
            $stmt->execute([':ssid' => $ssid, ':id' => $routerId]);

            $actualSsid = '';
            try {
                $api = connectToRouter($router);
                $actualSsid = $api->getWirelessSsid();
                $api->close();
            } catch (Exception $e) {
                $actualSsid = $ssid;
            }

            wirelessJson([
                'success' => true,
                'message' => "Wireless configured: $ssid",
                'ssid' => $actualSsid ?: $ssid,
            ]);
        } catch (Exception $e) {
            $stmt = $db->prepare("UPDATE routers SET ssid = :ssid WHERE id = :id");
            $stmt->execute([':ssid' => $ssid, ':id' => $routerId]);
            wirelessJson([
                'success' => true,
                'message' => "SSID saved: $ssid (router offline, will apply on next connection)",
                'ssid' => $ssid,
            ]);
        }
    }

    wirelessJson(['error' => 'Invalid action'], 400);
}

wirelessJson(['error' => 'Invalid request method'], 400);
