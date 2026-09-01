<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Module\DzCarrierManager\Carrier\Guepex\GuepexParcelStatus;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Query builder for the Parcels grid.
 *
 * Joins ps_orders with ps_customer, ps_address, ps_state, and
 * cm_parcels (LEFT JOIN) to show all orders with their parcel status.
 * Orders without a cm_parcels row appear as "Not Confirmed".
 */
class ParcelQueryBuilder extends AbstractDoctrineQueryBuilder
{
    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
    ) {
        parent::__construct($connection, $dbPrefix);
    }

    /**
     * Build the main data query for the grid.
     */
    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getQueryBuilder($searchCriteria->getFilters());
        $qb->select(
            'o.id_order',
            'o.reference',
            'o.total_paid_tax_incl AS total_paid',
            'o.date_add',
            'CONCAT(c.firstname, \' \', c.lastname) AS customer_name',
            'a.city',
            'COALESCE(NULLIF(p.phone, \'\'), NULLIF(a.phone, \'\'), a.phone_mobile) AS phone',
            's.name AS wilaya',
            'p.id AS parcel_id',
            'p.tracking',
            'p.carrier_account_id',
            'COALESCE(p.delivery_type, \'home\') AS delivery_type',
            'COALESCE(p.status, \'not_confirmed\') AS parcel_status'
        );

        $this->searchCriteriaApplicator
            ->applySorting($searchCriteria, $qb)
            ->applyPagination($searchCriteria, $qb);

        return $qb;
    }

    /**
     * Build the count query for pagination.
     */
    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getQueryBuilder($searchCriteria->getFilters());
        $qb->select('COUNT(DISTINCT o.id_order)');

        return $qb;
    }

    /**
     * Build the base query with joins and filters.
     */
    private function getQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from($this->dbPrefix . 'orders', 'o')
            ->leftJoin('o', $this->dbPrefix . 'customer', 'c', 'o.id_customer = c.id_customer')
            ->leftJoin('o', $this->dbPrefix . 'address', 'a', 'o.id_address_delivery = a.id_address')
            ->leftJoin('a', $this->dbPrefix . 'state', 's', 'a.id_state = s.id_state')
            ->leftJoin('o', $this->dbPrefix . 'cm_parcels', 'p', 'o.id_order = p.order_id');

        // Apply filters
        foreach ($filters as $name => $value) {
            if (empty($value) && $value !== '0') {
                continue;
            }

            switch ($name) {
                case 'id_order':
                    $qb->andWhere('o.id_order = :id_order')
                        ->setParameter('id_order', (int) $value);
                    break;

                case 'reference':
                    $qb->andWhere('o.reference LIKE :reference')
                        ->setParameter('reference', '%' . $value . '%');
                    break;

                case 'customer_name':
                    $qb->andWhere('CONCAT(c.firstname, \' \', c.lastname) LIKE :customer_name')
                        ->setParameter('customer_name', '%' . $value . '%');
                    break;

                case 'phone':
                    $qb->andWhere('COALESCE(NULLIF(p.phone, \'\'), NULLIF(a.phone, \'\'), a.phone_mobile) LIKE :phone')
                        ->setParameter('phone', '%' . $value . '%');
                    break;

                case 'tracking':
                    $qb->andWhere('p.tracking LIKE :tracking')
                        ->setParameter('tracking', '%' . $value . '%');
                    break;

                case 'parcel_status':
                    $this->applyStatusFilter($qb, $value);
                    break;
            }
        }

        return $qb;
    }

    /**
     * Apply status filter — handles both module statuses and carrier-phase grouping.
     */
    private function applyStatusFilter(QueryBuilder $qb, string $value): void
    {
        switch ($value) {
            case 'not_confirmed':
                // Orders with no parcel row or with status not_confirmed
                $qb->andWhere('(p.id IS NULL OR p.status = :not_confirmed)')
                    ->setParameter('not_confirmed', 'not_confirmed');
                break;

            case 'confirmed':
                $qb->andWhere('p.status = :parcel_status')
                    ->setParameter('parcel_status', 'confirmed');
                break;

            case 'shipping':
                // Group all "in transit" carrier statuses
                $shippingStatuses = [
                    GuepexParcelStatus::RAMASSE,
                    GuepexParcelStatus::BLOQUE,
                    GuepexParcelStatus::DEBLOQUE,
                    GuepexParcelStatus::TRANSFERT,
                    GuepexParcelStatus::EXPEDIE,
                    GuepexParcelStatus::CENTRE,
                    GuepexParcelStatus::EN_LOCALISATION,
                    GuepexParcelStatus::VERS_WILAYA,
                    GuepexParcelStatus::EN_TRANSIT,
                    GuepexParcelStatus::RECU_A_WILAYA,
                    GuepexParcelStatus::EN_ATTENTE_CLIENT,
                    GuepexParcelStatus::PRET_POUR_LIVREUR,
                    GuepexParcelStatus::SORTI_EN_LIVRAISON,
                    GuepexParcelStatus::EN_ATTENTE,
                    // Also include pre-shipping statuses from the carrier
                    GuepexParcelStatus::PAS_ENCORE_EXPEDIE,
                    GuepexParcelStatus::A_VERIFIER,
                    GuepexParcelStatus::EN_PREPARATION,
                    GuepexParcelStatus::PAS_ENCORE_RAMASSE,
                    GuepexParcelStatus::PRET_A_EXPEDIER,
                    GuepexParcelStatus::EN_PASSATION,
                ];
                $qb->andWhere($qb->expr()->in('p.status', ':shipping_statuses'))
                    ->setParameter('shipping_statuses', $shippingStatuses, Connection::PARAM_STR_ARRAY);
                break;

            case 'delivered':
                $qb->andWhere('p.status = :parcel_status')
                    ->setParameter('parcel_status', GuepexParcelStatus::LIVRE);
                break;

            case 'failed':
                $failedStatuses = [
                    GuepexParcelStatus::ANNULE,
                    GuepexParcelStatus::EN_ALERTE,
                    GuepexParcelStatus::TENTATIVE_ECHOUEE,
                    GuepexParcelStatus::ECHEC_LIVRAISON,
                ];
                $qb->andWhere($qb->expr()->in('p.status', ':failed_statuses'))
                    ->setParameter('failed_statuses', $failedStatuses, Connection::PARAM_STR_ARRAY);
                break;

            case 'returned':
                $returnedStatuses = [
                    GuepexParcelStatus::RETOUR_VERS_CENTRE,
                    GuepexParcelStatus::RETOURNE_AU_CENTRE,
                    GuepexParcelStatus::RETOUR_TRANSFERT,
                    GuepexParcelStatus::RETOUR_GROUPE,
                    GuepexParcelStatus::RETOUR_A_RETIRER,
                    GuepexParcelStatus::RETOUR_NON_RETIRE,
                    GuepexParcelStatus::COLIS_ABANDONNE,
                    GuepexParcelStatus::RETOUR_VERS_VENDEUR,
                    GuepexParcelStatus::RETOURNE_AU_VENDEUR,
                    GuepexParcelStatus::ECHANGE_ECHOUE,
                ];
                $qb->andWhere($qb->expr()->in('p.status', ':returned_statuses'))
                    ->setParameter('returned_statuses', $returnedStatuses, Connection::PARAM_STR_ARRAY);
                break;
        }
    }
}
