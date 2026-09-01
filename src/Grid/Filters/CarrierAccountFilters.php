<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Grid\Filters;

use Module\DzCarrierManager\Grid\Definition\Factory\CarrierAccountGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;

/**
 * Default filter values for the Carrier Accounts grid.
 */
class CarrierAccountFilters extends Filters
{
    protected $filterId = CarrierAccountGridDefinitionFactory::GRID_ID;

    /**
     * {@inheritdoc}
     */
    public static function getDefaults(): array
    {
        return [
            'limit' => 20,
            'offset' => 0,
            'orderBy' => 'id',
            'sortOrder' => 'asc',
            'filters' => [],
        ];
    }
}
