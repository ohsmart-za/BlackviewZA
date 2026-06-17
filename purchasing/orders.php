<?php
// ============================================================
// Blackview SA Portal — Purchasing: Purchase Orders
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Purchase Orders';
$errors    = [];
$editPO    = null;

// --- Cancel PO ---
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $cid  = (int)$_GET['cancel'];
    $stmt = $pdo->prepare("SELECT po_number, status FROM purchase_orders WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $cid]);
    $cpo  = $stmt->fetch();
    if ($cpo && !in_array($cpo['status'], ['cancelled', 'received'], true)) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = :id")->execute([':id' => $cid]);
        logAudit($pdo, 'cancel_po', 'purchase_orders', $cid, "Cancelled PO #{$cpo['po_number']}");
        setFlash('success', "Purchase Order #{$cpo['po_number']} has been cancelled.");
    } else {
        setFlash('error', 'That Purchase Order cannot be cancelled.');
    }
    header('Location: ' . BASE_URL . '/purchasing/orders.php');
    exit;
}

// Load dropdowns
$suppliers  = $pdo->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$warehouses = $pdo->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$products   = $pdo->query("SELECT id, sku, name, cost_price FROM products WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// --- Generate default PO number suggestion ---
$dateStr   = date('Ymd');
$poCountQ  = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE po_number LIKE :prefix");
$poCountQ->execute([':prefix' => "PO-{$dateStr}-%"]);
$poSeq     = (int)$poCountQ->fetchColumn() + 1;
$suggestedPONumber = sprintf('PO-%s-%03d', $dateStr, $poSeq);

// --- Handle POST (create PO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $poNumber    = trim($_POST['po_number']    ?? '');
    $supplierId  = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $orderDate   = trim($_POST['order_date']    ?? '');
    $expectedDate= trim($_POST['expected_date'] ?? '');
    $notes       = trim($_POST['notes']         ?? '');

    // Line items
    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemQtys       = $_POST['item_qty_ordered'] ?? [];
    $itemCosts      = $_POST['item_unit_cost']   ?? [];

    if ($poNumber    === '') $errors[] = 'PO Number is required.';
    if ($warehouseId === 0)  $errors[] = 'Please select a warehouse.';

    // Check PO number uniqueness
    if ($poNumber !== '' && empty($errors)) {
        $chk = $pdo->prepare('SELECT id FROM purchase_orders WHERE po_number = :po LIMIT 1');
        $chk->execute([':po' => $poNumber]);
        if ($chk->fetch()) {
            $errors[] = "PO Number \"$poNumber\" already exists. Please use a different number.";
        }
    }

    // Validate line items
    $lineItems = [];
    foreach ($itemProductIds as $i => $pid) {
        $pid  = (int)$pid;
        $qty  = (int)($itemQtys[$i]  ?? 0);
        $cost = (float)($itemCosts[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $lineItems[] = ['product_id' => $pid, 'qty_ordered' => $qty, 'unit_cost' => $cost];
        }
    }
    if (empty($lineItems)) $errors[] = 'Please add at least one line item with a quantity greater than 0.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare(
                "INSERT INTO purchase_orders (po_number, supplier_id, warehouse_id, order_date, expected_date, status, notes, created_by, created_at)
                 VALUES (:po, :sup, :wh, :od, :ed, 'ordered', :notes, :uid, NOW())"
            );
            $ins->execute([
                ':po'    => $poNumber,
                ':sup'   => $supplierId,
                ':wh'    => $warehouseId,
                ':od'    => $orderDate  !== '' ? $orderDate  : null,
                ':ed'    => $expectedDate !== '' ? $expectedDate : null,
                ':notes' => $notes,
                ':uid'   => $_SESSION['user_id'] ?? null,
            ]);
            $poId = (int)$pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO purchase_order_items (po_id, product_id, qty_ordered, qty_received, unit_cost)
                 VALUES (:po, :prod, :qty, 0, :cost)'
            );
            foreach ($lineItems as $li) {
                $insItem->execute([
                    ':po'   => $poId,
                    ':prod' => $li['product_id'],
                    ':qty'  => $li['qty_ordered'],
                    ':cost' => $li['unit_cost'],
                ]);
            }

            logAudit($pdo, 'create_po', 'purchase_orders', $poId,
                "Created PO #$poNumber with " . count($lineItems) . " line item(s)");
            $pdo->commit();
            setFlash('success', "Purchase Order #$poNumber created successfully.");
            header('Location: ' . BASE_URL . '/purchasing/order_view.php?id=' . $poId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch all POs for list
$poList = $pdo->query(
    "SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name,
            (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS item_count
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN warehouses w ON w.id = po.warehouse_id
     ORDER BY po.created_at DESC"
)->fetchAll();

$statusLabels = [
    'draft'     => 'Draft',
    'ordered'   => 'Ordered',
    'partial'   => 'Partial',
    'received'  => 'Received',
    'cancelled' => 'Cancelled',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Purchase Orders</h2>
    <p class="page-subtitle">Create and track purchase orders from suppliers.</p>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="two-col-layout">

    <!-- PO List -->
    <div class="col-main">
        <div class="card">
            <div class="card-header"><h3 class="card-title">All Purchase Orders</h3></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Warehouse</th>
                            <th>Order Date</th>
                            <th>Expected</th>
                            <th>Status</th>
                            <th class="text-right">Items</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($poList as $po): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td>
                            <td><?= htmlspecialchars($po['supplier_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($po['warehouse_name']) ?></td>
                            <td><?= $po['order_date']   ? date('d M Y', strtotime($po['order_date']))    : '—' ?></td>
                            <td><?= $po['expected_date'] ? date('d M Y', strtotime($po['expected_date'])) : '—' ?></td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($po['status']) ?>">
                                    <?= htmlspecialchars($statusLabels[$po['status']] ?? ucfirst($po['status'])) ?>
                                </span>
                            </td>
                            <td class="text-right"><?= (int)$po['item_count'] ?></td>
                            <td class="action-btns">
                                <a href="<?= BASE_URL ?>/purchasing/order_view.php?id=<?= $po['id'] ?>"
                                   class="btn btn-sm btn-outline">View</a>
                                <?php if (!in_array($po['status'], ['cancelled', 'received'], true)): ?>
                                <a href="?cancel=<?= $po['id'] ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Cancel PO #<?= htmlspecialchars($po['po_number']) ?>? This cannot be undone.')">
                                    Cancel
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($poList)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No purchase orders found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create PO form -->
    <div class="col-side">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Create Purchase Order</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate id="po-form">

                    <div class="form-group">
                        <label class="form-label">PO Number <span class="required">*</span></label>
                        <input type="text" name="po_number" class="form-control"
                               value="<?= htmlspecialchars($_POST['po_number'] ?? $suggestedPONumber) ?>"
                               placeholder="e.g. PO-20240101-001" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-control">
                            <option value="">— Select Supplier (optional) —</option>
                            <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"
                                <?= (isset($_POST['supplier_id']) && (int)$_POST['supplier_id'] === (int)$sup['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Warehouse <span class="required">*</span></label>
                        <select name="warehouse_id" class="form-control" required>
                            <option value="">— Select Warehouse —</option>
                            <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>"
                                <?= (isset($_POST['warehouse_id']) && (int)$_POST['warehouse_id'] === (int)$wh['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($wh['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Order Date</label>
                        <input type="date" name="order_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['order_date'] ?? date('Y-m-d')) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Expected Delivery Date</label>
                        <input type="date" name="expected_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['expected_date'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Optional order notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <!-- Line Items -->
                    <div class="form-group">
                        <label class="form-label">Line Items <span class="required">*</span></label>
                        <div id="po-line-items">
                            <div class="po-line-item" style="display:grid;grid-template-columns:1fr 60px 90px 28px;gap:.35rem;margin-bottom:.5rem;align-items:center;">
                                <select name="item_product_id[]" class="form-control form-control-sm">
                                    <option value="">— Product —</option>
                                    <?php foreach ($products as $prod): ?>
                                    <option value="<?= $prod['id'] ?>" data-cost="<?= number_format((float)$prod['cost_price'], 2, '.', '') ?>">
                                        <?= htmlspecialchars($prod['sku'] . ' — ' . $prod['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="item_qty_ordered[]" class="form-control form-control-sm"
                                       placeholder="Qty" min="1" step="1" value="1">
                                <input type="number" name="item_unit_cost[]" class="form-control form-control-sm"
                                       placeholder="Cost" min="0" step="0.01" value="0.00">
                                <button type="button" class="btn btn-sm btn-danger po-remove-btn" title="Remove" style="display:none;">×</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" id="po-add-line" style="margin-top:.35rem;">+ Add Product</button>
                        <small class="form-hint" style="display:block;margin-top:.3rem;">Qty | Unit Cost (R)</small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Create Purchase Order</button>
                    </div>

                </form>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    var productOptions = <?= json_encode(array_map(function($p) {
        return ['id' => $p['id'], 'label' => $p['sku'] . ' — ' . $p['name'], 'cost' => number_format((float)$p['cost_price'], 2, '.', '')];
    }, $products)) ?>;

    function buildSelect(selected) {
        var html = '<option value="">— Product —</option>';
        productOptions.forEach(function(p) {
            html += '<option value="' + p.id + '" data-cost="' + p.cost + '"' +
                    (selected == p.id ? ' selected' : '') + '>' +
                    p.label.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</option>';
        });
        return html;
    }

    function lineItemHTML() {
        return '<div class="po-line-item" style="display:grid;grid-template-columns:1fr 60px 90px 28px;gap:.35rem;margin-bottom:.5rem;align-items:center;">' +
               '<select name="item_product_id[]" class="form-control form-control-sm po-product-sel">' + buildSelect(0) + '</select>' +
               '<input type="number" name="item_qty_ordered[]" class="form-control form-control-sm" placeholder="Qty" min="1" step="1" value="1">' +
               '<input type="number" name="item_unit_cost[]" class="form-control form-control-sm po-cost-field" placeholder="Cost" min="0" step="0.01" value="0.00">' +
               '<button type="button" class="btn btn-sm btn-danger po-remove-btn" title="Remove">×</button>' +
               '</div>';
    }

    document.getElementById('po-add-line').addEventListener('click', function () {
        var container = document.getElementById('po-line-items');
        var div = document.createElement('div');
        div.innerHTML = lineItemHTML();
        var newRow = div.firstChild;
        container.appendChild(newRow);
        updateRemoveButtons();
        newRow.querySelector('.po-product-sel').addEventListener('change', handleProductChange);
    });

    function updateRemoveButtons() {
        var rows = document.querySelectorAll('.po-line-item');
        rows.forEach(function(row, idx) {
            var btn = row.querySelector('.po-remove-btn');
            if (btn) btn.style.display = rows.length > 1 ? '' : 'none';
        });
    }

    function handleProductChange(e) {
        var sel  = e.target;
        var opt  = sel.options[sel.selectedIndex];
        var cost = opt.getAttribute('data-cost');
        var row  = sel.closest('.po-line-item');
        if (row && cost !== null) {
            var cf = row.querySelector('.po-cost-field');
            if (cf) cf.value = cost;
        }
    }

    // Bind initial row
    document.querySelectorAll('.po-product-sel').forEach(function(sel) {
        sel.addEventListener('change', handleProductChange);
    });

    document.getElementById('po-line-items').addEventListener('click', function (e) {
        if (e.target.classList.contains('po-remove-btn')) {
            e.target.closest('.po-line-item').remove();
            updateRemoveButtons();
        }
    });

    updateRemoveButtons();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
