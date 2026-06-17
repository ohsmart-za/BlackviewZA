<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Scan In Stock';
$activeNav = 'stock';
$showBack  = true;
$backUrl   = 'mobile/stock.php';

$products   = $pdo->query('SELECT id, sku, name, COALESCE(is_serialised,1) AS is_serialised FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();

$openPOs = $pdo->query(
    "SELECT po.id, po.po_number, s.name AS supplier_name, w.name AS warehouse_name, w.id AS warehouse_id
     FROM purchase_orders po
     LEFT JOIN suppliers s  ON s.id  = po.supplier_id
     LEFT JOIN warehouses w ON w.id  = po.warehouse_id
     WHERE po.status NOT IN ('cancelled','received')
     ORDER BY po.created_at DESC"
)->fetchAll();

$errors  = [];
$success = false;
$summary = [];

// Build product lookup by id
$productById = [];
foreach ($products as $p) { $productById[$p['id']] = $p; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId   = (int)($_POST['product_id']   ?? 0);
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $sourceType  = trim($_POST['source_type']   ?? 'manual');
    $linkedPoId  = (int)($_POST['po_id']        ?? 0);
    $sourceRef   = trim($_POST['source_ref']    ?? '');

    $selectedProduct = $productById[$productId] ?? null;
    $isSerialised    = $selectedProduct ? (int)$selectedProduct['is_serialised'] : 1;

    if ($isSerialised) {
        $rawText = $_POST['serials_text'] ?? '';
        $serials = array_values(array_unique(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $rawText))))));
        $qty     = count($serials);
    } else {
        $serials = [];
        $qty     = max(0, (int)($_POST['bulk_qty'] ?? 0));
    }

    if ($productId   <= 0) $errors[] = 'Please select a product.';
    if ($warehouseId <= 0) $errors[] = 'Please select a warehouse.';
    if ($isSerialised  && $qty <= 0) $errors[] = 'Please enter at least one serial number.';
    if (!$isSerialised && $qty <= 0) $errors[] = 'Quantity must be at least 1.';

    $poData = null;
    if ($sourceType === 'po' && $linkedPoId > 0) {
        $poChk = $pdo->prepare("SELECT id, po_number FROM purchase_orders WHERE id = :id AND status NOT IN ('cancelled','received') LIMIT 1");
        $poChk->execute([':id' => $linkedPoId]);
        $poData = $poChk->fetch();
        if (!$poData) $errors[] = 'Selected Purchase Order is invalid or already fully received.';
    } elseif ($sourceType === 'po' && $linkedPoId <= 0) {
        $errors[] = 'Please select a Purchase Order.';
    }

    if (empty($errors) && $isSerialised && !empty($serials)) {
        $in  = implode(',', array_fill(0, count($serials), '?'));
        $chk = $pdo->prepare("SELECT serial_no FROM stock_items WHERE serial_no IN ($in)");
        $chk->execute($serials);
        $existing = $chk->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($existing)) {
            $errors[] = 'Serial(s) already exist: ' . implode(', ', $existing);
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $poIdForInsert = ($sourceType === 'po' && $linkedPoId > 0) ? $linkedPoId : null;

            if ($isSerialised) {
                $insSerial = $pdo->prepare(
                    'INSERT INTO stock_items (product_id, warehouse_id, po_id, serial_no, status, created_at)
                     VALUES (:pid, :wid, :po, :serial, "in_stock", NOW())'
                );
                foreach ($serials as $serial) {
                    $insSerial->execute([':pid' => $productId, ':wid' => $warehouseId, ':po' => $poIdForInsert, ':serial' => $serial]);
                }
            }

            if ($poIdForInsert) {
                $pdo->prepare("UPDATE purchase_order_items SET qty_received = qty_received + :n WHERE po_id = :po AND product_id = :prod")
                    ->execute([':n' => $qty, ':po' => $poIdForInsert, ':prod' => $productId]);

                $pt = $pdo->prepare("SELECT SUM(qty_ordered) AS ord, SUM(qty_received) AS rec FROM purchase_order_items WHERE po_id = :po");
                $pt->execute([':po' => $poIdForInsert]);
                $totals    = $pt->fetch();
                $newStatus = ((int)$totals['rec'] >= (int)$totals['ord']) ? 'received' : 'partial';
                $pdo->prepare("UPDATE purchase_orders SET status = :s WHERE id = :id")
                    ->execute([':s' => $newStatus, ':id' => $poIdForInsert]);
            }

            $pdo->prepare(
                'INSERT INTO inventory_stock (product_id, warehouse_id, qty, updated_at)
                 VALUES (:pid, :wid, :qty, NOW())
                 ON DUPLICATE KEY UPDATE qty = qty + :qty2, updated_at = NOW()'
            )->execute([':pid' => $productId, ':wid' => $warehouseId, ':qty' => $qty, ':qty2' => $qty]);

            $productName   = $selectedProduct['name'] ?? '';
            $warehouseName = '';
            foreach ($warehouses as $w) { if ($w['id'] == $warehouseId) $warehouseName = $w['name']; }

            $auditDetail = "Mobile: Scanned in $qty unit(s) of \"$productName\" into \"$warehouseName\".";
            if ($isSerialised && !empty($serials)) $auditDetail .= ' Serials: ' . implode(', ', $serials);
            logAudit($pdo, 'scan_in', 'stock_items', null, $auditDetail);

            $pdo->commit();
            $summary = ['product' => $productName, 'warehouse' => $warehouseName, 'qty' => $qty, 'is_serialised' => $isSerialised];
            $success = true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$productSerialMap = [];
foreach ($products as $p) { $productSerialMap[$p['id']] = (int)$p['is_serialised']; }

require_once __DIR__ . '/_shell.php';
?>

<div class="form-section">

    <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Scanned in <?= $summary['qty'] ?> unit<?= $summary['qty'] !== 1 ? 's' : '' ?></strong> of
        <?= htmlspecialchars($summary['product']) ?> into <?= htmlspecialchars($summary['warehouse']) ?>.
    </div>
    <?php endif; ?>

    <form method="POST" action="">

        <!-- Source -->
        <div class="field">
            <label>Source</label>
            <div class="radio-group">
                <div class="radio-pill">
                    <input type="radio" name="source_type" id="src-po" value="po"
                        <?= (($_POST['source_type'] ?? 'manual') === 'po') ? 'checked' : '' ?>>
                    <label for="src-po">Purchase Order</label>
                </div>
                <div class="radio-pill">
                    <input type="radio" name="source_type" id="src-manual" value="manual"
                        <?= (($_POST['source_type'] ?? 'manual') === 'manual') ? 'checked' : '' ?>>
                    <label for="src-manual">Manual</label>
                </div>
            </div>
        </div>

        <div id="po-section" style="display:none;">
            <div class="field">
                <label>Purchase Order</label>
                <select name="po_id" id="po_id_select" class="field-select">
                    <option value="">— Select PO —</option>
                    <?php foreach ($openPOs as $op): ?>
                    <option value="<?= $op['id'] ?>" data-warehouse-id="<?= $op['warehouse_id'] ?>"
                        <?= (!empty($_POST['po_id']) && $_POST['po_id'] == $op['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($op['po_number']) ?> — <?= htmlspecialchars($op['supplier_name'] ?? '') ?>
                        (<?= htmlspecialchars($op['warehouse_name']) ?>)
                    </option>
                    <?php endforeach; ?>
                    <?php if (empty($openPOs)): ?><option disabled>No open POs</option><?php endif; ?>
                </select>
            </div>
        </div>

        <div id="manual-section">
            <div class="field">
                <label>Reference (optional)</label>
                <input type="text" name="source_ref" class="field-input"
                    placeholder="e.g. Return, Initial stock..."
                    value="<?= htmlspecialchars($_POST['source_ref'] ?? '') ?>">
            </div>
        </div>

        <div class="field">
            <label>Product <span style="color:var(--danger)">*</span></label>
            <select name="product_id" id="product_id" class="field-select" required
                    onchange="onProductChange(this.value)">
                <option value="">— Select product —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"
                        data-serialised="<?= $p['is_serialised'] ?>"
                        <?= (!empty($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>>
                    [<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?>
                    <?= !$p['is_serialised'] ? ' (bulk)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Warehouse <span style="color:var(--danger)">*</span></label>
            <select name="warehouse_id" id="warehouse_id" class="field-select" required>
                <option value="">— Select warehouse —</option>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= $w['id'] ?>" <?= (!empty($_POST['warehouse_id']) && $_POST['warehouse_id'] == $w['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Serialised: chip area -->
        <div class="field" id="mob-section-serials">
            <label>Serial Numbers <span style="color:var(--danger)">*</span></label>
            <div class="serial-chip-area" id="serialChipArea">
                <input class="chip-input" type="text" placeholder="Scan or type, then Enter…" autocomplete="off" autocorrect="off" spellcheck="false">
            </div>
            <input type="hidden" name="serials_text" id="serialsHidden">
            <div class="serial-count">0 serials entered</div>
        </div>

        <!-- Non-serialised: qty -->
        <div class="field" id="mob-section-bulk" style="display:none;">
            <label>Quantity <span style="color:var(--danger)">*</span></label>
            <input type="number" name="bulk_qty" id="mob-bulk-qty" class="field-input"
                   min="1" step="1" placeholder="e.g. 50"
                   value="<?= !empty($_POST['bulk_qty']) ? (int)$_POST['bulk_qty'] : '' ?>">
            <small style="color:#9CA3AF;font-size:.8rem;">Bulk product — no serial numbers required.</small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12l7-7 7 7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Scan In Stock
            </button>
            <a href="<?= BASE_URL ?>/mobile/index.php" class="btn-secondary">Cancel</a>
        </div>

    </form>
</div>

<script>
var SERIALISED_MAP = <?= json_encode($productSerialMap) ?>;

window.onProductChange = function (pid) {
    var s = pid ? SERIALISED_MAP[pid] : 1;
    if (s === undefined) s = 1;
    var secS = document.getElementById('mob-section-serials');
    var secB = document.getElementById('mob-section-bulk');
    var bqIn = document.getElementById('mob-bulk-qty');
    var shIn = document.getElementById('serialsHidden');
    if (s) {
        if (secS) secS.style.display = '';
        if (secB) secB.style.display = 'none';
        if (bqIn) bqIn.required = false;
    } else {
        if (secS) secS.style.display = 'none';
        if (secB) secB.style.display = '';
        if (bqIn) bqIn.required = true;
    }
};

// Init on load
(function () {
    var prodSel = document.getElementById('product_id');
    if (prodSel && prodSel.value) onProductChange(prodSel.value);
}());

(function () {
    var radios     = document.querySelectorAll('input[name="source_type"]');
    var poSection  = document.getElementById('po-section');
    var manSection = document.getElementById('manual-section');
    var poSelect   = document.getElementById('po_id_select');
    var whSelect   = document.getElementById('warehouse_id');

    function toggleSource() {
        var val = document.querySelector('input[name="source_type"]:checked');
        if (!val) return;
        if (val.value === 'po') {
            poSection.style.display  = '';
            manSection.style.display = 'none';
            applyPoWarehouse();
        } else {
            poSection.style.display  = 'none';
            manSection.style.display = '';
            if (whSelect) whSelect.disabled = false;
        }
    }

    function applyPoWarehouse() {
        if (!poSelect || !whSelect) return;
        var opt = poSelect.options[poSelect.selectedIndex];
        var wid = opt ? opt.getAttribute('data-warehouse-id') : null;
        if (wid) {
            for (var i = 0; i < whSelect.options.length; i++) {
                if (whSelect.options[i].value == wid) {
                    whSelect.selectedIndex = i;
                    whSelect.disabled = true;
                    break;
                }
            }
        } else {
            whSelect.disabled = false;
        }
    }

    radios.forEach(function (r) { r.addEventListener('change', toggleSource); });
    if (poSelect) poSelect.addEventListener('change', applyPoWarehouse);
    toggleSource();

    // Wire hidden textarea
    var area   = document.getElementById('serialChipArea');
    var hidden = document.getElementById('serialsHidden');
    var form   = area ? area.closest('form') : null;
    if (form && hidden) {
        form.addEventListener('submit', function () {
            // hidden already synced by app.js chip logic
        });
    }
}());
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
