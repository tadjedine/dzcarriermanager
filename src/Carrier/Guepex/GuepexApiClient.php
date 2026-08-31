<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Carrier\Guepex;

use RuntimeException;

/**
 * Low-level HTTP client for the Guepex (Yalidine family) delivery API.
 *
 * Handles authentication, rate-limit awareness, and provides typed
 * methods for the Fees, Parcels, Wilayas, Communes, and Centers endpoints.
 *
 * Ported from the Laravel GuepexClient — uses native PHP curl instead
 * of Laravel's HTTP facade.
 *
 * @see https://api.guepex.app/v1/
 */
class GuepexApiClient
{
    private const DEFAULT_BASE_URL = 'https://api.guepex.app/v1';
    private const TIMEOUT = 30;

    private string $baseUrl;
    private string $apiId;
    private string $apiToken;

    public function __construct(string $apiId, string $apiToken, ?string $baseUrl = null)
    {
        $this->apiId    = $apiId;
        $this->apiToken = $apiToken;
        $this->baseUrl  = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');

        if (empty($this->apiId) || empty($this->apiToken)) {
            throw new RuntimeException('Guepex API credentials (API ID and API Token) are required.');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FEES
    // ═══════════════════════════════════════════════════════════════

    /**
     * Retrieve delivery fees between two wilayas.
     */
    public function getFees(int $fromWilayaId, int $toWilayaId): array
    {
        return $this->get('/fees/', [
            'from_wilaya_id' => $fromWilayaId,
            'to_wilaya_id'   => $toWilayaId,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PARCELS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create one or more parcels on Guepex.
     *
     * @param  array[] $parcels  Array of parcel data arrays
     * @return array Keyed by order_id
     */
    public function createParcels(array $parcels): array
    {
        return $this->post('/parcels/', $parcels);
    }

    /**
     * Retrieve a specific parcel by tracking number.
     */
    public function getParcel(string $tracking): array
    {
        return $this->get("/parcels/{$tracking}");
    }

    /**
     * Retrieve parcels with optional filters and pagination.
     */
    public function getParcels(array $filters = []): array
    {
        return $this->get('/parcels/', $filters);
    }

    // ═══════════════════════════════════════════════════════════════
    //  WILAYAS / COMMUNES / CENTERS
    // ═══════════════════════════════════════════════════════════════

    public function getWilayas(array $filters = []): array
    {
        return $this->get('/wilayas/', $filters);
    }

    public function getCommunes(array $filters = []): array
    {
        return $this->get('/communes/', $filters);
    }

    public function getCenters(array $filters = []): array
    {
        return $this->get('/centers/', $filters);
    }

    // ═══════════════════════════════════════════════════════════════
    //  HTTP TRANSPORT
    // ═══════════════════════════════════════════════════════════════

    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url);
    }

    private function post(string $path, array $data): array
    {
        $url = $this->baseUrl . $path;

        return $this->request('POST', $url, $data);
    }

    /**
     * Execute an HTTP request using native curl.
     *
     * @throws RuntimeException On network errors or non-2xx responses
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();

        $headers = [
            'X-API-ID: ' . $this->apiId,
            'X-API-TOKEN: ' . $this->apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);

        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException("Guepex API request failed: {$curlError}");
        }

        // Rate limited
        if ($httpCode === 429) {
            throw new RuntimeException('Guepex API rate limit exceeded. Please try again later.');
        }

        // Auth error
        if (in_array($httpCode, [401, 403], true)) {
            throw new RuntimeException('Guepex API authentication failed. Check your API credentials.');
        }

        // Other errors
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Guepex API error ({$httpCode}): {$responseBody}");
        }

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Guepex API returned invalid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
