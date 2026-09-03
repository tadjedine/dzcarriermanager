<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Controller\Admin;

use Doctrine\DBAL\Connection;
use Module\DzCarrierManager\Carrier\CarrierRegistry;
use Module\DzCarrierManager\Carrier\Guepex\GuepexParcelStatus;
use Module\DzCarrierManager\Grid\Definition\Factory\ParcelGridDefinitionFactory;
use Module\DzCarrierManager\Grid\Filters\ParcelFilters;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\GridDefinitionFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Main Parcels controller for the DZ Carrier Manager module.
 *
 * Handles:
 * - Grid listing of all PS orders with parcel status
 * - Confirming orders (single + bulk)
 * - Sending confirmed orders to carrier (single + bulk)
 * - Viewing parcel details
 */
class ParcelController extends PrestaShopAdminController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    /**
     * Parcels grid page — shows all PS orders with their parcel/carrier status.
     */
    public function indexAction(
        ParcelFilters $filters,
        #[Autowire(service: 'prestashop.module.dzcarriermanager.grid.factory.parcels')]
        GridFactoryInterface $parcelGridFactory,
    ): Response {
        return $this->render(
            '@Modules/dzcarriermanager/views/templates/admin/parcels/index.html.twig',
            [
                'enableSidebar' => true,
                'layoutTitle' => $this->trans('Parcels', [], 'Modules.Dzcarriermanager.Admin'),
                'parcelGrid' => $this->presentGrid($parcelGridFactory->getGrid($filters)),
            ]
        );
    }

    /**
     * Grid search/filter handler (POST).
     */
    public function searchAction(
        Request $request,
        #[Autowire(service: 'prestashop.module.dzcarriermanager.grid.definition.factory.parcels')]
        GridDefinitionFactoryInterface $parcelGridDefinitionFactory,
    ): RedirectResponse {
        return $this->buildSearchResponse(
            $parcelGridDefinitionFactory,
            $request,
            ParcelGridDefinitionFactory::GRID_ID,
            'ps_dzcarriermanager_parcel_index'
        );
    }

    /**
     * Confirm a single order — creates a cm_parcels row with status 'confirmed'.
     */
    public function confirmAction(int $orderId): RedirectResponse
    {
        try {
            $this->confirmOrder($orderId);
            $this->addFlash(
                'success',
                $this->trans('Order #%id% has been confirmed.', ['%id%' => $orderId], 'Modules.Dzcarriermanager.Admin')
            );
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
    }

    /**
     * Bulk confirm selected orders.
     */
    public function confirmBulkAction(Request $request): RedirectResponse
    {
        $orderIds = $this->getBulkOrderIds($request);

        if (empty($orderIds)) {
            $this->addFlash('warning', $this->trans('No orders selected.', [], 'Modules.Dzcarriermanager.Admin'));
            return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
        }

        $confirmed = 0;
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $this->confirmOrder((int) $orderId);
                $confirmed++;
            } catch (\Exception $e) {
                $errors[] = "Order #{$orderId}: " . $e->getMessage();
            }
        }

        if ($confirmed > 0) {
            $this->addFlash(
                'success',
                $this->trans(
                    '%count% order(s) confirmed successfully.',
                    ['%count%' => $confirmed],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
    }

    /**
     * Send a single confirmed order to the carrier API.
     */
    public function sendToCarrierAction(int $orderId): RedirectResponse
    {
        try {
            $result = $this->sendOrderToCarrier($orderId);

            if ($result['success']) {
                $this->addFlash(
                    'success',
                    $this->trans(
                        'Order #%id% sent to carrier. Tracking: %tracking%',
                        ['%id%' => $orderId, '%tracking%' => $result['tracking'] ?? 'N/A'],
                        'Modules.Dzcarriermanager.Admin'
                    )
                );
            } else {
                $this->addFlash('error', $result['message']);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
    }

    /**
     * Bulk send confirmed orders to carrier.
     * Only confirmed orders (not already sent) will be processed.
     */
    public function sendBulkToCarrierAction(Request $request): RedirectResponse
    {
        $orderIds = $this->getBulkOrderIds($request);

        if (empty($orderIds)) {
            $this->addFlash('warning', $this->trans('No orders selected.', [], 'Modules.Dzcarriermanager.Admin'));
            return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
        }

        $sent = 0;
        $skipped = 0;
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $result = $this->sendOrderToCarrier((int) $orderId);
                if ($result['success']) {
                    $sent++;
                } else {
                    $errors[] = "Order #{$orderId}: " . $result['message'];
                }
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'must be confirmed')) {
                    $skipped++;
                } else {
                    $errors[] = "Order #{$orderId}: " . $e->getMessage();
                }
            }
        }

        if ($sent > 0) {
            $this->addFlash(
                'success',
                $this->trans(
                    '%count% order(s) sent to carrier successfully.',
                    ['%count%' => $sent],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        if ($skipped > 0) {
            $this->addFlash(
                'warning',
                $this->trans(
                    '%count% order(s) skipped — only confirmed orders can be sent to the carrier.',
                    ['%count%' => $skipped],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
    }

    /**
     * View parcel details for an order.
     */
    public function viewAction(int $orderId): Response
    {
        $prefix = $this->getDbPrefix();

        // Get order info
        $order = $this->connection->fetchAssociative(
            "SELECT o.*, 
                    CONCAT(c.firstname, ' ', c.lastname) AS customer_name,
                    c.email AS customer_email,
                    a.address1, a.address2, a.city, a.phone, a.phone_mobile,
                    a.firstname AS addr_firstname, a.lastname AS addr_lastname,
                    s.name AS wilaya
             FROM {$prefix}orders o
             LEFT JOIN {$prefix}customer c ON o.id_customer = c.id_customer
             LEFT JOIN {$prefix}address a ON o.id_address_delivery = a.id_address
             LEFT JOIN {$prefix}state s ON a.id_state = s.id_state
             WHERE o.id_order = :id",
            ['id' => $orderId]
        );

        if (!$order) {
            $this->addFlash('error', $this->trans('Order not found.', [], 'Modules.Dzcarriermanager.Admin'));
            return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
        }

        // Get parcel info (may be null)
        $parcel = $this->connection->fetchAssociative(
            "SELECT p.*, ca.display_name AS carrier_name, ca.carrier_code
             FROM {$prefix}cm_parcels p
             LEFT JOIN {$prefix}cm_carrier_accounts ca ON p.carrier_account_id = ca.id
             WHERE p.order_id = :orderId",
            ['orderId' => $orderId]
        );

        // Get status history
        $history = [];
        if ($parcel) {
            $history = $this->connection->fetchAllAssociative(
                "SELECT * FROM {$prefix}cm_parcel_histories 
                 WHERE parcel_id = :parcelId 
                 ORDER BY occurred_at DESC",
                ['parcelId' => $parcel['id']]
            );
        }

        // Get order products
        $products = $this->connection->fetchAllAssociative(
            "SELECT od.product_name, od.product_quantity, od.total_price_tax_incl
             FROM {$prefix}order_detail od
             WHERE od.id_order = :orderId",
            ['orderId' => $orderId]
        );

        return $this->render(
            '@Modules/dzcarriermanager/views/templates/admin/parcels/view.html.twig',
            [
                'enableSidebar' => true,
                'layoutTitle' => $this->trans(
                    'Parcel — Order #%id%',
                    ['%id%' => $orderId],
                    'Modules.Dzcarriermanager.Admin'
                ),
                'order' => $order,
                'parcel' => $parcel,
                'history' => $history,
                'products' => $products,
                'statusLabel' => $parcel
                    ? GuepexParcelStatus::label($parcel['status'])
                    : GuepexParcelStatus::label(GuepexParcelStatus::NOT_CONFIRMED),
                'statusPhase' => $parcel
                    ? GuepexParcelStatus::phase($parcel['status'])
                    : 'pending',
            ]
        );
    }

    /**
     * Download/redirect to the shipping label PDF for a parcel.
     */
    public function downloadLabelAction(int $orderId): RedirectResponse
    {
        $prefix = $this->getDbPrefix();

        $parcel = $this->connection->fetchAssociative(
            "SELECT label_url FROM {$prefix}cm_parcels WHERE order_id = :orderId",
            ['orderId' => $orderId]
        );

        if (!$parcel || empty($parcel['label_url'])) {
            $this->addFlash(
                'warning',
                $this->trans(
                    'No shipping label available for order #%id%. The order must be sent to the carrier first.',
                    ['%id%' => $orderId],
                    'Modules.Dzcarriermanager.Admin'
                )
            );

            return $this->redirectToRoute('ps_dzcarriermanager_parcel_index');
        }

        return $this->redirect($parcel['label_url']);
    }

    // ════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Confirm a single order — create a cm_parcels row.
     *
     * @throws \RuntimeException If the order doesn't exist or is already confirmed
     */
    private function confirmOrder(int $orderId): void
    {
        $prefix = $this->getDbPrefix();

        // Verify the order exists in PS
        $orderExists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$prefix}orders WHERE id_order = :id",
            ['id' => $orderId]
        );

        if (!$orderExists) {
            throw new \RuntimeException(
                $this->trans('Order #%id% not found.', ['%id%' => $orderId], 'Modules.Dzcarriermanager.Admin')
            );
        }

        // Check if already has a parcel row
        $existingParcel = $this->connection->fetchAssociative(
            "SELECT id, status FROM {$prefix}cm_parcels WHERE order_id = :orderId",
            ['orderId' => $orderId]
        );

        if ($existingParcel && $existingParcel['status'] !== 'not_confirmed') {
            throw new \RuntimeException(
                $this->trans('Order #%id% is already confirmed or dispatched.', ['%id%' => $orderId], 'Modules.Dzcarriermanager.Admin')
            );
        }

        // Read order total
        $orderTotal = $this->connection->fetchOne(
            "SELECT total_paid_tax_incl FROM {$prefix}orders WHERE id_order = :id",
            ['id' => $orderId]
        );

        if ($existingParcel) {
            // Update pre-existing not_confirmed parcel (from website order)
            $this->connection->update($prefix . 'cm_parcels', [
                'status' => 'confirmed',
                'price' => (int) round((float) $orderTotal),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['order_id' => $orderId]);
        } else {
            // Insert new parcel row (PS-native order)
            $this->connection->insert($prefix . 'cm_parcels', [
                'order_id' => $orderId,
                'status' => 'confirmed',
                'price' => (int) round((float) $orderTotal),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Send a confirmed order to the default carrier.
     *
     * @throws \RuntimeException If the order is not confirmed or carrier has no credentials
     * @return array{success: bool, tracking: string|null, message: string}
     */
    private function sendOrderToCarrier(int $orderId): array
    {
        $prefix = $this->getDbPrefix();

        // Get the parcel row — must exist and be in 'confirmed' status
        $parcel = $this->connection->fetchAssociative(
            "SELECT * FROM {$prefix}cm_parcels WHERE order_id = :orderId",
            ['orderId' => $orderId]
        );

        if (!$parcel) {
            throw new \RuntimeException(
                $this->trans(
                    'Order #%id% must be confirmed before sending to carrier.',
                    ['%id%' => $orderId],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        if ($parcel['status'] !== 'confirmed') {
            throw new \RuntimeException(
                $this->trans(
                    'Order #%id% has already been sent to the carrier (status: %status%).',
                    ['%id%' => $orderId, '%status%' => $parcel['status']],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        // Get the default carrier account
        $carrierAccount = $this->connection->fetchAssociative(
            "SELECT * FROM {$prefix}cm_carrier_accounts WHERE is_default = 1 AND is_active = 1 LIMIT 1"
        );

        if (!$carrierAccount) {
            throw new \RuntimeException(
                $this->trans(
                    'No active carrier configured. Go to My Carriers to set up your API credentials.',
                    [],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        if (empty($carrierAccount['api_id']) || empty($carrierAccount['api_token'])) {
            throw new \RuntimeException(
                $this->trans(
                    'Carrier "%name%" has no API credentials configured.',
                    ['%name%' => $carrierAccount['display_name']],
                    'Modules.Dzcarriermanager.Admin'
                )
            );
        }

        // Get the carrier driver
        $carrier = $this->carrierRegistry->get($carrierAccount['carrier_code']);

        // Build order data from PS tables
        $orderData = $this->buildOrderDataForCarrier($orderId, $prefix);

        // Send to carrier API
        $results = $carrier->createParcels([$orderData], [
            'api_id' => $carrierAccount['api_id'],
            'api_token' => $carrierAccount['api_token'],
            'extra_config' => $carrierAccount['extra_config'],
        ]);

        $result = $results[(string) $orderId] ?? ['success' => false, 'message' => 'No response for this order.'];

        if ($result['success']) {
            // Update the parcel row
            $this->connection->update($prefix . 'cm_parcels', [
                'tracking' => $result['tracking'],
                'import_id' => $result['import_id'],
                'label_url' => $result['label_url'],
                'carrier_account_id' => $carrierAccount['id'],
                'status' => $result['initial_status'],
                'status_changed_at' => date('Y-m-d H:i:s'),
                'wilaya_name' => $orderData['wilaya_name'],
                'commune_name' => $orderData['commune_name'],
                'delivery_fee' => (int) ($orderData['shipping_cost'] ?? 0),
                'freeshipping' => (int) ($orderData['freeshipping'] ?? false),
                'weight' => (float) ($orderData['weight'] ?? 0),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['order_id' => $orderId]);

            // Log initial status in history
            $this->connection->insert($prefix . 'cm_parcel_histories', [
                'parcel_id' => (int) $parcel['id'],
                'status' => $result['initial_status'],
                'reason' => 'Parcel created on carrier platform.',
                'occurred_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $result;
    }

    /**
     * Build the order data array needed by the carrier driver.
     */
    private function buildOrderDataForCarrier(int $orderId, string $prefix): array
    {
        $data = $this->connection->fetchAssociative(
            "SELECT o.id_order, o.reference, o.total_paid_tax_incl, o.total_shipping_tax_incl,
                    a.firstname, a.lastname, a.phone, a.phone_mobile,
                    a.address1, a.address2, a.city,
                    s.name AS wilaya_name
             FROM {$prefix}orders o
             LEFT JOIN {$prefix}address a ON o.id_address_delivery = a.id_address
             LEFT JOIN {$prefix}state s ON a.id_state = s.id_state
             WHERE o.id_order = :id",
            ['id' => $orderId]
        );

        if (!$data) {
            throw new \RuntimeException("Order #{$orderId} not found.");
        }

        // Fetch pre-filled delivery details from cm_parcels if available
        $parcel = $this->connection->fetchAssociative(
            "SELECT * FROM {$prefix}cm_parcels WHERE order_id = :orderId",
            ['orderId' => $orderId]
        );

        // Build product list string
        $products = $this->connection->fetchAllAssociative(
            "SELECT product_name, product_quantity
             FROM {$prefix}order_detail
             WHERE id_order = :id",
            ['id' => $orderId]
        );

        $productList = implode(', ', array_map(
            fn(array $p) => $p['product_name'] . ' x' . $p['product_quantity'],
            $products
        ));

        // Calculate total weight
        $totalWeight = $this->connection->fetchOne(
            "SELECT COALESCE(SUM(product_weight * product_quantity), 0)
             FROM {$prefix}order_detail
             WHERE id_order = :id",
            ['id' => $orderId]
        );

        $phone = ($parcel && !empty($parcel['phone']))
            ? $parcel['phone']
            : ($data['phone'] ?: $data['phone_mobile']);

        $wilayaName = ($parcel && !empty($parcel['wilaya_name']))
            ? $parcel['wilaya_name']
            : ($data['wilaya_name'] ?? '');

        $communeName = ($parcel && !empty($parcel['commune_name']))
            ? $parcel['commune_name']
            : ($data['city'] ?? '');

        $deliveryType = ($parcel && !empty($parcel['delivery_type']))
            ? $parcel['delivery_type']
            : 'express_home';

        $centerId = ($parcel && !empty($parcel['center_id']))
            ? (int) $parcel['center_id']
            : null;

        return [
            'order_id' => $orderId,
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'phone' => $phone,
            'address' => trim($data['address1'] . ' ' . ($data['address2'] ?? '')),
            'commune_name' => $communeName,
            'wilaya_name' => $wilayaName,
            'product_list' => $productList ?: 'Commande #' . $orderId,
            'price' => (int) round((float) $data['total_paid_tax_incl']),
            'weight' => max(1.0, (float) $totalWeight),
            'shipping_cost' => (int) round((float) ($data['total_shipping_tax_incl'] ?? 0)),
            'freeshipping' => ((float) ($data['total_shipping_tax_incl'] ?? 0)) == 0,
            'delivery_type' => $deliveryType,
            'center_id' => $centerId,
            'stopdesk_id' => $centerId,
        ];
    }

    /**
     * Extract order IDs from a bulk action request.
     */
    private function getBulkOrderIds(Request $request): array
    {
        return $request->request->all(ParcelGridDefinitionFactory::GRID_ID . '_bulk') ?: [];
    }

    /**
     * Get the database prefix.
     */
    private function getDbPrefix(): string
    {
        return $this->connection->getParams()['driverOptions']['prefix']
            ?? $this->getParameter('database_prefix');
    }
}
