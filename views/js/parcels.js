/**
 * DZ Carrier Manager — Parcels grid initialization & enhancements.
 *
 * Handles:
 * - PS9 Grid component initialization (all extensions)
 * - Status badge rendering (color-coded by lifecycle phase)
 * - Delivery type badge rendering
 * - Duplicate tab bar removal
 */
document.addEventListener('DOMContentLoaded', function () {
    // ── 1. Initialize PS9 Grid Component ────────────────────────
    // PS9's Grid component auto-discovers and initializes extensions:
    // SubmitRowActionExtension, LinkRowActionExtension, BulkActionCheckboxExtension,
    // SubmitBulkActionExtension, SortingExtension, etc.
    try {
        if (window.prestashop && window.prestashop.component) {
            window.prestashop.component.initComponents(['Grid']);
        }
    } catch (e) {
        console.warn('[DzCarrierManager] Grid component init failed:', e);
    }

    // ── 2. Fallback: Ensure SubmitRowAction forms work ──────────
    // PS9's SubmitRowAction renders <form> elements with a <button type="submit">.
    // If the Grid extensions didn't bind properly, we manually ensure the
    // confirmation dialogs and form submissions work.
    initSubmitRowActions();
    initDropdownToggle();

    // ── 3. Apply custom badge styles ────────────────────────────
    applyStatusBadges();
    applyDeliveryTypeBadges();

    // ── 4. Re-apply after grid refreshes ────────────────────────
    var gridPanel = document.querySelector('.js-grid-table');
    if (!gridPanel) {
        gridPanel = document.querySelector('.grid-panel');
    }
    if (gridPanel) {
        var observer = new MutationObserver(function () {
            applyStatusBadges();
            applyDeliveryTypeBadges();
        });
        observer.observe(gridPanel, { childList: true, subtree: true });
    }

    // ── 5. Retry badge application (grid may render after DOM ready) ──
    setTimeout(function () {
        applyStatusBadges();
        applyDeliveryTypeBadges();
    }, 300);

    setTimeout(function () {
        applyStatusBadges();
        applyDeliveryTypeBadges();
    }, 1000);

    // ── 6. Remove duplicate tab bar ─────────────────────────────
    removeDuplicateTabBar();
});

/**
 * Ensure SubmitRowAction forms work even if PS9 Grid extensions fail.
 * Each row action renders as: <form method="POST" action="..."><button type="submit">...</button></form>
 * PS9 adds a confirmation dialog via JS. If that JS doesn't load, we add our own.
 */
function initSubmitRowActions() {
    var forms = document.querySelectorAll('.js-grid-table form.grid-action-submit-btn, .js-grid-table form[method="POST"]');
    forms.forEach(function (form) {
        var button = form.querySelector('button[type="submit"]');
        if (!button) return;

        // Check if PS9 already bound an event (look for data attribute)
        if (button.dataset.dzcmBound) return;
        button.dataset.dzcmBound = 'true';

        button.addEventListener('click', function (e) {
            var confirmMsg = button.getAttribute('data-confirm-message')
                || form.getAttribute('data-confirm-message')
                || '';

            if (confirmMsg) {
                // PS9 may have already shown a dialog — check if we need to intervene
                // Only show our dialog if PS9's extension didn't fire
                if (!e.defaultPrevented) {
                    e.preventDefault();
                    if (confirm(confirmMsg)) {
                        form.submit();
                    }
                }
            }
            // If no confirm message, let the form submit naturally
        });
    });

    // Also handle SubmitRowAction buttons that PS9 renders inside dropdown menus
    var dropdownForms = document.querySelectorAll('.dropdown-menu form');
    dropdownForms.forEach(function (form) {
        var button = form.querySelector('button[type="submit"]');
        if (!button || button.dataset.dzcmBound) return;
        button.dataset.dzcmBound = 'true';

        button.addEventListener('click', function (e) {
            var confirmMsg = button.getAttribute('data-confirm-message')
                || form.getAttribute('data-confirm-message')
                || '';

            if (confirmMsg && !e.defaultPrevented) {
                e.preventDefault();
                if (confirm(confirmMsg)) {
                    form.submit();
                }
            }
        });
    });
}

/**
 * Ensure the three-dot (⋮) dropdown toggles open on click.
 * PS9 uses Bootstrap 5 dropdowns, but if the Grid JS doesn't init,
 * the dropdown toggle might not work.
 */
function initDropdownToggle() {
    var toggles = document.querySelectorAll('.js-grid-table .dropdown-toggle');
    toggles.forEach(function (toggle) {
        if (toggle.dataset.dzcmBound) return;
        toggle.dataset.dzcmBound = 'true';

        // Only add manual toggle if Bootstrap's dropdown isn't already working
        toggle.addEventListener('click', function (e) {
            var menu = toggle.nextElementSibling;
            if (!menu || !menu.classList.contains('dropdown-menu')) {
                // Try finding within parent
                var parent = toggle.closest('.dropdown');
                if (parent) {
                    menu = parent.querySelector('.dropdown-menu');
                }
            }
            if (menu) {
                // Check if Bootstrap already handled it
                if (!menu.classList.contains('show')) {
                    // Close all other open dropdowns first
                    document.querySelectorAll('.dropdown-menu.show').forEach(function (m) {
                        m.classList.remove('show');
                    });
                    menu.classList.toggle('show');
                    e.stopPropagation();
                }
            }
        });
    });

    // Close dropdowns when clicking elsewhere
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function (m) {
                m.classList.remove('show');
            });
        }
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
        if (!raw || cell.querySelector('.dzcm-delivery-badge')) {
            return;
        }

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
        if (!rawStatus || cell.querySelector('.dzcm-status-badge')) {
            return; // Already badged or empty
        }

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
 * PS9 renders two tab levels for parent + child tabs:
 *   Row 1: Page-level tabs (text only, from parent tab hierarchy)
 *   Row 2: Same tabs with icons (from child tab definitions)
 * We keep Row 1 and hide Row 2.
 */
function removeDuplicateTabBar() {
    // PS9 renders the page tabs as <ul class="nav nav-tabs"> inside the content header.
    // The duplicate appears as a second <ul class="nav nav-tabs"> lower on the page.
    var tabBars = document.querySelectorAll('ul.nav.nav-tabs');

    if (tabBars.length > 1) {
        // Keep the first one (main navigation), hide the second (duplicate)
        for (var i = 1; i < tabBars.length; i++) {
            // Only hide if it contains links to the same destinations
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
