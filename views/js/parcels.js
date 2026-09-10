/**
 * DZ Carrier Manager — Parcels grid initialization & enhancements.
 *
 * Handles:
 * - PS9 Grid component initialization (all extensions including bulk actions & filters)
 * - Status badge rendering (color-coded by lifecycle phase)
 * - Delivery type badge rendering
 * - Duplicate tab bar removal
 */
document.addEventListener('DOMContentLoaded', function () {
    // ── 1. Initialize PS9 Grid Component ────────────────────────
    initGrid();

    // ── 2. Apply custom badge styles ────────────────────────────
    applyStatusBadges();
    applyDeliveryTypeBadges();

    // ── 3. Re-apply after grid refreshes (sorting, filtering, pagination) ──
    var gridPanel = document.querySelector('.js-grid-table') || document.querySelector('.grid-panel');
    if (gridPanel) {
        var observer = new MutationObserver(function () {
            applyStatusBadges();
            applyDeliveryTypeBadges();
        });
        observer.observe(gridPanel, { childList: true, subtree: true });
    }

    // ── 4. Retry badge application (grid may render after DOMContentLoaded) ──
    setTimeout(function () {
        applyStatusBadges();
        applyDeliveryTypeBadges();
    }, 500);

    setTimeout(function () {
        applyStatusBadges();
        applyDeliveryTypeBadges();
    }, 1500);

    // ── 5. Remove duplicate tab bar ─────────────────────────────
    removeDuplicateTabBar();
});


/**
 * Initialize the PS9 Grid component with all required extensions.
 *
 * PS9 uses a Grid JS class that attaches extensions for bulk actions,
 * filters, sorting, etc. We need to instantiate it on our grid element.
 */
function initGrid() {
    // Attempt 1: Use the prestashop.component API
    try {
        if (window.prestashop && window.prestashop.component) {
            window.prestashop.component.initComponents(['Grid']);
        }
    } catch (e) {
        console.warn('[DzCarrierManager] prestashop.component.initComponents failed:', e);
    }

    // Attempt 2: Manually initialize the Grid on our specific grid div
    // This ensures all extensions (bulk actions, filters, etc.) are properly attached
    setTimeout(function () {
        initGridManual();
    }, 300);
}

/**
 * Manual Grid initialization fallback.
 * Finds the grid div and initializes it with the PS9 Grid constructor + extensions.
 */
function initGridManual() {
    var gridDiv = document.querySelector('#dzcarriermanager_parcel_grid_panel');

    // If we can't find it by ID, try the generic grid panel
    if (!gridDiv) {
        gridDiv = document.querySelector('.js-grid');
    }
    if (!gridDiv) {
        gridDiv = document.querySelector('[id$="_grid_panel"]');
    }
    if (!gridDiv) {
        return;
    }

    try {
        // PS9 exposes Grid class and extensions in the global scope
        // via the admin theme's JS bundles
        var Grid = window.Grid || (window.prestashop && window.prestashop.component && window.prestashop.component.Grid);

        if (typeof Grid === 'function') {
            var grid = new Grid(gridDiv.dataset.gridId || 'dzcarriermanager_parcel');

            // Attach all standard PS9 extensions
            if (window.SortingExtension) grid.addExtension(new window.SortingExtension());
            if (window.FiltersResetExtension) grid.addExtension(new window.FiltersResetExtension());
            if (window.ReloadListExtension) grid.addExtension(new window.ReloadListExtension());
            if (window.BulkActionCheckboxExtension) grid.addExtension(new window.BulkActionCheckboxExtension());
            if (window.SubmitBulkActionExtension) grid.addExtension(new window.SubmitBulkActionExtension());
            if (window.SubmitGridActionExtension) grid.addExtension(new window.SubmitGridActionExtension());
            if (window.SubmitRowActionExtension) grid.addExtension(new window.SubmitRowActionExtension());
            if (window.LinkRowActionExtension) grid.addExtension(new window.LinkRowActionExtension());
            if (window.ColumnTogglingExtension) grid.addExtension(new window.ColumnTogglingExtension());
            if (window.ExportToSqlManagerExtension) grid.addExtension(new window.ExportToSqlManagerExtension());
        }
    } catch (e) {
        console.warn('[DzCarrierManager] Manual Grid init failed:', e);
    }

    // Fallback: ensure bulk action dropdown is enabled when checkboxes are checked
    enableBulkActionsFallback(gridDiv);
}

/**
 * Fallback mechanism to enable bulk actions when checkboxes are selected.
 * This handles cases where the Grid component fails to initialize properly.
 */
function enableBulkActionsFallback(gridDiv) {
    var bulkBtn = gridDiv.querySelector('.js-bulk-action-btn, .bulk-action-btn, [data-toggle="dropdown"]');
    var checkboxes = gridDiv.querySelectorAll('input[type="checkbox"].js-bulk-action-checkbox, input.bulk-action-checkbox, tbody input[type="checkbox"]');
    var selectAll = gridDiv.querySelector('.js-bulk-action-select-all, thead input[type="checkbox"]');

    if (!bulkBtn || checkboxes.length === 0) {
        return;
    }

    function updateBulkButton() {
        var anyChecked = false;
        checkboxes.forEach(function (cb) {
            if (cb.checked) anyChecked = true;
        });

        if (anyChecked) {
            bulkBtn.removeAttribute('disabled');
            bulkBtn.classList.remove('disabled');
        }
    }

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', updateBulkButton);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateBulkButton();
        });
    }
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
    if (s === 'confirmed') return 'confirmed';

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
