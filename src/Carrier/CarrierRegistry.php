<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Carrier;

/**
 * Registry of all available carrier drivers.
 *
 * Controllers and services ask the registry for the right driver
 * by carrier_code (e.g., 'guepex'). New carriers are registered
 * as Symfony services tagged with 'dzcarriermanager.carrier'.
 */
class CarrierRegistry
{
    /** @var array<string, CarrierInterface> */
    private array $carriers = [];

    /**
     * Register a carrier driver.
     */
    public function register(CarrierInterface $carrier): void
    {
        $this->carriers[$carrier->getCode()] = $carrier;
    }

    /**
     * Get a carrier driver by its code.
     *
     * @throws \InvalidArgumentException If the carrier code is unknown
     */
    public function get(string $code): CarrierInterface
    {
        if (!isset($this->carriers[$code])) {
            throw new \InvalidArgumentException(
                sprintf('Unknown carrier code "%s". Available: %s', $code, implode(', ', array_keys($this->carriers)))
            );
        }

        return $this->carriers[$code];
    }

    /**
     * Get all registered carrier drivers.
     *
     * @return array<string, CarrierInterface>
     */
    public function all(): array
    {
        return $this->carriers;
    }

    /**
     * Check if a carrier code is registered.
     */
    public function has(string $code): bool
    {
        return isset($this->carriers[$code]);
    }

    /**
     * Get all carrier codes.
     *
     * @return string[]
     */
    public function codes(): array
    {
        return array_keys($this->carriers);
    }
}
