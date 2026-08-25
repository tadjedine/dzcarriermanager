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
        $tabNames = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tabNames[$lang['locale']] = $this->trans(
                'DZ Carrier Manager',
                [],
                'Modules.Dzcarriermanager.Admin',
                $lang['locale']
            );
        }

        $this->tabs = [
            [
                'route_name' => 'ps_dzcarriermanager_parcel_index',
                'class_name' => 'AdminDzCarrierManagerParcels',
                'visible' => true,
                'name' => $tabNames,
                'icon' => 'local_shipping',
                'parent_class_name' => 'AdminParentShipping',
            ],
        ];
    }

    /**
     * Module installation: create DB tables and seed default carrier.
     */
    public function install(): bool
    {
        return $this->installTables()
            && parent::install()
            && $this->seedDefaultCarriers();
    }

    /**
     * Module uninstallation: drop DB tables.
     */
    public function uninstall(): bool
    {
        return $this->removeTables() && parent::uninstall();
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
}
