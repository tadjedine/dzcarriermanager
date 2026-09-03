<?php

declare(strict_types=1);

/**
 * DZ Carrier Manager — Public Webhook Reception Endpoint
 *
 * Receives external status updates and events from carrier platforms (e.g. Guepex/Yalidine).
 *
 * URL pattern: https://yourdomain.com/modules/dzcarriermanager/webhook.php?carrier=guepex
 */

// ── 1. Fast Challenge-Response Check (CRC) ─────────────────────────────────
// Guepex periodically validates the webhook endpoint by sending a GET request with
// subscribe and crc_token. It expects the exact crc_token echoed back with a 200 status.
if (isset($_GET['subscribe'], $_GET['crc_token'])) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo (string) $_GET['crc_token'];
    exit(0);
}

// ── 2. Bootstrap PrestaShop ───────────────────────────────────────────────
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', 'admin');
}

$configPath = dirname(__DIR__, 2) . '/config/config.inc.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'PrestaShop configuration not found.']);
    exit(1);
}

// Prevent PrestaShop from issuing a 302 canonical domain redirect when accessed via tunnel/proxy
$originalHost = $_SERVER['HTTP_HOST'] ?? '';
$_SERVER['HTTP_HOST'] = 'prestashop.test';
require_once $configPath;
$_SERVER['HTTP_HOST'] = $originalHost;

// Ensure autoloader is available
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use Module\DzCarrierManager\Carrier\Guepex\GuepexWebhookHandler;

// ── 3. Check HTTP Method ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed. Only POST is accepted for event deliveries.']);
    exit(0);
}

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Empty request body.']);
    exit(0);
}

// ── 4. Determine Carrier & Fetch Account ────────────────────────────────────
$carrierCode = isset($_GET['carrier']) ? trim((string) $_GET['carrier']) : 'guepex';

try {
    if (Context::getContext()->container && Context::getContext()->container->has('doctrine.dbal.default_connection')) {
        $db = Context::getContext()->container->get('doctrine.dbal.default_connection');
    } else {
        $connectionParams = [
            'dbname' => _DB_NAME_,
            'user' => _DB_USER_,
            'password' => _DB_PASSWD_,
            'host' => _DB_SERVER_,
            'driver' => 'pdo_mysql',
            'charset' => 'utf8mb4',
        ];
        $db = \Doctrine\DBAL\DriverManager::getConnection($connectionParams);
    }
    $prefix = _DB_PREFIX_;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection unavailable: ' . $e->getMessage()]);
    exit(1);
}

$carrierAccount = $db->fetchAssociative(
    "SELECT * FROM `{$prefix}cm_carrier_accounts` WHERE `carrier_code` = :code LIMIT 1",
    ['code' => $carrierCode]
);

if (!$carrierAccount) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => sprintf('Carrier "%s" not found or not configured.', $carrierCode)]);
    exit(0);
}

// ── 5. Verify Signature if Secret is Configured ───────────────────────────
$signature = $_SERVER['HTTP_X_YALIDINE_SIGNATURE']
    ?? $_SERVER['X_YALIDINE_SIGNATURE']
    ?? $_SERVER['HTTP_X_GUEPEX_SIGNATURE']
    ?? '';

$webhookSecret = !empty($carrierAccount['webhook_secret']) ? (string) $carrierAccount['webhook_secret'] : null;

$handler = new GuepexWebhookHandler();

if (!empty($webhookSecret)) {
    if (!$handler->validateSignature($rawPayload, $signature, $webhookSecret)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid signature verification.']);
        exit(0);
    }
}

// ── 6. Process Webhook Payload ─────────────────────────────────────────────
try {
    $result = $handler->handle($rawPayload, $db, $prefix);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while processing webhook: ' . $e->getMessage(),
    ]);
}
