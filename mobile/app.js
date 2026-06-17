'use strict';

/* ---- User Sheet ---- */
(function () {
    var overlay = document.getElementById('userSheetOverlay');
    if (!overlay) return;

    window.openUserSheet = function () {
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    window.closeUserSheet = function () {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    };

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeUserSheet();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeUserSheet();
    });
}());

/* ---- Serial chip input ---- */
(function () {
    var areas = document.querySelectorAll('.serial-chip-area');
    areas.forEach(function (area) {
        var inp    = area.querySelector('.chip-input');
        var count  = area.parentNode.querySelector('.serial-count');
        var hidden = area.parentNode.querySelector('input[type="hidden"][name]');
        if (!inp) return;

        function getChips() {
            return Array.from(area.querySelectorAll('.serial-chip'));
        }

        function getValues() {
            return getChips().map(function (c) { return c.dataset.val; });
        }

        function updateCount() {
            var n = getChips().length;
            if (count) count.textContent = n + ' serial' + (n === 1 ? '' : 's') + ' entered';
            if (hidden) hidden.value = getValues().join('\n');
        }

        function addChip(val) {
            val = val.trim();
            if (!val) return;
            // No duplicate
            if (getValues().indexOf(val) !== -1) return;

            var chip = document.createElement('span');
            chip.className = 'serial-chip';
            chip.dataset.val = val;
            chip.innerHTML = '<span>' + val + '</span><button type="button" class="serial-chip-x" aria-label="Remove">&#x2715;</button>';
            chip.querySelector('.serial-chip-x').addEventListener('click', function () {
                area.removeChild(chip);
                updateCount();
            });
            area.insertBefore(chip, inp);
            inp.value = '';
            updateCount();
        }

        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === '\n' || e.key === 'Tab') {
                e.preventDefault();
                addChip(inp.value);
            }
        });

        // Handle scan (scanner sends \n at end)
        inp.addEventListener('input', function () {
            if (inp.value.endsWith('\n') || inp.value.endsWith('\r')) {
                addChip(inp.value.replace(/[\r\n]+$/, ''));
            }
        });

        // Focus chip area → focus input
        area.addEventListener('click', function (e) {
            if (e.target === area || e.target.tagName === 'SPAN') inp.focus();
        });

        area.addEventListener('focus', function () { area.classList.add('focused'); }, true);
        area.addEventListener('blur', function () { area.classList.remove('focused'); }, true);

        updateCount();
    });
}());

/* ---- Payment pills ---- */
(function () {
    var pills   = document.querySelectorAll('.pay-pill');
    var hidden  = document.getElementById('paymentMethodInput');
    if (!pills.length) return;

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            pills.forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');
            if (hidden) hidden.value = pill.dataset.method;
        });
    });
}());

/* ---- POS: serial scan + cart ---- */
(function () {
    var scanInput  = document.getElementById('posSerialInput');
    var scanBtn    = document.getElementById('posScanBtn');
    var cartDiv    = document.getElementById('posCart');
    var emptyDiv   = document.getElementById('posEmpty');
    var subtotalEl = document.getElementById('posSubtotal');
    var discountEl = document.getElementById('posDiscountAmt');
    var totalEl    = document.getElementById('posTotal');
    var discPctInp = document.getElementById('posDiscountPct');
    var submitBtn  = document.getElementById('posSubmitBtn');

    if (!scanInput || !cartDiv) return;

    var cartItems = [];
    var itemCount = 0;

    var baseUrl = (typeof BVZA_BASE_URL !== 'undefined') ? BVZA_BASE_URL : '';

    function recalc() {
        var sub = 0;
        cartItems.forEach(function (it) { sub += it.price; });
        var discPct = Math.min(10, Math.max(0, parseFloat((discPctInp ? discPctInp.value : 0)) || 0));
        var disc    = sub * (discPct / 100);
        var total   = sub - disc;

        if (subtotalEl) subtotalEl.textContent = 'R ' + sub.toFixed(2);
        if (discountEl) discountEl.textContent  = '- R ' + disc.toFixed(2);
        if (totalEl)    totalEl.textContent      = 'R ' + total.toFixed(2);

        if (emptyDiv)  emptyDiv.style.display   = cartItems.length ? 'none' : 'block';
        if (submitBtn) submitBtn.disabled        = cartItems.length === 0;
    }

    function addToCart(item) {
        var idx = itemCount++;
        cartItems.push(item);

        var card = document.createElement('div');
        card.className = 'pos-item-card';
        card.innerHTML =
            '<input type="hidden" name="item_product_id[]" value="' + item.product_id + '">'
            + '<input type="hidden" name="item_serial_no[]"  value="' + (item.serial_no || '') + '">'
            + '<div class="pos-item-name">' + item.product_name + '</div>'
            + '<div class="pos-item-meta">' + item.sku + (item.serial_no ? ' · ' + item.serial_no : '') + '</div>'
            + '<div class="pos-item-row">'
            + '<span style="font-size:13px;color:var(--text-muted);flex-shrink:0;">R</span>'
            + '<input type="number" name="item_unit_price[]" class="pos-price-input" value="' + item.price.toFixed(2) + '" min="0" step="0.01" data-idx="' + idx + '">'
            + '<input type="hidden" name="item_qty[]" value="1">'
            + '<button type="button" class="pos-remove" data-idx="' + idx + '">&#xd7;</button>'
            + '</div>';

        cartDiv.appendChild(card);

        card.querySelector('.pos-price-input').addEventListener('input', function () {
            cartItems[this.dataset.idx].price = parseFloat(this.value) || 0;
            recalc();
        });

        card.querySelector('.pos-remove').addEventListener('click', function () {
            var i = parseInt(this.dataset.idx, 10);
            cartItems[i] = { price: 0, product_id: 0, serial_no: '', product_name: '', sku: '' };
            card.remove();
            recalc();
        });

        recalc();
    }

    function doScan() {
        var sn = scanInput.value.trim();
        if (!sn) return;

        scanBtn.textContent = '...';
        scanBtn.disabled    = true;

        fetch(baseUrl + '/mobile/pos.php?ajax=validate_serial&serial_no=' + encodeURIComponent(sn))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                scanBtn.textContent = 'Add';
                scanBtn.disabled    = false;
                if (data.found) {
                    addToCart({
                        product_id:   data.product_id,
                        product_name: data.product_name,
                        sku:          data.sku,
                        serial_no:    data.serial_no,
                        price:        parseFloat(data.selling_price) || 0,
                    });
                    scanInput.value = '';
                    scanInput.focus();
                } else {
                    scanInput.select();
                    alert(data.message || 'Serial not found or not in stock.');
                }
            })
            .catch(function () {
                scanBtn.textContent = 'Add';
                scanBtn.disabled    = false;
                alert('Network error. Please try again.');
            });
    }

    scanBtn.addEventListener('click', doScan);
    scanInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); doScan(); }
    });

    if (discPctInp) discPctInp.addEventListener('input', recalc);

    recalc();
}());

/* ---- Flash auto-dismiss ---- */
(function () {
    document.querySelectorAll('.alert').forEach(function (a) {
        setTimeout(function () {
            a.style.transition = 'opacity .4s';
            a.style.opacity    = '0';
            setTimeout(function () { a.remove(); }, 400);
        }, 5000);
    });
}());
