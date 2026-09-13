<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Carrier\Guepex;

/**
 * Guepex parcel status values.
 *
 * These are the exact French strings returned by the Guepex API.
 * Ported from the Laravel GuepexParcelStatus enum.
 *
 * @see https://api.guepex.app/v1/parcels (last_status field)
 */
final class GuepexParcelStatus
{
    // ── Module-internal statuses (not from Guepex) ───────────────
    public const NOT_CONFIRMED = 'not_confirmed';
    public const CONFIRMED     = 'confirmed';

    // ── Pre-Shipping ─────────────────────────────────────────────
    public const PAS_ENCORE_EXPEDIE = 'Pas encore expédié';
    public const A_VERIFIER         = 'A vérifier';
    public const EN_PREPARATION     = 'En préparation';
    public const PAS_ENCORE_RAMASSE = 'Pas encore ramassé';
    public const PRET_A_EXPEDIER    = 'Prêt à expédier';
    public const EN_PASSATION       = 'En passation';

    // ── In Transit ───────────────────────────────────────────────
    public const RAMASSE             = 'Ramassé';
    public const BLOQUE              = 'Bloqué';
    public const DEBLOQUE            = 'Débloqué';
    public const TRANSFERT           = 'Transfert';
    public const EXPEDIE             = 'Expédié';
    public const CENTRE              = 'Centre';
    public const EN_LOCALISATION     = 'En localisation';
    public const VERS_WILAYA         = 'Vers Wilaya';
    public const EN_TRANSIT          = 'En transit';
    public const RECU_A_WILAYA       = 'Reçu à Wilaya';
    public const EN_ATTENTE_CLIENT   = 'En attente du client';
    public const PRET_POUR_LIVREUR   = 'Prêt pour livreur';
    public const SORTI_EN_LIVRAISON  = 'Sorti en livraison';
    public const EN_ATTENTE          = 'En attente';

    // ── Terminal: Success ────────────────────────────────────────
    public const LIVRE = 'Livré';

    // ── Terminal: Failure / Cancelled ────────────────────────────
    public const ANNULE              = 'Annulé';
    public const EN_ALERTE           = 'En alerte';
    public const TENTATIVE_ECHOUEE   = 'Tentative échouée';
    public const ECHEC_LIVRAISON     = 'Echèc livraison';

    // ── Return Flow ─────────────────────────────────────────────
    public const RETOUR_VERS_CENTRE  = 'Retour vers centre';
    public const RETOURNE_AU_CENTRE  = 'Retourné au centre';
    public const RETOUR_TRANSFERT    = 'Retour transfert';
    public const RETOUR_GROUPE       = 'Retour groupé';
    public const RETOUR_A_RETIRER    = 'Retour à retirer';
    public const RETOUR_NON_RETIRE   = 'Retour non retiré';
    public const COLIS_ABANDONNE     = 'Colis abandonné';
    public const RETOUR_VERS_VENDEUR = 'Retour vers vendeur';
    public const RETOURNE_AU_VENDEUR = 'Retourné au vendeur';
    public const ECHANGE_ECHOUE      = 'Echange échoué';

    /**
     * Map status → lifecycle phase.
     */
    public static function phase(string $status): string
    {
        return match ($status) {
            self::NOT_CONFIRMED,
            self::CONFIRMED => 'pending',

            self::PAS_ENCORE_EXPEDIE,
            self::A_VERIFIER,
            self::EN_PREPARATION,
            self::PAS_ENCORE_RAMASSE,
            self::PRET_A_EXPEDIER,
            self::EN_PASSATION => 'processing',

            self::RAMASSE,
            self::BLOQUE,
            self::DEBLOQUE,
            self::TRANSFERT,
            self::EXPEDIE,
            self::CENTRE,
            self::EN_LOCALISATION,
            self::VERS_WILAYA,
            self::EN_TRANSIT,
            self::RECU_A_WILAYA,
            self::EN_ATTENTE_CLIENT,
            self::PRET_POUR_LIVREUR,
            self::SORTI_EN_LIVRAISON,
            self::EN_ATTENTE => 'shipping',

            self::LIVRE => 'delivered',

            self::ANNULE,
            self::EN_ALERTE,
            self::TENTATIVE_ECHOUEE,
            self::ECHEC_LIVRAISON => 'failed',

            self::RETOUR_VERS_CENTRE,
            self::RETOURNE_AU_CENTRE,
            self::RETOUR_TRANSFERT,
            self::RETOUR_GROUPE,
            self::RETOUR_A_RETIRER,
            self::RETOUR_NON_RETIRE,
            self::COLIS_ABANDONNE,
            self::RETOUR_VERS_VENDEUR,
            self::RETOURNE_AU_VENDEUR,
            self::ECHANGE_ECHOUE => 'returned',

            default => 'processing',
        };
    }

    /**
     * English label for the tracking UI.
     */
    public static function label(string $status): string
    {
        return match ($status) {
            self::NOT_CONFIRMED       => 'Not Confirmed',
            self::CONFIRMED           => 'Confirmed',
            self::PAS_ENCORE_EXPEDIE  => 'Not yet shipped',
            self::A_VERIFIER          => 'Being verified',
            self::EN_PREPARATION      => 'Being prepared',
            self::PAS_ENCORE_RAMASSE  => 'Not yet picked up',
            self::PRET_A_EXPEDIER     => 'Ready to ship',
            self::EN_PASSATION        => 'Handover pending',
            self::RAMASSE             => 'Picked up',
            self::BLOQUE              => 'Blocked',
            self::DEBLOQUE            => 'Unblocked',
            self::TRANSFERT           => 'Transfer',
            self::EXPEDIE             => 'Shipped',
            self::CENTRE              => 'At hub',
            self::EN_LOCALISATION     => 'Locating',
            self::VERS_WILAYA         => 'Heading to wilaya',
            self::EN_TRANSIT          => 'In transit',
            self::RECU_A_WILAYA       => 'Arrived at wilaya',
            self::EN_ATTENTE_CLIENT   => 'Awaiting customer',
            self::PRET_POUR_LIVREUR   => 'Ready for courier',
            self::SORTI_EN_LIVRAISON  => 'Out for delivery',
            self::EN_ATTENTE          => 'On hold',
            self::LIVRE               => 'Delivered',
            self::ANNULE              => 'Cancelled',
            self::EN_ALERTE           => 'Alert',
            self::TENTATIVE_ECHOUEE   => 'Delivery attempt failed',
            self::ECHEC_LIVRAISON     => 'Delivery failed',
            self::RETOUR_VERS_CENTRE  => 'Returning to hub',
            self::RETOURNE_AU_CENTRE  => 'Returned to hub',
            self::RETOUR_TRANSFERT    => 'Return in transfer',
            self::RETOUR_GROUPE       => 'Grouped return',
            self::RETOUR_A_RETIRER    => 'Return ready for pickup',
            self::RETOUR_NON_RETIRE   => 'Return not picked up',
            self::COLIS_ABANDONNE     => 'Parcel abandoned',
            self::RETOUR_VERS_VENDEUR => 'Returning to seller',
            self::RETOURNE_AU_VENDEUR => 'Returned to seller',
            self::ECHANGE_ECHOUE      => 'Exchange failed',
            default                   => $status,
        };
    }

    /**
     * Map Guepex status to PrestaShop order state ID.
     * Returns null when PS state should NOT be updated.
     *
     * Uses custom DZ order states from ps_configuration (installed by the module).
     * Falls back to PS built-in states if custom states aren't available.
     */
    public static function toPrestashopState(string $status): ?int
    {
        $phase = self::phase($status);

        return match ($phase) {
            'pending'    => self::resolveState('DZ_CM_STATE_NOT_CONFIRMED', null),
            'processing' => self::resolveState('DZ_CM_STATE_BEING_PREPARED', 3),
            'shipping'   => self::resolveState('DZ_CM_STATE_SHIPPED', 4),
            'delivered'  => self::resolveState('DZ_CM_STATE_DELIVERED', 5),
            'failed'     => self::resolveState('DZ_CM_STATE_FAILED_DELIVERY', 6),
            'returned'   => self::resolveState('DZ_CM_STATE_RETURNED', 6),
            default      => null,
        };
    }

    /**
     * Resolve a custom DZ state ID, falling back to a PS built-in state.
     */
    private static function resolveState(string $configKey, ?int $fallback): ?int
    {
        $stateId = \Module\DzCarrierManager\Database\OrderStateInstaller::getStateId($configKey);

        return $stateId ?? $fallback;
    }

    /**
     * All carrier statuses (excluding module-internal ones) with labels.
     *
     * @return array<string, string>
     */
    public static function allCarrierStatuses(): array
    {
        return [
            self::PAS_ENCORE_EXPEDIE  => self::label(self::PAS_ENCORE_EXPEDIE),
            self::A_VERIFIER          => self::label(self::A_VERIFIER),
            self::EN_PREPARATION      => self::label(self::EN_PREPARATION),
            self::PAS_ENCORE_RAMASSE  => self::label(self::PAS_ENCORE_RAMASSE),
            self::PRET_A_EXPEDIER     => self::label(self::PRET_A_EXPEDIER),
            self::EN_PASSATION        => self::label(self::EN_PASSATION),
            self::RAMASSE             => self::label(self::RAMASSE),
            self::BLOQUE              => self::label(self::BLOQUE),
            self::DEBLOQUE            => self::label(self::DEBLOQUE),
            self::TRANSFERT           => self::label(self::TRANSFERT),
            self::EXPEDIE             => self::label(self::EXPEDIE),
            self::CENTRE              => self::label(self::CENTRE),
            self::EN_LOCALISATION     => self::label(self::EN_LOCALISATION),
            self::VERS_WILAYA         => self::label(self::VERS_WILAYA),
            self::EN_TRANSIT          => self::label(self::EN_TRANSIT),
            self::RECU_A_WILAYA       => self::label(self::RECU_A_WILAYA),
            self::EN_ATTENTE_CLIENT   => self::label(self::EN_ATTENTE_CLIENT),
            self::PRET_POUR_LIVREUR   => self::label(self::PRET_POUR_LIVREUR),
            self::SORTI_EN_LIVRAISON  => self::label(self::SORTI_EN_LIVRAISON),
            self::EN_ATTENTE          => self::label(self::EN_ATTENTE),
            self::LIVRE               => self::label(self::LIVRE),
            self::ANNULE              => self::label(self::ANNULE),
            self::EN_ALERTE           => self::label(self::EN_ALERTE),
            self::TENTATIVE_ECHOUEE   => self::label(self::TENTATIVE_ECHOUEE),
            self::ECHEC_LIVRAISON     => self::label(self::ECHEC_LIVRAISON),
            self::RETOUR_VERS_CENTRE  => self::label(self::RETOUR_VERS_CENTRE),
            self::RETOURNE_AU_CENTRE  => self::label(self::RETOURNE_AU_CENTRE),
            self::RETOUR_TRANSFERT    => self::label(self::RETOUR_TRANSFERT),
            self::RETOUR_GROUPE       => self::label(self::RETOUR_GROUPE),
            self::RETOUR_A_RETIRER    => self::label(self::RETOUR_A_RETIRER),
            self::RETOUR_NON_RETIRE   => self::label(self::RETOUR_NON_RETIRE),
            self::COLIS_ABANDONNE     => self::label(self::COLIS_ABANDONNE),
            self::RETOUR_VERS_VENDEUR => self::label(self::RETOUR_VERS_VENDEUR),
            self::RETOURNE_AU_VENDEUR => self::label(self::RETOURNE_AU_VENDEUR),
            self::ECHANGE_ECHOUE      => self::label(self::ECHANGE_ECHOUE),
        ];
    }
}
