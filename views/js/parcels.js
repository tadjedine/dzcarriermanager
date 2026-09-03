/**
 * DZ Carrier Manager — Parcels grid initialization & enhancements.
 *
 * Handles:
 * - PS9 Grid component initialization (all extensions)
 * - Fallback for SubmitRowAction (which PS9 renders as <a href="#" class="js-submit-row-action">)
 * - Status badge rendering (color-coded by lifecycle phase)
 * - Delivery type badge rendering
 * - Duplicate tab bar removal
 */
document.addEventListener('DOMContentLoaded', function () {
    // ── 1. Initialize PS9 Grid Component ────────────────────────
    var gridInitialized = false;
    try {
        if (window.prestashop && window.prestashop.component) {
            window.prestashop.component.initComponents(['Grid']);
            gridInitialized = true;
        }
    } catch (e) {
        console.warn('[DzCarrierManager] Grid component init failed:', e);
    }

    // ── 2. Fallback: Bind SubmitRowAction anchors ───────────────
    // PS9 renders SubmitRowAction as:
    //   <a class="js-submit-row-action" href="#"
    //      data-url="/path/to/action" data-method="POST"
    //      data-confirm-message="Are you sure?">
    //
    // The Grid JS extension normally handles click → confirm → dynamic form submit.
    // If the Grid extension didn't bind, we need to handle this ourselves.
    setTimeout(function () {
        initSubmitRowActionFallback();
    }, 200);

    // ── 3. Apply custom badge styles ────────────────────────────
    applyStatusBadges();
    applyDeliveryTypeBadges();

    // ── 4. Re-apply after grid refreshes (sorting, filtering, pagination) ──
    var gridPanel = document.querySelector('.js-grid-table') || document.querySelector('.grid-panel');
    if (gridPanel) {
        var observer = new MutationObserver(function () {
            applyStatusBadges();
            applyDeliveryTypeBadges();
            // Re-bind fallback after grid DOM changes
            setTimeout(function () { initSubmitRowActionFallback(); }, 100);
        });
        observer.observe(gridPanel, { childList: true, subtree: true });
    }

    // ── 5. Retry badge application (grid may render after DOMContentLoaded) ──
    setTimeout(function () {
        applyStatusBadges();
        applyDeliveryTypeBadges();
    }, 500);

    setTimeout(function () {
        applyStatusBadges();
        applyDeliveryTypeBadges();
    }, 1500);

    // ── 6. Remove duplicate tab bar ─────────────────────────────
    removeDuplicateTabBar();
});

/**
 * Fallback handler for PS9's SubmitRowAction anchors.
 *
 * PS9's submit.html.twig renders each SubmitRowAction as:
 *   <a class="js-submit-row-action" href="#"
 *      data-url="{route_url}" data-method="POST"
 *      data-confirm-message="{message}">
 *
 * The Grid JS extension (SubmitRowActionExtension) normally intercepts
 * clicks, shows a confirm dialog, creates a hidden form, and submits it.
 * If that extension fails to load, we replicate the behavior here.
 */
function initSubmitRowActionFallback() {
    var actions = document.querySelectorAll('a.js-submit-row-action');

    actions.forEach(function (link) {
        // Skip if already bound (by us or by PS9's extension)
        if (link.dataset.dzcmFallbackBound) return;
        link.dataset.dzcmFallbackBound = 'true';

        link.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var url = link.getAttribute('data-url');
            var method = (link.getAttribute('data-method') || 'POST').toUpperCase();
            var confirmMsg = link.getAttribute('data-confirm-message') || '';

            if (!url) return;

            // Show confirmation dialog if message is set
            if (confirmMsg && !window.confirm(confirmMsg)) {
                return;
            }

            // Create and submit a dynamic form (same as PS9's SubmitRowActionExtension)
            var form = document.createElement('form');
            form.method = method;
            form.action = url;
            form.style.display = 'none';

            // PS9 requires CSRF token — try to find one on the page
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                var csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfMeta.getAttribute('content');
                form.appendChild(csrfInput);
            }

            document.body.appendChild(form);
            form.submit();
        });
    });
}

/**
 * Find all cells in the "delivery_type" column and render
 * a small icon + label badge for Home / Stop Desk.
 */
function applyDeliveryTypeBadges() {
    var cells = document.querySelectorAll('td.column-delivery_type');

    cells.forEach(function (cell) {
        var raw = cell.textContent.trim().toLowerCase();
        if (!raw || cell.querySelector('.dzcm-delivery-badge')) return;

        var isStopDesk = raw === 'stop_desk' || raw === 'stop desk' || raw === 'stopdesk';
        var label = isStopDesk ? '📦 Stop Desk' : '🏠 Home';
        var cls = isStopDesk ? 'dzcm-delivery-stopdesk' : 'dzcm-delivery-home';

        cell.textContent = '';
        var badge = document.createElement('span');
        badge.className = 'dzcm-delivery-badge ' + cls;
        badge.textContent = label;
        cell.appendChild(badge);
    });
}

/**
 * Find all cells in the "parcel_status" column and wrap the text
 * in a styled badge based on the lifecycle phase.
 */
function applyStatusBadges() {
    var statusCells = document.querySelectorAll('td.column-parcel_status');

    statusCells.forEach(function (cell) {
        var rawStatus = cell.textContent.trim();
        if (!rawStatus || cell.querySelector('.dzcm-status-badge')) return;

        var phase = getPhaseForStatus(rawStatus);
        var label = getLabelForStatus(rawStatus);

        cell.textContent = '';
        var badge = document.createElement('span');
        badge.className = 'dzcm-status-badge dzcm-phase-' + phase;
        badge.textContent = label;
        cell.appendChild(badge);
    });
}

/**
 * Map a raw status string to a lifecycle phase.
 */
function getPhaseForStatus(status) {
    var s = status.toLowerCase();

    if (s === 'not_confirmed') return 'pending';
    if (s === 'confirmed') return 'pending';

    // Delivered
    if (s === 'livré') return 'delivered';

    // Failed
    var failed = ['annulé', 'en alerte', 'tentative échouée', 'echèc livraison'];
    if (failed.indexOf(s) !== -1) return 'failed';

    // Returned
    if (s.indexOf('retour') !== -1 || s.indexOf('retourné') !== -1 || s === 'colis abandonné' || s === 'echange échoué') {
        return 'returned';
    }

    // Processing (pre-shipping)
    var processing = ['pas encore expédié', 'a vérifier', 'en préparation',
                       'pas encore ramassé', 'prêt à expédier', 'en passation'];
    if (processing.indexOf(s) !== -1) return 'processing';

    // Default: shipping
    return 'shipping';
}

/**
 * Get a human-readable label for a status.
 */
function getLabelForStatus(status) {
    var labels = {
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

/**
 * Remove the duplicate tab bar that PS9 renders.
 */
function removeDuplicateTabBar() {
    var tabBars = document.querySelectorAll('ul.nav.nav-tabs');

    if (tabBars.length > 1) {
        for (var i = 1; i < tabBars.length; i++) {
            var links = tabBars[i].querySelectorAll('a[href]');
            var isDuplicate = false;
            links.forEach(function (link) {
                var href = link.getAttribute('href') || '';
                if (href.indexOf('carrier') !== -1 || href.indexOf('parcel') !== -1 || href.indexOf('dz-carrier-manager') !== -1) {
                    isDuplicate = true;
                }
            });
            if (isDuplicate) {
                tabBars[i].style.display = 'none';
            }
        }
    }
}
