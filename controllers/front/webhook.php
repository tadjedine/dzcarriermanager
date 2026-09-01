<?php

declare(strict_types=1);

use Module\DzCarrierManager\Carrier\Guepex\GuepexWebhookHandler;

/**
 * DZ Carrier Manager — Front Controller Webhook Reception
 *
 * Accessible via:
 * - https://yourdomain.com/module/dzcarriermanager/webhook?carrier=guepex
 * - https://yourdomain.com/index.php?fc=module&module=dzcarriermanager&controller=webhook&carrier=guepex
 */
class DzCarrierManagerWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;

    public function init(): void
    {
        // ── 1. Fast Challenge-Response Check (CRC) ──────────────
        if (isset($_GET['subscribe'], $_GET['crc_token'])) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(200);
            echo (string) $_GET['crc_token'];
            exit(0);
        }

        parent::init();
    }

    public function postProcess(): void
    {
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

        $carrierCode = isset($_GET['carrier']) ? trim((string) $_GET['carrier']) : 'guepex';

        try {
            if ($this->container && $this->container->has('doctrine.dbal.default_connection')) {
                $db = $this->container->get('doctrine.dbal.default_connection');
            } elseif (Context::getContext()->container) {
                $db = Context::getContext()->container->get('doctrine.dbal.default_connection');
            } else {
                require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
                require_once _PS_ROOT_DIR_ . '/app/FrontKernel.php';
                $env = defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? 'dev' : 'prod';
                $kernel = new \FrontKernel($env, defined('_PS_MODE_DEV_') && _PS_MODE_DEV_);
                $kernel->boot();
                $db = $kernel->getContainer()->get('doctrine.dbal.default_connection');
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

        try {
            $result = $handler->handle($rawPayload, $db, $prefix);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($result);
            exit(0);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'An error occurred while processing webhook: ' . $e->getMessage(),
            ]);
            exit(1);
        }
    }
}
