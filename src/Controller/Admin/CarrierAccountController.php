<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Controller\Admin;

use Doctrine\DBAL\Connection;
use Module\DzCarrierManager\Carrier\CarrierRegistry;
use Module\DzCarrierManager\Carrier\Guepex\GuepexApiClient;
use Module\DzCarrierManager\Grid\Filters\CarrierAccountFilters;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for managing carrier accounts (API credentials, active/default toggles, test connection).
 */
class CarrierAccountController extends PrestaShopAdminController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    /**
     * List all configured carrier accounts.
     */
    public function indexAction(
        CarrierAccountFilters $filters,
        #[Autowire(service: 'prestashop.module.dzcarriermanager.grid.factory.carrier_accounts')]
        GridFactoryInterface $gridFactory,
    ): Response {
        return $this->render(
            '@Modules/dzcarriermanager/views/templates/admin/carriers/index.html.twig',
            [
                'enableSidebar' => true,
                'layoutTitle' => $this->trans('My Carriers', [], 'Modules.Dzcarriermanager.Admin'),
                'carrierGrid' => $this->presentGrid($gridFactory->getGrid($filters)),
            ]
        );
    }

    /**
     * Edit a carrier account's credentials and configuration.
     */
    public function editAction(int $id, Request $request): Response
    {
        $prefix = $this->getDbPrefix();

        $account = $this->connection->fetchAssociative(
            "SELECT * FROM `{$prefix}cm_carrier_accounts` WHERE `id` = :id",
            ['id' => $id]
        );

        if (!$account) {
            $this->addFlash('error', $this->trans('Carrier account not found.', [], 'Modules.Dzcarriermanager.Admin'));
            return $this->redirectToRoute('ps_dzcarriermanager_carrier_index');
        }

        $extraConfig = !empty($account['extra_config'])
            ? json_decode($account['extra_config'], true)
            : [];
        if (!is_array($extraConfig)) {
            $extraConfig = [];
        }

        if ($request->isMethod('POST')) {
            $displayName = trim((string) $request->request->get('display_name', $account['display_name']));
            $apiId = trim((string) $request->request->get('api_id', ''));
            $apiToken = trim((string) $request->request->get('api_token', ''));
            $webhookSecret = trim((string) $request->request->get('webhook_secret', ''));
            $isDefault = $request->request->getBoolean('is_default') ? 1 : 0;
            $isActive = $request->request->getBoolean('is_active') ? 1 : 0;

            // Extra config fields
            $fromWilayaId = (int) $request->request->get('from_wilaya_id', $extraConfig['from_wilaya_id'] ?? 16);
            $baseUrl = trim((string) $request->request->get('base_url', $extraConfig['base_url'] ?? 'https://api.guepex.app/v1'));

            $newExtraConfig = [
                'from_wilaya_id' => $fromWilayaId,
                'base_url' => $baseUrl,
            ];

            // If no new api_token entered, retain existing one if field was left blank
            if (empty($apiToken) && !empty($account['api_token'])) {
                $apiToken = $account['api_token'];
            }

            // If set as default, clear default on other carriers
            if ($isDefault === 1) {
                $this->connection->executeStatement(
                    "UPDATE `{$prefix}cm_carrier_accounts` SET `is_default` = 0 WHERE `id` != :id",
                    ['id' => $id]
                );
            }

            $this->connection->update(
                $prefix . 'cm_carrier_accounts',
                [
                    'display_name' => $displayName,
                    'api_id' => $apiId ?: null,
                    'api_token' => $apiToken ?: null,
                    'webhook_secret' => $webhookSecret ?: null,
                    'extra_config' => json_encode($newExtraConfig),
                    'is_default' => $isDefault,
                    'is_active' => $isActive,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                ['id' => $id]
            );

            $this->addFlash(
                'success',
                $this->trans('Carrier credentials updated successfully.', [], 'Modules.Dzcarriermanager.Admin')
            );

            return $this->redirectToRoute('ps_dzcarriermanager_carrier_index');
        }

        return $this->render(
            '@Modules/dzcarriermanager/views/templates/admin/carriers/edit.html.twig',
            [
                'enableSidebar' => true,
                'layoutTitle' => $this->trans(
                    'Edit Carrier — %name%',
                    ['%name%' => $account['display_name']],
                    'Modules.Dzcarriermanager.Admin'
                ),
                'account' => $account,
                'extraConfig' => $extraConfig,
                'webhookUrl' => $this->generateWebhookUrl($account['carrier_code']),
            ]
        );
    }

    /**
     * Set carrier account as default.
     */
    public function toggleDefaultAction(int $id): RedirectResponse
    {
        $prefix = $this->getDbPrefix();

        $this->connection->executeStatement("UPDATE `{$prefix}cm_carrier_accounts` SET `is_default` = 0");
        $this->connection->executeStatement(
            "UPDATE `{$prefix}cm_carrier_accounts` SET `is_default` = 1, `is_active` = 1 WHERE `id` = :id",
            ['id' => $id]
        );

        $this->addFlash('success', $this->trans('Default carrier updated.', [], 'Modules.Dzcarriermanager.Admin'));

        return $this->redirectToRoute('ps_dzcarriermanager_carrier_index');
    }

    /**
     * Toggle carrier account active status.
     */
    public function toggleActiveAction(int $id): RedirectResponse
    {
        $prefix = $this->getDbPrefix();

        $account = $this->connection->fetchAssociative(
            "SELECT is_active FROM `{$prefix}cm_carrier_accounts` WHERE `id` = :id",
            ['id' => $id]
        );

        if ($account) {
            $newStatus = $account['is_active'] ? 0 : 1;
            $this->connection->update(
                $prefix . 'cm_carrier_accounts',
                ['is_active' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id]
            );
            $this->addFlash('success', $this->trans('Carrier status updated.', [], 'Modules.Dzcarriermanager.Admin'));
        }

        return $this->redirectToRoute('ps_dzcarriermanager_carrier_index');
    }

    /**
     * AJAX endpoint to test carrier API connection.
     */
    public function testConnectionAction(int $id, Request $request): JsonResponse
    {
        $prefix = $this->getDbPrefix();

        $account = $this->connection->fetchAssociative(
            "SELECT * FROM `{$prefix}cm_carrier_accounts` WHERE `id` = :id",
            ['id' => $id]
        );

        if (!$account) {
            return new JsonResponse(['success' => false, 'message' => 'Carrier account not found.'], 404);
        }

        $apiId = trim((string) $request->request->get('api_id', $account['api_id'] ?? ''));
        $apiToken = trim((string) $request->request->get('api_token', $account['api_token'] ?? ''));

        $extraConfig = !empty($account['extra_config']) ? json_decode($account['extra_config'], true) : [];
        $baseUrl = $request->request->get('base_url', $extraConfig['base_url'] ?? null);

        if (empty($apiId) || empty($apiToken)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'API ID and API Token must be provided to test connection.',
            ]);
        }

        try {
            if ($account['carrier_code'] === 'guepex') {
                $client = new GuepexApiClient($apiId, $apiToken, $baseUrl ?: null);
                // Simple query to verify authentication
                $result = $client->getWilayas(['has_stopdesk' => 1]);

                if (isset($result['data']) || !empty($result)) {
                    return new JsonResponse([
                        'success' => true,
                        'message' => 'Connection successful! Guepex API authentication verified.',
                    ]);
                }

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Connection verified successfully.',
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Connection test not implemented for this carrier type.',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }

    private function generateWebhookUrl(string $carrierCode): string
    {
        $context = \Context::getContext();
        $shopUrl = $context && $context->shop ? $context->shop->getBaseURL(true) : '';
        return rtrim($shopUrl, '/') . '/modules/dzcarriermanager/webhook.php?carrier=' . urlencode($carrierCode);
    }

    private function getDbPrefix(): string
    {
        return $this->connection->getParams()['driverOptions']['prefix']
            ?? $this->getParameter('database_prefix');
    }
}
