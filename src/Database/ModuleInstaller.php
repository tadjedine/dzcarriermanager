<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Handles database table creation and removal during module install/uninstall.
 *
 * Uses Doctrine DBAL (not ORM entities) because the entity mapping
 * is not yet available during the install lifecycle.
 */
class ModuleInstaller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {}

    /**
     * Create all module tables from the install.sql file.
     *
     * @return array<int, array{key: string, parameters: array, domain: string}> Errors (empty = success)
     */
    public function createTables(): array
    {
        $errors = [];
        $sqlFile = dirname(__DIR__, 2) . '/Resources/data/install.sql';

        if (!file_exists($sqlFile)) {
            $errors[] = [
                'key' => 'SQL install file not found: ' . $sqlFile,
                'parameters' => [],
                'domain' => 'Admin.Modules.Notification',
            ];
            return $errors;
        }

        $sqlContent = file_get_contents($sqlFile);
        $sqlQueries = preg_split('/\r\n|\r|\n/', $sqlContent);
        $sqlQueries = str_replace('PREFIX_', $this->dbPrefix, $sqlQueries);

        foreach ($sqlQueries as $query) {
            $query = trim($query);
            if (empty($query)) {
                continue;
            }

            try {
                $this->connection->executeStatement($query);
            } catch (DBALException $e) {
                $errors[] = [
                    'key' => $e->getMessage(),
                    'parameters' => [],
                    'domain' => 'Admin.Modules.Notification',
                ];
            }
        }

        return $errors;
    }

    /**
     * Drop all module tables.
     *
     * @return array<int, array{key: string, parameters: array, domain: string}> Errors (empty = success)
     */
    public function dropTables(): array
    {
        $errors = [];
        // Order matters: histories has FK to parcels
        $tables = [
            'cm_parcel_histories',
            'cm_parcels',
            'cm_carrier_accounts',
        ];

        foreach ($tables as $table) {
            $sql = 'DROP TABLE IF EXISTS `' . $this->dbPrefix . $table . '`';
            try {
                $this->connection->executeStatement($sql);
            } catch (DBALException $e) {
                $errors[] = [
                    'key' => $e->getMessage(),
                    'parameters' => [],
                    'domain' => 'Admin.Modules.Notification',
                ];
            }
        }

        return $errors;
    }
}
