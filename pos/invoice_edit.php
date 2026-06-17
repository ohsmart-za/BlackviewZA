<?php
// ============================================================
// Blackview SA Portal — Edit Posted Invoice
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();

$invoiceId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId === 0) {
    setFlash('error', 'Invalid invoice ID.');
    header('Location: ' . BASE_URL . '/pos/index.php');
    exit;
}

// Load invoice
$invStmt = $pdo->prepare(
    "SELECT inv.*, c.name AS customer_name, c.email AS customer_email,
            c.phone AS customer_phone, c.address AS customer_address,
            c.id_number AS customer_id_number, c.company_name AS customer_company,
            c.vat_no AS customer_vat_no, c.contact_type
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     WHERE inv.id = :id LIMIT 1"
);
$invStmt->execute([':id' => $invoiceId]);
$invoice = $invStmt->fetch();

if (!$invoice) {
    setFlash('error', 'Invoice not found.');
    header('Location: ' . BASE_URL . '/pos/index.php');
    exit;
}

// Permission check — must have can_edit_invoices, be admin, or invoice unlocked
$_editUser = currentUser();
$canEdit   = false;
$editUnlocked = false;
try {
    $unlockChk = $pdo->prepare("SELECT edit_unlocked FROM invoices WHERE id = :id LIMIT 1");
    $unlockChk->execute([':id' => $invoiceId]);
    $editUnlocked = (bool)$unlockChk->fetchColumn();
} catch (Throwable $e) { /* edit_unlocked column not yet added */ }

// Fetch can_edit_invoices permission from DB (not in session)
$userCanEditInv = false;
try {
    $permRow = $pdo->prepare("SELECT can_edit_invoices FROM users WHERE id=:id LIMIT 1");
    $permRow->execute([':id' => $_SESSION['user_id']]);
    $userCanEditInv = (bool)$permRow->fetchColumn();
} catch (Throwable $e) { /* column not yet added */ }

$canEdit = $userCanEditInv || isAdmin() || $editUnlocked;

if (!$canEdit) {
    setFlash('error', 'You do not have permission to edit this invoice.');
    header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
    exit;
}

// Load current line items
$lineItems = $pdo->prepare(
    "SELECT ii.*, p.name AS product_name, p.sku, p.is_serialised, w.name AS warehouse_name
     FROM invoice_items ii
     JOIN products p ON p.id = ii.product_id
     LEFT JOIN warehouses w ON w.id = ii.warehouse_id
     WHERE ii.invoice_id = :inv ORDER BY ii.id ASC"
);
$lineItems->execute([':inv' => $invoiceId]);
$currentItems = $lineItems->fetchAll();

// Products list for "add item" dropdown
$products = $pdo->query(
    "SELECT id, sku, name, selling_price, COALESCE(vat_rate,15) AS vat_rate,
            COALESCE(is_serialised,1) AS is_serialised
     FROM products WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();

// Payment methods
$pmLabels = ['cash' => 'Cash', 'eft' => 'EFT', 'card' => 'Card'];
try {
    $pmRows = $pdo->query("SELECT code, name FROM payment_methods WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
    if (!empty($pmRows)) {
        $pmLabels = [];
        foreach ($pmRows as $pm) { $pmLabels[$pm['code']] = $pm['name']; }
    }
} catch (Throwable $e) { /* fallback */ }

$channelLabels = [
    'takealot' => 'Takealot',
    'makro'    => 'Makro',
    'instore'  => 'In-Store',
    'email'    => 'Email',
    'other'    => 'Other',
];

// ============================================================
// POST: Save edits
// ============================================================
$editErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invoice_edit') {

    $custName    = trim($_POST['customer_name']      ?? '');
    $custEmail   = trim($_POST['customer_email']     ?? '');
    $custPhone   = trim($_POST['customer_phone']     ?? '');
    $custAddress = trim($_POST['customer_address']   ?? '');
    $custIdNum   = trim($_POST['customer_id_number'] ?? '');
    $channel     = trim($_POST['channel']            ?? 'instore');
    $payMethod   = trim($_POST['payment_method']     ?? 'cash');
    $notes       = trim($_POST['notes']              ?? '');
    $discountPct = min(100.0, max(0.0, (float)($_POST['discount_pct'] ?? 0)));

    $keepIds     = array_map('intval', $_POST['item_id']          ?? []);
    $itemPids    = array_map('intval', $_POST['item_product_id']  ?? []);
    $itemSerials = $_POST['item_serial_no']  ?? [];
    $itemPrices  = $_POST['item_unit_price'] ?? [];
    $itemQtys    = array_map('intval',        $_POST['item_qty']  ?? []);

    if ($custName === '') $editErrors[] = 'Customer name is required.';
    if (empty($itemPids))  $editErrors[] = 'Invoice must have at least one item.';

    // Build new item list
    $newItems = [];
    foreach ($itemPids as $i => $pid) {
        if ($pid <= 0) continue;
        $newItems[] = [
            'item_id'    => (int)($keepIds[$i] ?? 0),
            'product_id' => $pid,
            'serial_no'  => trim($itemSerials[$i] ?? ''),
            'unit_price' => round((float)($itemPrices[$i] ?? 0), 4),
            'qty'        => max(1, $itemQtys[$i] ?? 1),
        ];
    }
    if (empty($newItems)) $editErrors[] = 'No valid items found.';

    if (empty($editErrors)) {
        try {
            $pdo->beginTransaction();

            // --- 1. Update/create customer ---
            $customerId = $invoice['customer_id'];
            if ($customerId) {
                $pdo->prepare(
                    'UPDATE customers SET name=:n, email=:e, phone=:p, address=:a, id_number=:i WHERE id=:id'
                )->execute([':n'=>$custName,':e'=>$custEmail,':p'=>$custPhone,
                             ':a'=>$custAddress,':i'=>$custIdNum,':id'=>$customerId]);
            } else {
                if ($custEmail !== '') {
                    $chk2 = $pdo->prepare('SELECT id FROM customers WHERE email=:e LIMIT 1');
                    $chk2->execute([':e' => $custEmail]);
                    $existing = $chk2->fetchColumn();
                    if ($existing) {
                        $customerId = (int)$existing;
                    }
                }
                if (!$customerId) {
                    $pdo->prepare(
                        'INSERT INTO customers (name,email,phone,address,id_number,created_at)
                         VALUES (:n,:e,:p,:a,:i,NOW())'
                    )->execute([':n'=>$custName,':e'=>$custEmail,':p'=>$custPhone,
                                ':a'=>$custAddress,':i'=>$custIdNum]);
                    $customerId = (int)$pdo->lastInsertId();
                }
            }

            // --- 2. Figure out which old items are removed ---
            $oldItemIds    = array_column($currentItems, 'id');
            $keepingIds    = array_filter(array_column($newItems, 'item_id'));
            $removedItemIds = array_diff($oldItemIds, $keepingIds);

            // Build old item map for audit
            $oldItemMap = [];
            foreach ($currentItems as $ci) { $oldItemMap[$ci['id']] = $ci; }

            // Restore stock for removed items
            foreach ($removedItemIds as $rmId) {
                $rmItem = $oldItemMap[$rmId] ?? null;
                if (!$rmItem) continue;
                if ($rmItem['serial_no'] !== '' && $rmItem['serial_no'] !== null) {
                    $pdo->prepare("UPDATE stock_items SET status='in_stock' WHERE serial_no=:sn")
                        ->execute([':sn' => $rmItem['serial_no']]);
                }
                $pdo->prepare(
                    "UPDATE inventory_stock SET qty = qty + :qty
                     WHERE product_id=:pid AND warehouse_id=:wid"
                )->execute([':qty'=>$rmItem['qty'],':pid'=>$rmItem['product_id'],':wid'=>$rmItem['warehouse_id']]);
            }

            // Delete removed invoice_items
            if (!empty($removedItemIds)) {
                $inList = implode(',', array_map('intval', $removedItemIds));
                $pdo->exec("DELETE FROM invoice_items WHERE id IN ($inList)");
            }

            // --- 3. Process each item ---
            $vatRate  = 15.0;
            $subtotal = 0;

            foreach ($newItems as &$ni) {
                // Resolve warehouse for serial items
                if ($ni['serial_no'] !== '') {
                    $snChk = $pdo->prepare(
                        "SELECT warehouse_id FROM stock_items WHERE serial_no=:sn AND (status='in_stock' OR status='sold') LIMIT 1"
                    );
                    $snChk->execute([':sn' => $ni['serial_no']]);
                    $snRow = $snChk->fetch();
                    $ni['warehouse_id'] = $snRow ? $snRow['warehouse_id'] : null;
                } else {
                    // existing item: keep warehouse; new item: find any warehouse with stock
                    if ($ni['item_id'] > 0 && isset($oldItemMap[$ni['item_id']])) {
                        $ni['warehouse_id'] = $oldItemMap[$ni['item_id']]['warehouse_id'];
                    } else {
                        $wChk = $pdo->prepare(
                            "SELECT warehouse_id FROM inventory_stock WHERE product_id=:pid AND qty>=:qty LIMIT 1"
                        );
                        $wChk->execute([':pid'=>$ni['product_id'],':qty'=>$ni['qty']]);
                        $wRow = $wChk->fetch();
                        if (!$wRow) {
                            throw new RuntimeException(
                                "Insufficient stock for product ID {$ni['product_id']}."
                            );
                        }
                        $ni['warehouse_id'] = $wRow['warehouse_id'];
                    }
                }

                $lineSubtotal = round($ni['unit_price'] * $ni['qty'], 2);
                $lineTotal    = round($ni['unit_price'] * $ni['qty'] * (1 + $vatRate / 100), 2);
                $lineVat      = round($lineTotal - $lineSubtotal, 2);
                $subtotal    += $lineSubtotal;

                if ($ni['item_id'] > 0) {
                    // UPDATE existing item
                    $old = $oldItemMap[$ni['item_id']];
                    // If serial changed: restore old, mark new as sold
                    if (($old['serial_no'] ?? '') !== $ni['serial_no']) {
                        if (!empty($old['serial_no'])) {
                            $pdo->prepare("UPDATE stock_items SET status='in_stock' WHERE serial_no=:sn")
                                ->execute([':sn' => $old['serial_no']]);
                        }
                        if ($ni['serial_no'] !== '') {
                            $pdo->prepare("UPDATE stock_items SET status='sold' WHERE serial_no=:sn")
                                ->execute([':sn' => $ni['serial_no']]);
                        }
                    }
                    // If qty changed on non-serialised: adjust inventory_stock
                    if (empty($ni['serial_no']) && $old['qty'] !== $ni['qty']) {
                        $qtyDiff = $ni['qty'] - $old['qty'];
                        if ($qtyDiff > 0) {
                            // Need more stock
                            $pdo->prepare(
                                "UPDATE inventory_stock SET qty = GREATEST(0, qty - :d)
                                 WHERE product_id=:pid AND warehouse_id=:wid"
                            )->execute([':d'=>$qtyDiff,':pid'=>$ni['product_id'],':wid'=>$ni['warehouse_id']]);
                        } else {
                            // Returning stock
                            $pdo->prepare(
                                "UPDATE inventory_stock SET qty = qty + :d
                                 WHERE product_id=:pid AND warehouse_id=:wid"
                            )->execute([':d'=>abs($qtyDiff),':pid'=>$ni['product_id'],':wid'=>$ni['warehouse_id']]);
                        }
                    }
                    $pdo->prepare(
                        "UPDATE invoice_items SET product_id=:pid, serial_no=:sn, warehouse_id=:wid,
                                qty=:qty, unit_price=:price, vat_rate=:vr, vat_amount=:va, line_total=:lt
                         WHERE id=:id"
                    )->execute([
                        ':pid'   => $ni['product_id'],
                        ':sn'    => $ni['serial_no'] ?: null,
                        ':wid'   => $ni['warehouse_id'],
                        ':qty'   => $ni['qty'],
                        ':price' => $ni['unit_price'],
                        ':vr'    => $vatRate,
                        ':va'    => $lineVat,
                        ':lt'    => $lineTotal,
                        ':id'    => $ni['item_id'],
                    ]);
                } else {
                    // INSERT new item
                    if ($ni['serial_no'] !== '') {
                        $pdo->prepare("UPDATE stock_items SET status='sold' WHERE serial_no=:sn")
                            ->execute([':sn' => $ni['serial_no']]);
                    } else {
                        $pdo->prepare(
                            "UPDATE inventory_stock SET qty=GREATEST(0,qty-:qty)
                             WHERE product_id=:pid AND warehouse_id=:wid"
                        )->execute([':qty'=>$ni['qty'],':pid'=>$ni['product_id'],':wid'=>$ni['warehouse_id']]);
                    }
                    $pdo->prepare(
                        "INSERT INTO invoice_items
                            (invoice_id, product_id, serial_no, warehouse_id, qty, unit_price, vat_rate, vat_amount, line_total)
                         VALUES (:inv,:pid,:sn,:wid,:qty,:price,:vr,:va,:lt)"
                    )->execute([
                        ':inv'   => $invoiceId,
                        ':pid'   => $ni['product_id'],
                        ':sn'    => $ni['serial_no'] ?: null,
                        ':wid'   => $ni['warehouse_id'],
                        ':qty'   => $ni['qty'],
                        ':price' => $ni['unit_price'],
                        ':vr'    => $vatRate,
                        ':va'    => $lineVat,
                        ':lt'    => $lineTotal,
                    ]);
                }
            }
            unset($ni);

            // --- 4. Recalculate invoice totals ---
            $discountAmount = round($subtotal * $discountPct / 100, 2);
            $discountedSub  = round($subtotal - $discountAmount, 2);
            $grandTotal     = round($discountedSub * (1 + $vatRate / 100), 2);
            $vatTotal       = round($grandTotal - $discountedSub, 2);

            // --- 5. Update invoice header ---
            $pdo->prepare(
                "UPDATE invoices SET
                    customer_id=:cust, channel=:ch, payment_method=:pm,
                    discount_pct=:dpct, discount_amount=:damt,
                    subtotal=:sub, vat_amount=:vat, total=:tot,
                    notes=:notes,
                    edit_unlocked=0, edit_unlocked_by=NULL, edit_unlocked_at=NULL,
                    last_edited_by=:uid, last_edited_at=NOW()
                 WHERE id=:id"
            )->execute([
                ':cust'  => $customerId,
                ':ch'    => $channel,
                ':pm'    => $payMethod,
                ':dpct'  => $discountPct,
                ':damt'  => $discountAmount,
                ':sub'   => $discountedSub,
                ':vat'   => $vatTotal,
                ':tot'   => $grandTotal,
                ':notes' => $notes,
                ':uid'   => $_SESSION['user_id'],
                ':id'    => $invoiceId,
            ]);

            // --- 6. Audit log ---
            $auditDetail = sprintf(
                'Invoice %s edited by %s. New total: R %s (was R %s). Items: %d.',
                $invoice['invoice_no'],
                $_editUser['name'],
                number_format($grandTotal, 2),
                number_format((float)$invoice['total'], 2),
                count($newItems)
            );
            logAudit($pdo, 'edit_invoice', 'invoices', $invoiceId, $auditDetail);

            $pdo->commit();
            setFlash('success', 'Invoice updated successfully.');
            header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $editErrors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Invoice — ' . htmlspecialchars($invoice['invoice_no']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div>
        <h2 class="page-title">Edit Invoice</h2>
        <p class="page-subtitle"><?= htmlspecialchars($invoice['invoice_no']) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $invoiceId ?>" class="btn btn-outline btn-sm">← Back to Invoice</a>
</div>

<?php foreach ($editErrors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if ($editUnlocked): ?>
<div class="alert alert-warning" style="display:flex;align-items:center;gap:.6rem;">
    <span style="font-size:1.2rem;">🔓</span>
    <div>This invoice has been <strong>unlocked for a one-time edit</strong>. After saving, it will be locked again.</div>
</div>
<?php endif; ?>

<form method="POST" action="" id="invoice-edit-form">
<input type="hidden" name="action" value="save_invoice_edit">

<div class="two-col-layout">

<!-- LEFT: Customer + Settings -->
<div class="col-side">
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Customer Details</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Customer Name <span class="required">*</span></label>
                <input type="text" name="customer_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['customer_name'] ?? $invoice['customer_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="customer_email" class="form-control"
                       value="<?= htmlspecialchars($_POST['customer_email'] ?? $invoice['customer_email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="customer_phone" class="form-control"
                       value="<?= htmlspecialchars($_POST['customer_phone'] ?? $invoice['customer_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea name="customer_address" class="form-control" rows="2"><?= htmlspecialchars($_POST['customer_address'] ?? $invoice['customer_address'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Account No</label>
                <input type="text" name="customer_id_number" class="form-control"
                       value="<?= htmlspecialchars($_POST['customer_id_number'] ?? $invoice['customer_id_number'] ?? '') ?>"
                       style="font-family:monospace;letter-spacing:.03em;">
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Invoice Settings</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Channel</label>
                <select name="channel" class="form-control form-select">
                    <?php foreach ($channelLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= (($_POST['channel'] ?? $invoice['channel']) === $val) ? 'selected' : '' ?>>
                        <?= $lbl ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-control form-select">
                    <?php foreach ($pmLabels as $code => $lbl): ?>
                    <option value="<?= $code ?>" <?= (($_POST['payment_method'] ?? $invoice['payment_method']) === $code) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Discount (%)</label>
                <input type="number" name="discount_pct" class="form-control"
                       min="0" max="100" step="0.01"
                       value="<?= (float)($_POST['discount_pct'] ?? $invoice['discount_pct'] ?? 0) ?>"
                       id="edit-discount">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($_POST['notes'] ?? $invoice['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Totals summary -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Totals (preview)</h3></div>
        <div class="card-body">
            <table style="width:100%;font-size:.95rem;">
                <tr><td style="padding:.3rem 0;color:#6B7280;">Subtotal (excl.)</td>
                    <td style="text-align:right;" id="preview-sub">R 0.00</td></tr>
                <tr><td style="padding:.3rem 0;color:#6B7280;">Discount</td>
                    <td style="text-align:right;" id="preview-disc">R 0.00</td></tr>
                <tr><td style="padding:.3rem 0;color:#6B7280;">VAT (15%)</td>
                    <td style="text-align:right;" id="preview-vat">R 0.00</td></tr>
                <tr style="font-weight:700;border-top:2px solid #E5E7EB;">
                    <td style="padding-top:.5rem;">Total</td>
                    <td style="text-align:right;padding-top:.5rem;" id="preview-total">R 0.00</td></tr>
            </table>
        </div>
    </div>
</div>

<!-- RIGHT: Line items -->
<div class="col-main">
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Line Items</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="addNewItemRow()">+ Add Item</button>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table" id="edit-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="width:140px;">Serial No</th>
                            <th style="width:80px;">Qty</th>
                            <th style="width:120px;">Unit Price (excl.)</th>
                            <th style="width:110px;">Line Total (incl.)</th>
                            <th style="width:50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="edit-items-body">
                    <?php foreach ($currentItems as $idx => $item): ?>
                    <tr class="edit-item-row" data-idx="<?= $idx ?>">
                        <td>
                            <input type="hidden" name="item_id[]" value="<?= $item['id'] ?>">
                            <select name="item_product_id[]" class="form-control form-select item-product-sel"
                                    style="min-width:180px;" onchange="onProductChange(this)">
                                <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                    data-price="<?= number_format((float)$p['selling_price'], 4, '.', '') ?>"
                                    data-serialised="<?= (int)($p['is_serialised'] ?? 1) ?>"
                                    <?= ($p['id'] == $item['product_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="item_serial_no[]" class="form-control item-serial"
                                   value="<?= htmlspecialchars($item['serial_no'] ?? '') ?>"
                                   placeholder="—" style="width:130px;">
                        </td>
                        <td>
                            <input type="number" name="item_qty[]" class="form-control item-qty"
                                   min="1" value="<?= (int)$item['qty'] ?>"
                                   style="width:70px;" oninput="recalcTotals()">
                        </td>
                        <td>
                            <input type="number" name="item_unit_price[]" class="form-control item-price"
                                   min="0" step="0.0001" value="<?= number_format((float)$item['unit_price'], 4, '.', '') ?>"
                                   style="width:110px;" oninput="recalcTotals()">
                        </td>
                        <td class="item-line-total" style="font-weight:600;padding-top:.8rem;">
                            R <?= number_format((float)$item['line_total'], 2) ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm" style="color:#DC2626;padding:.25rem .5rem;"
                                    onclick="removeItemRow(this)" title="Remove">✕</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="form-actions" style="margin-top:1.5rem;">
        <button type="submit" class="btn btn-primary btn-lg">💾 Save Changes</button>
        <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $invoiceId ?>" class="btn btn-outline">Cancel</a>
    </div>
</div>

</div><!-- .two-col-layout -->
</form>

<!-- Product data for JS -->
<script>
var PRODUCTS_DATA = <?= json_encode(array_map(function($p) {
    return [
        'id'          => (int)$p['id'],
        'name'        => $p['name'],
        'sku'         => $p['sku'],
        'price'       => (float)$p['selling_price'],
        'is_serialised' => (int)($p['is_serialised'] ?? 1),
    ];
}, $products)) ?>;

var VAT_RATE = 0.15;

function recalcTotals() {
    var rows    = document.querySelectorAll('#edit-items-body .edit-item-row');
    var subtotal = 0;

    rows.forEach(function(row) {
        var price = parseFloat(row.querySelector('.item-price').value) || 0;
        var qty   = parseInt(row.querySelector('.item-qty').value)     || 1;
        var lineSub   = Math.round(price * qty * 100) / 100;
        var lineTotal = Math.round(price * qty * (1 + VAT_RATE) * 100) / 100;
        subtotal += lineSub;
        var lt = row.querySelector('.item-line-total');
        if (lt) lt.textContent = 'R ' + lineTotal.toFixed(2);
    });

    var discPct   = parseFloat(document.getElementById('edit-discount').value) || 0;
    var discAmt   = Math.round(subtotal * discPct / 100 * 100) / 100;
    var discSub   = Math.round((subtotal - discAmt) * 100) / 100;
    var grandTotal = Math.round(discSub * (1 + VAT_RATE) * 100) / 100;
    var vatTotal   = Math.round((grandTotal - discSub) * 100) / 100;

    document.getElementById('preview-sub').textContent   = 'R ' + subtotal.toFixed(2);
    document.getElementById('preview-disc').textContent  = discAmt > 0 ? '-R ' + discAmt.toFixed(2) : 'R 0.00';
    document.getElementById('preview-vat').textContent   = 'R ' + vatTotal.toFixed(2);
    document.getElementById('preview-total').textContent = 'R ' + grandTotal.toFixed(2);
}

function onProductChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var row = sel.closest('.edit-item-row');
    if (!opt || !row) return;
    var price = parseFloat(opt.dataset.price) || 0;
    var priceInput = row.querySelector('.item-price');
    if (priceInput) priceInput.value = price.toFixed(4);
    recalcTotals();
}

function removeItemRow(btn) {
    var row = btn.closest('.edit-item-row');
    if (!row) return;
    var tbody = document.getElementById('edit-items-body');
    if (tbody.querySelectorAll('.edit-item-row').length <= 1) {
        alert('Invoice must have at least one item.');
        return;
    }
    row.remove();
    recalcTotals();
}

var _newRowIdx = 9000;
function addNewItemRow() {
    var tbody = document.getElementById('edit-items-body');
    var idx   = _newRowIdx++;
    var opts  = PRODUCTS_DATA.map(function(p) {
        return '<option value="' + p.id + '" data-price="' + p.price.toFixed(4)
             + '" data-serialised="' + p.is_serialised + '">'
             + p.name.replace(/</g,'&lt;') + ' (' + p.sku.replace(/</g,'&lt;') + ')</option>';
    }).join('');

    var tr = document.createElement('tr');
    tr.className = 'edit-item-row';
    tr.dataset.idx = idx;
    tr.innerHTML =
        '<td>'
        + '<input type="hidden" name="item_id[]" value="0">'
        + '<select name="item_product_id[]" class="form-control form-select item-product-sel" style="min-width:180px;" onchange="onProductChange(this)">'
        + opts + '</select></td>'
        + '<td><input type="text" name="item_serial_no[]" class="form-control item-serial" placeholder="—" style="width:130px;"></td>'
        + '<td><input type="number" name="item_qty[]" class="form-control item-qty" min="1" value="1" style="width:70px;" oninput="recalcTotals()"></td>'
        + '<td><input type="number" name="item_unit_price[]" class="form-control item-price" min="0" step="0.0001" value="0.0000" style="width:110px;" oninput="recalcTotals()"></td>'
        + '<td class="item-line-total" style="font-weight:600;padding-top:.8rem;">R 0.00</td>'
        + '<td><button type="button" class="btn btn-sm" style="color:#DC2626;padding:.25rem .5rem;" onclick="removeItemRow(this)" title="Remove">✕</button></td>';
    tbody.appendChild(tr);

    // Set default price from first product
    var firstSel = tr.querySelector('.item-product-sel');
    if (firstSel) onProductChange(firstSel);
    recalcTotals();
}

// Init on load
document.getElementById('edit-discount').addEventListener('input', recalcTotals);
recalcTotals();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
