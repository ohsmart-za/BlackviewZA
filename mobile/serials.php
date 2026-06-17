<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Serial Lookup';
$activeNav = 'stock';
$showBack  = true;
$backUrl   = 'mobile/stock.php';

// AJAX serial lookup
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup') {
    header('Content-Type: application/json');
    $sn = trim($_GET['sn'] ?? '');
    if ($sn === '') { echo json_encode(['found' => false]); exit; }

    $stmt = $pdo->prepare(
        "SELECT si.serial_no, si.status, si.created_at,
                p.name AS product_name, p.sku,
                w.name AS warehouse_name,
                inv.invoice_no, inv.created_at AS sold_at,
                c.name AS customer_name
         FROM stock_items si
         JOIN products p ON p.id = si.product_id
         LEFT JOIN warehouses w ON w.id = si.warehouse_id
         LEFT JOIN invoice_items ii ON ii.serial_no = si.serial_no
         LEFT JOIN invoices inv ON inv.id = ii.invoice_id
         LEFT JOIN customers c ON c.id = inv.customer_id
         WHERE si.serial_no = :sn
         LIMIT 1"
    );
    $stmt->execute([':sn' => $sn]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { echo json_encode(['found' => false]); exit; }

    echo json_encode([
        'found'         => true,
        'serial_no'     => $row['serial_no'],
        'status'        => $row['status'],
        'product_name'  => $row['product_name'],
        'sku'           => $row['sku'],
        'warehouse'     => $row['warehouse_name'] ?? '—',
        'scanned_at'    => $row['created_at'] ? date('d M Y', strtotime($row['created_at'])) : '—',
        'invoice_no'    => $row['invoice_no'] ?? null,
        'sold_at'       => $row['sold_at']    ? date('d M Y', strtotime($row['sold_at'])) : null,
        'customer'      => $row['customer_name'] ?? null,
    ]);
    exit;
}

require_once __DIR__ . '/_shell.php';
?>

<div class="search-wrap">
    <input type="text" id="snInput" class="search-input"
           placeholder="Enter or scan serial number…"
           autocomplete="off" autocorrect="off" spellcheck="false">
    <button type="button" class="search-btn" id="snBtn">Look Up</button>
</div>

<div class="page-pad">
    <p style="font-size:13px;color:var(--text-muted);margin-top:4px;">
        Type a serial number or use a barcode scanner. Results appear instantly.
    </p>
    <div id="snResult"></div>
</div>

<script>
(function () {
    var input  = document.getElementById('snInput');
    var btn    = document.getElementById('snBtn');
    var result = document.getElementById('snResult');
    var base   = '<?= BASE_URL ?>';

    var statusColors = {
        in_stock: { bg: '#DCFCE7', color: '#166534', label: 'In Stock' },
        moved:    { bg: '#DBEAFE', color: '#1E40AF', label: 'Moved'    },
        sold:     { bg: '#FEF3C7', color: '#92400E', label: 'Sold'     },
    };

    function lookup() {
        var sn = input.value.trim();
        if (!sn) return;
        btn.textContent  = '…';
        btn.disabled     = true;
        result.innerHTML = '';

        fetch(base + '/mobile/serials.php?ajax=lookup&sn=' + encodeURIComponent(sn))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.textContent = 'Look Up';
                btn.disabled    = false;

                if (!d.found) {
                    result.innerHTML = '<div class="serial-result-card" style="margin-top:16px;"><div class="serial-result-head" style="background:#B91C1C;">❌ ' + escHtml(sn) + '<span style="font-size:13px;font-weight:400;">Not found</span></div></div>';
                    return;
                }

                var sc = statusColors[d.status] || { bg: '#F3F4F6', color: '#6B7280', label: d.status };
                var rows = '';
                rows += infoRow('Product',   d.product_name);
                rows += infoRow('SKU',       '<span class="font-mono">' + escHtml(d.sku) + '</span>');
                rows += infoRow('Status',    '<span class="badge" style="background:' + sc.bg + ';color:' + sc.color + ';">' + sc.label + '</span>');
                rows += infoRow('Warehouse', d.warehouse);
                rows += infoRow('Scanned In', d.scanned_at);
                if (d.invoice_no) {
                    rows += infoRow('Invoice',   escHtml(d.invoice_no));
                    rows += infoRow('Sold',      d.sold_at || '—');
                    rows += infoRow('Customer',  d.customer || '—');
                }

                result.innerHTML =
                    '<div class="serial-result-card">'
                    + '<div class="serial-result-head">📦 ' + escHtml(d.serial_no) + '</div>'
                    + rows
                    + '</div>';
            })
            .catch(function() {
                btn.textContent = 'Look Up';
                btn.disabled    = false;
                result.innerHTML = '<div class="alert alert-error">Network error. Please try again.</div>';
            });
    }

    function infoRow(label, value) {
        return '<div class="info-row"><span class="info-label">' + label + '</span><span class="info-value">' + value + '</span></div>';
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    btn.addEventListener('click', lookup);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); lookup(); }
    });
    // Auto-lookup on scan (scanner appends \n)
    input.addEventListener('input', function() {
        if (input.value.endsWith('\n') || input.value.endsWith('\r')) {
            input.value = input.value.replace(/[\r\n]+$/, '');
            lookup();
        }
    });
    input.focus();
}());
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
