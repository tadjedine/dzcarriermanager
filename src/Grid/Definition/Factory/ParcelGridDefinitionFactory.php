<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\BulkActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;
use PrestaShop\PrestaShop\Core\Grid\Action\GridActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\SubmitRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Type\SimpleGridAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\BulkActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Defines the Parcels grid structure: columns, filters, row actions, bulk actions.
 *
 * This grid shows ALL PrestaShop orders with their carrier/parcel status.
 * Orders without a cm_parcels row appear as "Not Confirmed".
 */
class ParcelGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    public const GRID_ID = 'dzcarriermanager_parcel';

    protected function getId(): string
    {
        return self::GRID_ID;
    }

    protected function getName(): string
    {
        return $this->trans('Parcels', [], 'Modules.Dzcarriermanager.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add(
                (new BulkActionColumn('bulk'))
                    ->setOptions([
                        'bulk_field' => 'id_order',
                    ])
            )
            ->add(
                (new DataColumn('id_order'))
                    ->setName($this->trans('Order', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'id_order',
                    ])
            )
            ->add(
                (new DataColumn('reference'))
                    ->setName($this->trans('Reference', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'reference',
                    ])
            )
            ->add(
                (new DataColumn('customer_name'))
                    ->setName($this->trans('Customer', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'customer_name',
                    ])
            )
            ->add(
                (new DataColumn('phone'))
                    ->setName($this->trans('Phone', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'phone',
                    ])
            )
            ->add(
                (new DataColumn('total_paid'))
                    ->setName($this->trans('Total', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'total_paid',
                    ])
            )
            ->add(
                (new DataColumn('wilaya'))
                    ->setName($this->trans('Wilaya', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setOptions([
                        'field' => 'wilaya',
                    ])
            )
            ->add(
                (new DataColumn('city'))
                    ->setName($this->trans('City', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'city',
                    ])
            )
            ->add(
                (new DataColumn('delivery_type'))
                    ->setName($this->trans('Delivery', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setOptions([
                        'field' => 'delivery_type',
                    ])
            )
            ->add(
                (new DataColumn('parcel_status'))
                    ->setName($this->trans('Status', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'parcel_status',
                    ])
            )
            ->add(
                (new DataColumn('tracking'))
                    ->setName($this->trans('Tracking', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setOptions([
                        'field' => 'tracking',
                    ])
            )
            ->add(
                (new DataColumn('date_add'))
                    ->setName($this->trans('Date', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'date_add',
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
        return (new FilterCollection())
            ->add(
                (new Filter('id_order', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Order ID', [], 'Modules.Dzcarriermanager.Admin')],
                    ])
                    ->setAssociatedColumn('id_order')
            )
            ->add(
                (new Filter('reference', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Reference', [], 'Admin.Global')],
                    ])
                    ->setAssociatedColumn('reference')
            )
            ->add(
                (new Filter('customer_name', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Customer', [], 'Admin.Global')],
                    ])
                    ->setAssociatedColumn('customer_name')
            )
            ->add(
                (new Filter('phone', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Phone', [], 'Admin.Global')],
                    ])
                    ->setAssociatedColumn('phone')
            )
            ->add(
                (new Filter('parcel_status', ChoiceType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'placeholder' => $this->trans('All', [], 'Admin.Global'),
                        'choices' => [
                            $this->trans('Not Confirmed', [], 'Modules.Dzcarriermanager.Admin') => 'not_confirmed',
                            $this->trans('Confirmed', [], 'Modules.Dzcarriermanager.Admin') => 'confirmed',
                            $this->trans('Shipped', [], 'Modules.Dzcarriermanager.Admin') => 'shipping',
                            $this->trans('Delivered', [], 'Modules.Dzcarriermanager.Admin') => 'delivered',
                            $this->trans('Failed', [], 'Modules.Dzcarriermanager.Admin') => 'failed',
                            $this->trans('Returned', [], 'Modules.Dzcarriermanager.Admin') => 'returned',
                        ],
                    ])
                    ->setAssociatedColumn('parcel_status')
            )
            ->add(
                (new Filter('tracking', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Tracking #', [], 'Modules.Dzcarriermanager.Admin')],
                    ])
                    ->setAssociatedColumn('tracking')
            )
            ->add(
                (new Filter('actions', SearchAndResetType::class))
                    ->setTypeOptions([
                        'reset_route' => 'admin_common_reset_search_by_filter_id',
                        'reset_route_params' => ['filterId' => self::GRID_ID],
                        'redirect_route' => 'ps_dzcarriermanager_parcel_index',
                    ])
                    ->setAssociatedColumn('actions')
            );
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

    protected function getBulkActions(): BulkActionCollection
    {
        return (new BulkActionCollection())
            ->add(
                (new SubmitBulkAction('confirm_bulk'))
                    ->setName($this->trans('Confirm selected', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setOptions([
                        'submit_route' => 'ps_dzcarriermanager_parcel_bulk_confirm',
                        'confirm_message' => $this->trans(
                            'Confirm the selected orders?',
                            [],
                            'Modules.Dzcarriermanager.Admin'
                        ),
                    ])
            )
            ->add(
                (new SubmitBulkAction('send_bulk'))
                    ->setName($this->trans('Send selected to carrier', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setOptions([
                        'submit_route' => 'ps_dzcarriermanager_parcel_bulk_send',
                        'confirm_message' => $this->trans(
                            'Send the selected confirmed orders to the carrier? Only confirmed orders will be processed.',
                            [],
                            'Modules.Dzcarriermanager.Admin'
                        ),
                    ])
            );
    }

    private function getRowActions(): RowActionCollection
    {
        return (new RowActionCollection())
            ->add(
                (new SubmitRowAction('confirm'))
                    ->setName($this->trans('Confirm', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setIcon('check_circle')
                    ->setOptions([
                        'method' => 'POST',
                        'route' => 'ps_dzcarriermanager_parcel_confirm',
                        'route_param_name' => 'orderId',
                        'route_param_field' => 'id_order',
                        'confirm_message' => $this->trans(
                            'Confirm this order?',
                            [],
                            'Modules.Dzcarriermanager.Admin'
                        ),
                    ])
            )
            ->add(
                (new SubmitRowAction('send'))
                    ->setName($this->trans('Send to carrier', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setIcon('local_shipping')
                    ->setOptions([
                        'method' => 'POST',
                        'route' => 'ps_dzcarriermanager_parcel_send',
                        'route_param_name' => 'orderId',
                        'route_param_field' => 'id_order',
                        'confirm_message' => $this->trans(
                            'Send this order to the carrier?',
                            [],
                            'Modules.Dzcarriermanager.Admin'
                        ),
                    ])
            )
            ->add(
                (new LinkRowAction('view'))
                    ->setName($this->trans('View', [], 'Admin.Actions'))
                    ->setIcon('visibility')
                    ->setOptions([
                        'route' => 'ps_dzcarriermanager_parcel_view',
                        'route_param_name' => 'orderId',
                        'route_param_field' => 'id_order',
                    ])
            )
            ->add(
                (new LinkRowAction('label'))
                    ->setName($this->trans('Download Label', [], 'Modules.Dzcarriermanager.Admin'))
                    ->setIcon('print')
                    ->setOptions([
                        'route' => 'ps_dzcarriermanager_parcel_label',
                        'route_param_name' => 'orderId',
                        'route_param_field' => 'id_order',
                    ])
            );
    }
}
