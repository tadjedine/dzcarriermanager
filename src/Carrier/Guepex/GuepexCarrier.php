<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Carrier\Guepex;

use Module\DzCarrierManager\Carrier\CarrierInterface;
use RuntimeException;

/**
 * Guepex carrier driver implementation.
 *
 * Handles parcel creation, status queries, and status mapping
 * for the Guepex (Yalidine family) delivery service.
 *
 * Ported from the Laravel GuepexService.
 */
class GuepexCarrier implements CarrierInterface
{
    /**
     * Algerian wilayas mapping (ID → name).
     * Used to resolve the sender's wilaya name from its ID.
     */
    private const WILAYAS = [
        1 => 'Adrar', 2 => 'Chlef', 3 => 'Laghouat', 4 => 'Oum El Bouaghi',
        5 => 'Batna', 6 => 'Béjaïa', 7 => 'Biskra', 8 => 'Béchar',
        9 => 'Blida', 10 => 'Bouira', 11 => 'Tamanrasset', 12 => 'Tébessa',
        13 => 'Tlemcen', 14 => 'Tiaret', 15 => 'Tizi Ouzou', 16 => 'Alger',
        17 => 'Djelfa', 18 => 'Jijel', 19 => 'Sétif', 20 => 'Saïda',
        21 => 'Skikda', 22 => 'Sidi Bel Abbès', 23 => 'Annaba', 24 => 'Guelma',
        25 => 'Constantine', 26 => 'Médéa', 27 => 'Mostaganem', 28 => "M'sila",
        29 => 'Mascara', 30 => 'Ouargla', 31 => 'Oran', 32 => 'El Bayadh',
        33 => 'Illizi', 34 => 'Bordj Bou Arréridj', 35 => 'Boumerdès',
        36 => 'El Tarf', 37 => 'Tindouf', 38 => 'Tissemsilt', 39 => 'El Oued',
        40 => 'Khenchela', 41 => 'Souk Ahras', 42 => 'Tipaza', 43 => 'Mila',
        44 => 'Aïn Defla', 45 => 'Naâma', 46 => 'Aïn Témouchent',
        47 => 'Ghardaïa', 48 => 'Relizane',
        49 => "El M'Ghair", 50 => 'El Meniaa', 51 => 'Ouled Djellal',
        52 => 'Bordj Baji Mokhtar', 53 => 'Béni Abbès', 54 => 'Timimoun',
        55 => 'Touggourt', 56 => 'Djanet', 57 => 'In Salah', 58 => 'In Guezzam',
    ];

    /** Default sender wilaya ID — configurable via extra_config */
    private const DEFAULT_FROM_WILAYA_ID = 16; // Alger

    public function getCode(): string
    {
        return 'guepex';
    }

    public function getName(): string
    {
        return 'Guepex';
    }

    public function getAuthFields(): array
    {
        return [
            'api_id'    => 'API ID',
            'api_token' => 'API Token',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function createParcels(array $orders, array $credentials): array
    {
        $client = $this->buildClient($credentials);
        $fromWilayaId = $this->getFromWilayaId($credentials);
        $fromWilayaName = self::WILAYAS[$fromWilayaId]
            ?? throw new RuntimeException("Unknown from_wilaya_id: {$fromWilayaId}");

        // Build Guepex parcel payloads
        $parcels = [];
        foreach ($orders as $order) {
            $parcel = [
                'order_id'         => (string) $order['order_id'],
                'from_wilaya_name' => $fromWilayaName,
                'firstname'        => $order['firstname'],
                'familyname'       => $order['lastname'],
                'contact_phone'    => $order['phone'],
                'address'          => $order['address'],
                'to_commune_name'  => $order['commune_name'],
                'to_wilaya_name'   => $order['wilaya_name'],
                'product_list'     => $order['product_list'] ?? 'Commande #' . $order['order_id'],
                'price'            => (int) $order['price'],
                'do_insurance'     => false,
                'declared_value'   => (int) $order['price'],
                'length'           => (int) ($order['length'] ?? 0),
                'width'            => (int) ($order['width'] ?? 0),
                'height'           => (int) ($order['height'] ?? 0),
                'weight'           => (int) ceil((float) ($order['weight'] ?? 1)),
                'freeshipping'     => (bool) ($order['freeshipping'] ?? false),
                'is_stopdesk'      => ($order['delivery_type'] ?? '') === 'stop_desk',
                'has_exchange'     => false,
            ];

            // Stop desk requires a center ID
            if ($parcel['is_stopdesk'] && !empty($order['center_id'])) {
                $parcel['stopdesk_id'] = (int) $order['center_id'];
            }

            $parcels[] = $parcel;
        }

        // Call Guepex API
        $response = $client->createParcels($parcels);

        // Map results
        $results = [];
        foreach ($orders as $order) {
            $orderId = (string) $order['order_id'];
            $result  = $response[$orderId] ?? null;

            if (!$result || !($result['success'] ?? false)) {
                $results[$orderId] = [
                    'success'        => false,
                    'tracking'       => null,
                    'import_id'      => null,
                    'label_url'      => null,
                    'initial_status' => GuepexParcelStatus::EN_PREPARATION,
                    'message'        => $result['message'] ?? 'Unknown error from Guepex API.',
                ];
                continue;
            }

            $results[$orderId] = [
                'success'        => true,
                'tracking'       => $result['tracking'] ?? null,
                'import_id'      => $result['import_id'] ?? null,
                'label_url'      => $result['label'] ?? null,
                'initial_status' => GuepexParcelStatus::EN_PREPARATION,
                'message'        => $result['message'] ?? 'Parcel created successfully.',
            ];
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function getParcelStatus(string $tracking, array $credentials): array
    {
        $client = $this->buildClient($credentials);
        $data   = $client->getParcel($tracking);

        return [
            'status' => $data['last_status'] ?? 'unknown',
            'raw'    => $data,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getStatuses(): array
    {
        return array_merge(
            [
                GuepexParcelStatus::NOT_CONFIRMED => GuepexParcelStatus::label(GuepexParcelStatus::NOT_CONFIRMED),
                GuepexParcelStatus::CONFIRMED     => GuepexParcelStatus::label(GuepexParcelStatus::CONFIRMED),
            ],
            GuepexParcelStatus::allCarrierStatuses()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function mapStatusToPhase(string $status): string
    {
        return GuepexParcelStatus::phase($status);
    }

    /**
     * {@inheritdoc}
     */
    public function mapStatusToPsOrderState(string $status): ?int
    {
        return GuepexParcelStatus::toPrestashopState($status);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function buildClient(array $credentials): GuepexApiClient
    {
        $apiId    = $credentials['api_id'] ?? '';
        $apiToken = $credentials['api_token'] ?? '';
        $baseUrl  = null;

        // Allow base URL override from extra_config (useful for testing)
        if (!empty($credentials['extra_config'])) {
            $extra = is_string($credentials['extra_config'])
                ? json_decode($credentials['extra_config'], true)
                : $credentials['extra_config'];
            $baseUrl = $extra['base_url'] ?? null;
        }

        return new GuepexApiClient($apiId, $apiToken, $baseUrl);
    }

    private function getFromWilayaId(array $credentials): int
    {
        if (!empty($credentials['extra_config'])) {
            $extra = is_string($credentials['extra_config'])
                ? json_decode($credentials['extra_config'], true)
                : $credentials['extra_config'];

            if (!empty($extra['from_wilaya_id'])) {
                return (int) $extra['from_wilaya_id'];
            }
        }

        return self::DEFAULT_FROM_WILAYA_ID;
    }
}
