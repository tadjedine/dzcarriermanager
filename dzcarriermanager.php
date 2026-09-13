<?php
/**
 * DZ Carrier Manager — PrestaShop Module
 *
 * Manage Algerian carrier integrations (Guepex, etc.) directly
 * from the PrestaShop back office. Confirm orders, create parcels
 * in bulk, track shipments via webhooks.
 *
 * @author  Tadjedine
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
declare(strict_types=1);

use Module\DzCarrierManager\Database\ModuleInstaller;
use Module\DzCarrierManager\Database\OrderStateInstaller;

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class dzcarriermanager extends Module
{
    public function __construct()
    {
        $this->name = 'dzcarriermanager';
        $this->author = 'Tadjedine';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('DZ Carrier Manager', [], 'Modules.Dzcarriermanager.Admin');
        $this->description = $this->trans(
            'Manage Algerian carrier integrations — confirm orders, create parcels, track shipments.',
            [],
            'Modules.Dzcarriermanager.Admin'
        );

        // Register BO tabs under Shipping
        // @see https://devdocs.prestashop-project.org/9/modules/concepts/controllers/admin-controllers/tabs/
        $mainTabNames = [];
        $parcelsTabNames = [];
        $carriersTabNames = [];
        foreach (Language::getLanguages(true) as $lang) {
            $mainTabNames[$lang['locale']] = $this->trans(
                'DZ Carrier Manager',
                [],
                'Modules.Dzcarriermanager.Admin',
                $lang['locale']
            );
            $parcelsTabNames[$lang['locale']] = $this->trans(
                'Parcels',
                [],
                'Modules.Dzcarriermanager.Admin',
                $lang['locale']
            );
            $carriersTabNames[$lang['locale']] = $this->trans(
                'My Carriers',
                [],
                'Modules.Dzcarriermanager.Admin',
                $lang['locale']
            );
        }

        $this->tabs = [
            [
                'class_name' => 'AdminDzCarrierManager',
                'visible' => true,
                'name' => $mainTabNames,
                'icon' => 'local_shipping',
                'parent_class_name' => 'AdminParentShipping',
            ],
            [
                'route_name' => 'ps_dzcarriermanager_parcel_index',
                'class_name' => 'AdminDzCarrierManagerParcels',
                'visible' => true,
                'name' => $parcelsTabNames,
                'icon' => 'local_shipping',
                'parent_class_name' => 'AdminDzCarrierManager',
            ],
            [
                'route_name' => 'ps_dzcarriermanager_carrier_index',
                'class_name' => 'AdminDzCarrierManagerCarriers',
                'visible' => true,
                'name' => $carriersTabNames,
                'icon' => 'tune',
                'parent_class_name' => 'AdminDzCarrierManager',
            ],
        ];
    }

    /**
     * Module installation: create DB tables, register hooks, and seed default carrier.
     */
    public function install(): bool
    {
        return $this->installTables()
            && $this->installOrderStates()
            && parent::install()
            && $this->registerHook('displayAdminOrderTabLink')
            && $this->registerHook('displayAdminOrderTabContent')
            && $this->seedDefaultCarriers();
    }

    /**
     * Module uninstallation: drop DB tables.
     */
    public function uninstall(): bool
    {
        $this->uninstallOrderStates();
        return $this->removeTables() && parent::uninstall();
    }

    /**
     * Hook: display tab link on PS order detail page.
     */
    public function hookDisplayAdminOrderTabLink(array $params): string
    {
        $orderId = (int) ($params['id_order'] ?? 0);
        if ($orderId <= 0) {
            return '';
        }

        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;
        $parcel = $db->getRow('SELECT tracking, status FROM `' . $prefix . 'cm_parcels` WHERE `order_id` = ' . $orderId);

        try {
            return $this->get('twig')->render(
                '@Modules/dzcarriermanager/views/templates/admin/hooks/order_tab_link.html.twig',
                [
                    'parcel' => $parcel,
                    'orderId' => $orderId,
                ]
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Hook: display tab content on PS order detail page.
     */
    public function hookDisplayAdminOrderTabContent(array $params): string
    {
        $orderId = (int) ($params['id_order'] ?? 0);
        if ($orderId <= 0) {
            return '';
        }

        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $order = $db->getRow(
            'SELECT o.id_order, o.reference, a.city, s.name AS wilaya
             FROM `' . $prefix . 'orders` o
             LEFT JOIN `' . $prefix . 'address` a ON o.id_address_delivery = a.id_address
             LEFT JOIN `' . $prefix . 'state` s ON a.id_state = s.id_state
             WHERE o.id_order = ' . $orderId
        );

        $parcel = $db->getRow(
            'SELECT p.*, ca.display_name AS carrier_name
             FROM `' . $prefix . 'cm_parcels` p
             LEFT JOIN `' . $prefix . 'cm_carrier_accounts` ca ON p.carrier_account_id = ca.id
             WHERE p.order_id = ' . $orderId
        );

        $history = [];
        if ($parcel) {
            $history = $db->executeS(
                'SELECT * FROM `' . $prefix . 'cm_parcel_histories` WHERE `parcel_id` = ' . (int) $parcel['id'] . ' ORDER BY `occurred_at` DESC'
            );
        }

        try {
            return $this->get('twig')->render(
                '@Modules/dzcarriermanager/views/templates/admin/hooks/order_tab_content.html.twig',
                [
                    'orderId' => $orderId,
                    'order' => $order ?: [],
                    'parcel' => $parcel,
                    'history' => $history ?: [],
                    'statusLabel' => $parcel
                        ? \Module\DzCarrierManager\Carrier\Guepex\GuepexParcelStatus::label($parcel['status'])
                        : 'Not Confirmed',
                    'statusPhase' => $parcel
                        ? \Module\DzCarrierManager\Carrier\Guepex\GuepexParcelStatus::phase($parcel['status'])
                        : 'pending',
                ]
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Redirect module "Configure" link to the parcels page.
     */
    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminDzCarrierManagerParcels')
        );
    }

    // ── Private helpers ─────────────────────────────────────────

    private function installTables(): bool
    {
        $installer = $this->getInstaller();
        $errors = $installer->createTables();

        return empty($errors);
    }

    private function removeTables(): bool
    {
        $installer = $this->getInstaller();
        $errors = $installer->dropTables();

        return empty($errors);
    }

    /**
     * Seed the cm_carrier_accounts table with default carrier entries.
     * One row per supported carrier — credentials left empty for the seller to fill in.
     */
    private function seedDefaultCarriers(): bool
    {
        try {
            $connection = $this->get('doctrine.dbal.default_connection');
            $prefix = $this->getContainer()->getParameter('database_prefix');

            $table = $prefix . 'cm_carrier_accounts';

            // Check if already seeded (idempotent)
            $existing = $connection->fetchOne("SELECT COUNT(*) FROM `{$table}`");
            if ((int) $existing > 0) {
                return true;
            }

            $connection->insert($table, [
                'carrier_code' => 'guepex',
                'display_name' => 'Guepex',
                'is_default' => 1,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Exception $e) {
            // During install, container might not be available — try raw approach
            try {
                $db = Db::getInstance();
                $prefix = _DB_PREFIX_;
                $table = $prefix . 'cm_carrier_accounts';

                $db->insert('cm_carrier_accounts', [
                    'carrier_code' => 'guepex',
                    'display_name' => 'Guepex',
                    'is_default' => 1,
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                return true;
            } catch (\Exception $e2) {
                return false;
            }
        }
    }

    private function getInstaller(): ModuleInstaller
    {
        try {
            $installer = $this->get(ModuleInstaller::class);
        } catch (\Exception) {
            $installer = null;
        }

        // During install the service container might not have our services yet
        if (!$installer) {
            $installer = new ModuleInstaller(
                $this->get('doctrine.dbal.default_connection'),
                $this->getContainer()->getParameter('database_prefix')
            );
        }

        return $installer;
    }

    private function getOrderStateInstaller(): OrderStateInstaller
    {
        return new OrderStateInstaller(
            $this->get('doctrine.dbal.default_connection'),
            $this->getContainer()->getParameter('database_prefix')
        );
    }

    private function installOrderStates(): bool
    {
        try {
            $errors = $this->getOrderStateInstaller()->installStates();
            return empty($errors);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function uninstallOrderStates(): void
    {
        try {
            $this->getOrderStateInstaller()->uninstallStates();
        } catch (\Throwable) {
            // Best effort — don't block uninstall
        }
    }
}

