/**
 * DZ Carrier Manager — Parcels grid enhancements.
 *
 * Handles:
 * - Applying status badge styles after grid renders
 * - Grid interactions (sorting, pagination are handled by PS core)
 */
document.addEventListener('DOMContentLoaded', function () {
    applyStatusBadges();

    // Re-apply after grid refreshes (PS grid uses pagination.js)
    const gridPanel = document.querySelector('.grid-panel');
    if (gridPanel) {
        const observer = new MutationObserver(function () {
            applyStatusBadges();
        });
        observer.observe(gridPanel, { childList: true, subtree: true });
    }
});

/**
 * Find all cells in the "parcel_status" column and wrap the text
 * in a styled badge based on the lifecycle phase.
 */
function applyStatusBadges() {
    const statusCells = document.querySelectorAll('td.column-parcel_status');

    statusCells.forEach(function (cell) {
        const rawStatus = cell.textContent.trim();
        if (!rawStatus || cell.querySelector('.dzcm-status-badge')) {
            return; // Already badged or empty
        }

        const phase = getPhaseForStatus(rawStatus);
        const label = getLabelForStatus(rawStatus);

        cell.textContent = '';
        const badge = document.createElement('span');
        badge.className = 'dzcm-status-badge dzcm-phase-' + phase;
        badge.textContent = label;
        cell.appendChild(badge);
    });
}

/**
 * Map a raw status string to a lifecycle phase.
 */
function getPhaseForStatus(status) {
    const s = status.toLowerCase();

    if (s === 'not_confirmed') return 'pending';
    if (s === 'confirmed') return 'pending';

    // Delivered
    if (s === 'livré') return 'delivered';

    // Failed
    const failed = ['annulé', 'en alerte', 'tentative échouée', 'echèc livraison'];
    if (failed.includes(s)) return 'failed';

    // Returned
    if (s.includes('retour') || s.includes('retourné') || s === 'colis abandonné' || s === 'echange échoué') {
        return 'returned';
    }

    // Processing (pre-shipping)
    const processing = ['pas encore expédié', 'a vérifier', 'en préparation',
                         'pas encore ramassé', 'prêt à expédier', 'en passation'];
    if (processing.includes(s)) return 'processing';

    // Default: shipping
    return 'shipping';
}

/**
 * Get a human-readable label for a status.
 */
function getLabelForStatus(status) {
    const labels = {
        'not_confirmed': 'Not Confirmed',
        'confirmed': 'Confirmed',
        'Pas encore expédié': 'Not yet shipped',
        'A vérifier': 'Being verified',
        'En préparation': 'Being prepared',
        'Pas encore ramassé': 'Not yet picked up',
        'Prêt à expédier': 'Ready to ship',
        'En passation': 'Handover pending',
        'Ramassé': 'Picked up',
        'Bloqué': 'Blocked',
        'Débloqué': 'Unblocked',
        'Transfert': 'Transfer',
        'Expédié': 'Shipped',
        'Centre': 'At hub',
        'En localisation': 'Locating',
        'Vers Wilaya': 'Heading to wilaya',
        'En transit': 'In transit',
        'Reçu à Wilaya': 'Arrived at wilaya',
        'En attente du client': 'Awaiting customer',
        'Prêt pour livreur': 'Ready for courier',
        'Sorti en livraison': 'Out for delivery',
        'En attente': 'On hold',
        'Livré': 'Delivered',
        'Annulé': 'Cancelled',
        'En alerte': 'Alert',
        'Tentative échouée': 'Attempt failed',
        'Echèc livraison': 'Delivery failed',
        'Retour vers centre': 'Returning to hub',
        'Retourné au centre': 'Returned to hub',
        'Retour transfert': 'Return in transfer',
        'Retour groupé': 'Grouped return',
        'Retour à retirer': 'Return ready',
        'Retour non retiré': 'Return not picked up',
        'Colis abandonné': 'Parcel abandoned',
        'Retour vers vendeur': 'Returning to seller',
        'Retourné au vendeur': 'Returned to seller',
        'Echange échoué': 'Exchange failed',
    };

    return labels[status] || status;
}
