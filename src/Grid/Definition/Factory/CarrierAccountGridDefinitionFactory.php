<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Action\GridActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\SubmitRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Type\SimpleGridAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ToggleColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;

/**
 * Defines the Carrier Accounts grid structure.
 */
class CarrierAccountGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    public const GRID_ID = 'dzcarriermanager_carrier_account';

    protected function getId(): string
    {
        return self::GRID_ID;
    }

    protected function getName(): string
    {
        return $this->trans('My Carriers', [], 'Modules.Dzcarriermanager.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add(
                (new DataColumn('id'))
                    ->setName($this->trans('ID', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'id',
                    ])
            )
            ->add(
                (new DataColumn('display_name'))
                    ->setName($this->trans('Carrier', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'display_name',
                    ])
            )
            ->add(
                (new DataColumn('carrier_code'))
                    ->setName($this->trans('Code', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'carrier_code',
                    ])
            )
            ->add(
                (new DataColumn('api_id'))
                    ->setName($this->trans('API ID', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setOptions([
                        'field' => 'api_id',
                    ])
            )
            ->add(
                (new DataColumn('is_default'))
                    ->setName($this->trans('Default', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'is_default',
                    ])
            )
            ->add(
                (new DataColumn('is_active'))
                    ->setName($this->trans('Status', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'is_active',
                    ])
            )
            ->add(
                (new ActionColumn('actions'))
                    ->setName($this->trans('Actions', [], 'Admin.Global'))
                    ->setOptions([
                        'actions' => $this->getRowActions(),
                    ])
            );
    }

    protected function getFilters(): FilterCollection
    {
        return new FilterCollection();
    }

    protected function getGridActions(): GridActionCollection
    {
        return (new GridActionCollection())
            ->add(
                (new SimpleGridAction('common_refresh_list'))
                    ->setName($this->trans('Refresh list', [], 'Admin.Advparameters.Feature'))
                    ->setIcon('refresh')
            );
    }

    private function getRowActions(): RowActionCollection
    {
        return (new RowActionCollection())
            ->add(
                (new LinkRowAction('edit'))
                    ->setName($this->trans('Edit', [], 'Admin.Actions'))
                    ->setIcon('edit')
                    ->setOptions([
                        'route' => 'ps_dzcarriermanager_carrier_edit',
                        'route_param_name' => 'id',
                        'route_param_field' => 'id',
                    ])
            )
            ->add(
                (new SubmitRowAction('toggle_default'))
                    ->setName($this->trans('Set as Default', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setIcon('star')
                    ->setOptions([
                        'method' => 'POST',
                        'route' => 'ps_dzcarriermanager_carrier_toggle_default',
                        'route_param_name' => 'id',
                        'route_param_field' => 'id',
                    ])
            )
            ->add(
                (new SubmitRowAction('toggle_active'))
                    ->setName($this->trans('Toggle Status', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setIcon('toggle_on')
                    ->setOptions([
                        'method' => 'POST',
                        'route' => 'ps_dzcarriermanager_carrier_toggle_active',
                        'route_param_name' => 'id',
                        'route_param_field' => 'id',
                    ])
            );
    }
}
