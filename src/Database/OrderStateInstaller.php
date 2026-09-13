<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Database;

use Doctrine\DBAL\Connection;

/**
 * Manages custom PrestaShop order states for the DZ COD workflow.
 *
 * Creates custom states during module install, stores their IDs in ps_configuration
 * for runtime lookup, and removes them on uninstall.
 *
 * These states coexist alongside PS's default states (used for Stripe/online payments).
 */
class OrderStateInstaller
{
    /**
     * State definitions: config_key => [name, color, paid, shipped, delivered, pdf_invoice, pdf_delivery, logable]
     *
     * @var array<string, array{name: string, color: string, paid: bool, shipped: bool, delivered: bool, pdf_invoice: bool, pdf_delivery: bool, logable: bool}>
     */
    private const STATES = [
        'DZ_CM_STATE_NOT_CONFIRMED' => [
            'name'         => 'Not Confirmed',
            'color'        => '#FFA500',
            'paid'         => false,
            'shipped'      => false,
            'delivered'    => false,
            'pdf_invoice'  => false,
            'pdf_delivery' => false,
            'logable'      => true,
        ],
        'DZ_CM_STATE_CONFIRMED' => [
            'name'         => 'Confirmed',
            'color'        => '#32CD32',
            'paid'         => false,
            'shipped'      => false,
            'delivered'    => false,
            'pdf_invoice'  => false,
            'pdf_delivery' => false,
            'logable'      => true,
        ],
        'DZ_CM_STATE_BEING_PREPARED' => [
            'name'         => 'Being Prepared',
            'color'        => '#1E90FF',
            'paid'         => false,
            'shipped'      => false,
            'delivered'    => false,
            'pdf_invoice'  => true,
            'pdf_delivery' => false,
            'logable'      => true,
        ],
        'DZ_CM_STATE_SHIPPED' => [
            'name'         => 'Shipped',
            'color'        => '#4169E1',
            'paid'         => false,
            'shipped'      => true,
            'delivered'    => false,
            'pdf_invoice'  => true,
            'pdf_delivery' => true,
            'logable'      => true,
        ],
        'DZ_CM_STATE_DELIVERED' => [
            'name'         => 'Delivered',
            'color'        => '#228B22',
            'paid'         => true,
            'shipped'      => true,
            'delivered'    => true,
            'pdf_invoice'  => true,
            'pdf_delivery' => true,
            'logable'      => true,
        ],
        'DZ_CM_STATE_FAILED_DELIVERY' => [
            'name'         => 'Delivery Failed',
            'color'        => '#DC143C',
            'paid'         => false,
            'shipped'      => false,
            'delivered'    => false,
            'pdf_invoice'  => false,
            'pdf_delivery' => false,
            'logable'      => true,
        ],
        'DZ_CM_STATE_RETURNED' => [
            'name'         => 'Returned to Seller',
            'color'        => '#696969',
            'paid'         => false,
            'shipped'      => false,
            'delivered'    => false,
            'pdf_invoice'  => false,
            'pdf_delivery' => false,
            'logable'      => true,
        ],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {}

    /**
     * Install all custom order states if they don't already exist.
     */
    public function installStates(): array
    {
        $errors = [];

        // Get all language IDs
        $languages = $this->connection->fetchAllAssociative(
            "SELECT id_lang, iso_code FROM `{$this->dbPrefix}lang`"
        );

        foreach (self::STATES as $configKey => $stateDef) {
            try {
                // Check if already installed via ps_configuration
                $existingId = $this->connection->fetchOne(
                    "SELECT value FROM `{$this->dbPrefix}configuration` WHERE name = :name",
                    ['name' => $configKey]
                );

                if ($existingId !== false && $existingId !== null) {
                    // Verify the state row still exists
                    $exists = $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM `{$this->dbPrefix}order_state` WHERE id_order_state = :id",
                        ['id' => (int) $existingId]
                    );
                    if ((int) $exists > 0) {
                        continue; // Already installed, skip
                    }
                }

                // Insert into ps_order_state
                $this->connection->insert($this->dbPrefix . 'order_state', [
                    'invoice'       => $stateDef['pdf_invoice'] ? 1 : 0,
                    'send_email'    => 0,
                    'module_name'   => 'dzcarriermanager',
                    'color'         => $stateDef['color'],
                    'unremovable'   => 0,
                    'hidden'        => 0,
                    'logable'       => $stateDef['logable'] ? 1 : 0,
                    'delivery'      => $stateDef['pdf_delivery'] ? 1 : 0,
                    'shipped'       => $stateDef['shipped'] ? 1 : 0,
                    'paid'          => $stateDef['paid'] ? 1 : 0,
                    'pdf_invoice'   => $stateDef['pdf_invoice'] ? 1 : 0,
                    'pdf_delivery'  => $stateDef['pdf_delivery'] ? 1 : 0,
                    'deleted'       => 0,
                ]);

                $newStateId = (int) $this->connection->lastInsertId();

                // Insert translations for each language
                foreach ($languages as $lang) {
                    $this->connection->insert($this->dbPrefix . 'order_state_lang', [
                        'id_order_state' => $newStateId,
                        'id_lang'        => (int) $lang['id_lang'],
                        'name'           => $stateDef['name'],
                        'template'       => '',
                    ]);
                }

                // Store the ID in ps_configuration for runtime lookup
                // First remove any stale entry
                $this->connection->executeStatement(
                    "DELETE FROM `{$this->dbPrefix}configuration` WHERE name = :name",
                    ['name' => $configKey]
                );

                $this->connection->insert($this->dbPrefix . 'configuration', [
                    'name'       => $configKey,
                    'value'      => (string) $newStateId,
                    'date_add'   => date('Y-m-d H:i:s'),
                    'date_upd'   => date('Y-m-d H:i:s'),
                ]);

            } catch (\Throwable $e) {
                $errors[] = [
                    'key'        => "Failed to install order state '{$stateDef['name']}': " . $e->getMessage(),
                    'parameters' => [],
                    'domain'     => 'Admin.Modules.Notification',
                ];
            }
        }

        return $errors;
    }

    /**
     * Remove all custom order states created by this module.
     */
    public function uninstallStates(): array
    {
        $errors = [];

        foreach (array_keys(self::STATES) as $configKey) {
            try {
                $stateId = $this->connection->fetchOne(
                    "SELECT value FROM `{$this->dbPrefix}configuration` WHERE name = :name",
                    ['name' => $configKey]
                );

                if ($stateId !== false && $stateId !== null) {
                    $stateIdInt = (int) $stateId;

                    // Soft-delete: mark as deleted rather than removing,
                    // so existing orders referencing this state still display correctly
                    $this->connection->update(
                        $this->dbPrefix . 'order_state',
                        ['deleted' => 1],
                        ['id_order_state' => $stateIdInt]
                    );
                }

                // Remove the config entry
                $this->connection->executeStatement(
                    "DELETE FROM `{$this->dbPrefix}configuration` WHERE name = :name",
                    ['name' => $configKey]
                );

            } catch (\Throwable $e) {
                $errors[] = [
                    'key'        => "Failed to uninstall order state for '{$configKey}': " . $e->getMessage(),
                    'parameters' => [],
                    'domain'     => 'Admin.Modules.Notification',
                ];
            }
        }

        return $errors;
    }

    /**
     * Get the PS order_state ID for a given config key.
     * Returns null if not found (module not installed or state removed).
     */
    public static function getStateId(string $configKey): ?int
    {
        // Use PrestaShop's Configuration class for cached lookups at runtime
        if (class_exists('Configuration')) {
            $val = \Configuration::get($configKey);
            return $val !== false ? (int) $val : null;
        }

        return null;
    }

    /**
     * Get all config keys managed by this installer.
     *
     * @return string[]
     */
    public static function getConfigKeys(): array
    {
        return array_keys(self::STATES);
    }
}
