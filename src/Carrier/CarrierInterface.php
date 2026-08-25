<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Carrier;

/**
 * Contract that every carrier driver must implement.
 *
 * Each Algerian carrier (Guepex, Yalidine, ZR Express, etc.) provides
 * a concrete implementation of this interface. The CarrierRegistry
 * holds all registered drivers and resolves them by carrier code.
 */
interface CarrierInterface
{
    /**
     * Unique carrier identifier (e.g., 'guepex', 'yalidine').
     */
    public function getCode(): string;

    /**
     * Human-readable carrier name (e.g., 'Guepex').
     */
    public function getName(): string;

    /**
     * Fields required to authenticate with this carrier's API.
     *
     * Returned as an associative array of field_key => label.
     * Used to dynamically render the credentials form in "My Carriers".
     *
     * Example for Guepex: ['api_id' => 'API ID', 'api_token' => 'API Token']
     * Another carrier might need: ['username' => 'Username', 'password' => 'Password', 'account_id' => 'Account ID']
     *
     * @return array<string, string>
     */
    public function getAuthFields(): array;

    /**
     * Create parcels on the carrier platform for the given orders.
     *
     * @param  array  $orders       Array of order data arrays, each containing:
     *                              - order_id (int)
     *                              - firstname, lastname, phone, address (string)
     *                              - wilaya_name, commune_name (string)
     *                              - price (int), weight (float)
     *                              - product_list (string)
     *                              - delivery_type (string)
     *                              - freeshipping (bool)
     * @param  array  $credentials  API credentials from cm_carrier_accounts
     *                              (api_id, api_token, extra_config)
     *
     * @return array<string, array{
     *     success: bool,
     *     tracking: string|null,
     *     import_id: int|null,
     *     label_url: string|null,
     *     initial_status: string,
     *     message: string
     * }>  Keyed by order_id
     */
    public function createParcels(array $orders, array $credentials): array;

    /**
     * Query the carrier API for the current status of a parcel.
     *
     * @param  string $tracking     The carrier tracking number
     * @param  array  $credentials  API credentials
     * @return array{status: string, raw: array}
     */
    public function getParcelStatus(string $tracking, array $credentials): array;

    /**
     * All possible parcel statuses this carrier returns.
     *
     * @return array<string, string>  status_value => human-readable label
     */
    public function getStatuses(): array;

    /**
     * Map a carrier-specific status to a lifecycle phase.
     *
     * @return string  One of: processing, shipping, delivered, failed, returned
     */
    public function mapStatusToPhase(string $status): string;

    /**
     * Map a carrier status to a PrestaShop order state ID.
     *
     * Returns null when the PS order state should NOT be updated.
     */
    public function mapStatusToPsOrderState(string $status): ?int;
}
