/* ============================================================
   Blackview SA Portal — Main JavaScript
   ============================================================ */

'use strict';

/* -------------------------------------------------------
   0. MOBILE SIDEBAR DRAWER
   ------------------------------------------------------- */
(function initMobileDrawer() {
    var toggle  = document.getElementById('menuToggle');
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.getElementById('sidebar');

    if (!toggle) return;

    function openSidebar() {
        document.body.classList.add('sidebar-open');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        document.body.classList.remove('sidebar-open');
        document.body.style.overflow = '';
    }

    toggle.addEventListener('click', function () {
        document.body.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
    });

    if (overlay) overlay.addEventListener('click', closeSidebar);

    var closeBtn = document.getElementById('sidebarClose');
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

    // Close when any nav link is tapped on mobile
    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });
    }

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });
}());

/* -------------------------------------------------------
   1. SERIAL NUMBER FIELD GENERATOR (scan_in.php)
      Watches the #qty input; dynamically creates/removes
      serial number <input> rows in #serial-fields-container.
   ------------------------------------------------------- */
(function initSerialFieldGenerator() {
    var qtyInput  = document.getElementById('qty');
    var container = document.getElementById('serial-fields-container');

    if (!qtyInput || !container) return;

    function syncSerialFields() {
        var qty = parseInt(qtyInput.value, 10);
        if (isNaN(qty) || qty < 1) qty = 0;
        if (qty > 500) qty = 500; // safety cap

        var currentRows = container.querySelectorAll('.serial-field-row');
        var currentCount = currentRows.length;

        if (qty === currentCount) return; // nothing to do

        if (qty > currentCount) {
            // Add rows
            for (var i = currentCount + 1; i <= qty; i++) {
                var row    = document.createElement('div');
                row.className = 'serial-field-row';

                var label  = document.createElement('label');
                label.className = 'form-label';
                label.textContent = 'Serial #' + i;

                var input  = document.createElement('input');
                input.type        = 'text';
                input.name        = 'serial_no[]';
                input.className   = 'form-control serial-input';
                input.placeholder = 'Enter serial number';
                input.required    = true;
                input.setAttribute('autocomplete', 'off');

                row.appendChild(label);
                row.appendChild(input);
                container.appendChild(row);

                // Focus the first newly-added field
                if (i === currentCount + 1) {
                    setTimeout(function() { input.focus(); }, 50);
                }
            }
        } else {
            // Remove excess rows from the bottom
            var rows = container.querySelectorAll('.serial-field-row');
            for (var j = rows.length - 1; j >= qty; j--) {
                container.removeChild(rows[j]);
            }
        }
    }

    qtyInput.addEventListener('input', syncSerialFields);
    qtyInput.addEventListener('change', syncSerialFields);

    // Also run on page load (in case qty was prefilled on validation failure)
    syncSerialFields();
}());


/* -------------------------------------------------------
   1b. SERIAL TEXTAREA COUNTER (scan_in.php)
       Shows a live count of serials as the user types.
   ------------------------------------------------------- */
(function initSerialTextareaCounter() {
    var ta      = document.getElementById('serials_text');
    var display = document.getElementById('serial-count-display');
    if (!ta || !display) return;

    function countSerials() {
        var lines = ta.value.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l !== ''; });
        var unique = [...new Set(lines)];
        display.textContent = unique.length + ' serial(s) entered' + (unique.length !== lines.length ? ' (' + (lines.length - unique.length) + ' duplicate(s) will be ignored)' : '') + '.';
    }

    ta.addEventListener('input', countSerials);
    countSerials();
}());

(function initTakeOutSerialCounter() {
    var ta      = document.getElementById('serials_text_out');
    var display = document.getElementById('serial-count-display-out');
    if (!ta || !display) return;
    function countSerials() {
        var lines  = ta.value.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l !== ''; });
        var unique = [...new Set(lines)];
        display.textContent = unique.length + ' serial(s) entered' + (unique.length !== lines.length ? ' (' + (lines.length - unique.length) + ' duplicate(s) will be ignored)' : '') + '.';
    }
    ta.addEventListener('input', countSerials);
    countSerials();
}());


/* -------------------------------------------------------
   2. MOVE STOCK — Dynamic serial checkbox loader
      On change of product_id OR from_warehouse_id,
      AJAX-fetches available in_stock serials and renders
      them as checkboxes. Qty auto-calculates from selection.
   ------------------------------------------------------- */
(function initMoveStockSerials() {
    var prodSel  = document.getElementById('product_id');
    var fromSel  = document.getElementById('from_warehouse_id');
    var container     = document.getElementById('serial-selector-container');
    var checkboxDiv   = document.getElementById('serial-checkboxes');
    var loadingDiv    = document.getElementById('serial-loading');
    var emptyDiv      = document.getElementById('serial-empty');
    var countSpan     = document.getElementById('selectedCount');
    var selectAllBtn  = document.getElementById('selectAllSerials');
    var deselectAllBtn= document.getElementById('deselectAllSerials');

    if (!prodSel || !fromSel || !container) return;

    // Use BASE_URL defined inline in move_stock.php
    var baseUrl = (typeof BVZA_BASE_URL !== 'undefined') ? BVZA_BASE_URL : '';

    function updateSelectedCount() {
        var checked = checkboxDiv ? checkboxDiv.querySelectorAll('input[type="checkbox"]:checked').length : 0;
        if (countSpan) countSpan.textContent = checked + ' selected';
    }

    function loadSerials() {
        var pid = prodSel.value;
        var wid = fromSel.value;

        if (!pid || !wid) {
            if (container)  container.style.display = 'none';
            if (emptyDiv)   emptyDiv.style.display  = 'none';
            if (loadingDiv) loadingDiv.style.display = 'none';
            return;
        }

        if (container)  container.style.display  = 'none';
        if (emptyDiv)   emptyDiv.style.display   = 'none';
        if (loadingDiv) loadingDiv.style.display  = 'block';

        var url = baseUrl + '/inventory/move_stock.php?ajax=serials&product_id=' + encodeURIComponent(pid) + '&warehouse_id=' + encodeURIComponent(wid);

        fetch(url)
            .then(function(resp) { return resp.json(); })
            .then(function(serials) {
                if (loadingDiv) loadingDiv.style.display = 'none';

                if (!serials || serials.length === 0) {
                    if (emptyDiv) emptyDiv.style.display = 'block';
                    return;
                }

                // Build checkboxes
                if (checkboxDiv) {
                    checkboxDiv.innerHTML = '';
                    serials.forEach(function(sn) {
                        var item  = document.createElement('label');
                        item.className = 'serial-checkbox-item';

                        var cb = document.createElement('input');
                        cb.type  = 'checkbox';
                        cb.name  = 'serials[]';
                        cb.value = sn;
                        cb.addEventListener('change', updateSelectedCount);

                        var span = document.createElement('span');
                        span.textContent = sn;

                        item.appendChild(cb);
                        item.appendChild(span);
                        checkboxDiv.appendChild(item);
                    });
                }

                if (container) container.style.display = 'block';
                updateSelectedCount();
            })
            .catch(function(err) {
                if (loadingDiv) loadingDiv.style.display = 'none';
                console.error('Serial fetch failed:', err);
            });
    }

    prodSel.addEventListener('change', loadSerials);
    fromSel.addEventListener('change', loadSerials);

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            var cbs = checkboxDiv ? checkboxDiv.querySelectorAll('input[type="checkbox"]') : [];
            cbs.forEach(function(cb) { cb.checked = true; });
            updateSelectedCount();
        });
    }
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            var cbs = checkboxDiv ? checkboxDiv.querySelectorAll('input[type="checkbox"]') : [];
            cbs.forEach(function(cb) { cb.checked = false; });
            updateSelectedCount();
        });
    }

    // If the form had a POST failure, re-load serials for the pre-selected values
    if (prodSel.value && fromSel.value) {
        loadSerials();
    }
}());


/* -------------------------------------------------------
   3. SERIAL TOGGLE BUTTONS (view_stock.php & reports)
      Shows/hides the serial number expand list.
   ------------------------------------------------------- */
(function initSerialToggles() {
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.serial-toggle');
        if (!btn) return;

        // Case 1: data-target references an element ID (view_stock)
        var targetId = btn.getAttribute('data-target');
        if (targetId) {
            var el = document.getElementById(targetId);
            if (el) {
                var isHidden = el.style.display === 'none' || el.style.display === '';
                el.style.display = isHidden ? 'flex' : 'none';
                btn.textContent  = isHidden ? 'Hide serials' : ('Show ' + el.querySelectorAll('.serial-chip').length + ' serial(s)');
            }
            return;
        }

        // Case 2: data-full contains the full list (reports)
        var full = btn.getAttribute('data-full');
        if (full) {
            var preview = btn.previousElementSibling;
            if (preview) {
                var expanded = btn.dataset.expanded === '1';
                if (expanded) {
                    preview.textContent    = full.substring(0, 40) + (full.length > 40 ? '…' : '');
                    btn.textContent        = 'Show all';
                    btn.dataset.expanded   = '0';
                } else {
                    preview.textContent    = full;
                    btn.textContent        = 'Collapse';
                    btn.dataset.expanded   = '1';
                }
            }
        }
    });
}());


/* -------------------------------------------------------
   4. FLASH MESSAGE AUTO-DISMISS
      Flash alerts fade out after 6 seconds.
   ------------------------------------------------------- */
(function initFlashAutoDismiss() {
    var alerts = document.querySelectorAll('.flash-container .alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity    = '0';
            setTimeout(function() { alert.remove(); }, 500);
        }, 6000);
    });
}());


/* -------------------------------------------------------
   5. FORM VALIDATION — basic front-end helpers
   ------------------------------------------------------- */
(function initFormValidation() {
    var forms = document.querySelectorAll('form[novalidate]');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var requiredFields = form.querySelectorAll('[required]');
            var hasError = false;

            // Clear previous inline errors
            form.querySelectorAll('.field-error').forEach(function(el) { el.remove(); });
            form.querySelectorAll('.input-error').forEach(function(el) { el.classList.remove('input-error'); });

            requiredFields.forEach(function(field) {
                if (field.disabled) return;
                var val = field.value.trim();
                if (val === '' || (field.type === 'select-one' && val === '')) {
                    hasError = true;
                    field.classList.add('input-error');
                    var err = document.createElement('span');
                    err.className   = 'field-error';
                    err.textContent = 'This field is required.';
                    err.style.cssText = 'color:#B91C1C;font-size:.75rem;display:block;margin-top:.2rem;';
                    field.parentNode.appendChild(err);
                }
            });

            if (hasError) {
                e.preventDefault();
                // Scroll to first error
                var first = form.querySelector('.input-error');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });
}());


/* -------------------------------------------------------
   6. CONFIRM DIALOGS for destructive actions
      Any <a> or <button> with data-confirm="..." gets a
      browser confirm() before proceeding.
   ------------------------------------------------------- */
(function initConfirmDialogs() {
    document.addEventListener('click', function(e) {
        var el = e.target.closest('[data-confirm]');
        if (!el) return;
        var msg = el.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });
}());


/* -------------------------------------------------------
   7. TABLE SORT — lightweight click-to-sort on <th>
      Add class "sortable" to <th> elements to enable.
   ------------------------------------------------------- */
(function initTableSort() {
    document.querySelectorAll('table.table').forEach(function(table) {
        var ths = table.querySelectorAll('thead th.sortable');
        ths.forEach(function(th, colIndex) {
            th.style.cursor = 'pointer';
            th.title = 'Click to sort';
            var asc = true;

            th.addEventListener('click', function() {
                var tbody = table.querySelector('tbody');
                if (!tbody) return;
                var rows  = Array.from(tbody.querySelectorAll('tr'));

                rows.sort(function(a, b) {
                    var aVal = (a.cells[colIndex] ? a.cells[colIndex].textContent.trim() : '');
                    var bVal = (b.cells[colIndex] ? b.cells[colIndex].textContent.trim() : '');
                    // Numeric comparison if both parse as numbers
                    var aNum = parseFloat(aVal.replace(/,/g, ''));
                    var bNum = parseFloat(bVal.replace(/,/g, ''));
                    if (!isNaN(aNum) && !isNaN(bNum)) {
                        return asc ? aNum - bNum : bNum - aNum;
                    }
                    return asc
                        ? aVal.localeCompare(bVal, undefined, { sensitivity: 'base' })
                        : bVal.localeCompare(aVal, undefined, { sensitivity: 'base' });
                });

                rows.forEach(function(row) { tbody.appendChild(row); });

                // Update sort indicator
                ths.forEach(function(h) { h.textContent = h.textContent.replace(/ [▲▼]$/, ''); });
                th.textContent += asc ? ' ▲' : ' ▼';
                asc = !asc;
            });
        });
    });
}());

/* -------------------------------------------------------
   Tab switching
   Finds all .tab-btn elements within their .tab-group
   ancestor and switches the active tab panel on click.
   ------------------------------------------------------- */
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var group = this.closest('.tab-group');
        if (!group) return;
        group.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        group.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
        this.classList.add('active');
        var panel = group.querySelector('#' + this.dataset.tab);
        if (panel) panel.classList.add('active');
    });
});
