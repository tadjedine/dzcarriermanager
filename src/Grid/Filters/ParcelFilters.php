<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Grid\Filters;

use Module\DzCarrierManager\Grid\Definition\Factory\ParcelGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;

/**
 * Default filter values for the Parcels grid.
 */
class ParcelFilters extends Filters
{
    protected $filterId = ParcelGridDefinitionFactory::GRID_ID;

    /**
     * {@inheritdoc}
     */
    public static function getDefaults(): array
    {
        return [
            'limit' => 20,
            'offset' => 0,
            'orderBy' => 'id_order',
            'sortOrder' => 'desc',
            'filters' => [],
        ];
    }
}
