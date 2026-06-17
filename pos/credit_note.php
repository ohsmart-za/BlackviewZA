<?php
// ============================================================
// Blackview SA Portal — Create Credit Note
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Create Credit Note';

$invoiceId = isset($_GET['invoice_id']) && is_numeric($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if ($invoiceId === 0) {
    setFlash('error', 'No invoice specified.');
    header('Location: ' . BASE_URL . '/pos/invoices.php');
    exit;
}

// Load invoice
$invStmt = $pdo->prepare(
    "SELECT inv.*, c.name AS customer_name
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     WHERE inv.id = :id LIMIT 1"
);
$invStmt->execute([':id' => $invoiceId]);
$invoice = $invStmt->fetch();

if (!$invoice) {
    setFlash('error', 'Invoice not found.');
    header('Location: ' . BASE_URL . '/pos/invoices.php');
    exit;
}

// Load invoice line items
$lineItems = $pdo->prepare(
    "SELECT ii.*, p.name AS product_name, p.sku
     FROM invoice_items ii
     JOIN products p ON p.id = ii.product_id
     WHERE ii.invoice_id = :inv ORDER BY ii.id ASC"
);
$lineItems->execute([':inv' => $invoiceId]);
$lineItems = $lineItems->fetchAll();

// Load existing credit notes for this invoice (to check if already credited)
$existingCNs = $pdo->prepare(
    "SELECT credit_note_no, total, status FROM credit_notes WHERE invoice_id = :id AND status != 'voided'"
);
$existingCNs->execute([':id' => $invoiceId]);
$existingCNs = $existingCNs->fetchAll();

// Load warehouses for stock return
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();

$errors  = [];
$success = false;

// ============================================================
// POST: Create credit note
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason      = trim($_POST['reason']      ?? '');
    $applyNow    = isset($_POST['apply_now']) && $_POST['apply_now'] == '1';
    $vatRate     = 15.0;

    // Stock return fields
    $returnToStock = isset($_POST['return_to_stock']) && $_POST['return_to_stock'] == '1';
    $returnWhId    = ($returnToStock && is_numeric($_POST['return_warehouse_id'] ?? ''))
                        ? (int)$_POST['return_warehouse_id'] : null;
    $returnCond    = in_array($_POST['return_condition'] ?? '', ['resellable', 'damaged'])
                        ? $_POST['return_condition'] : 'resellable';

    // Collect line items from POST
    $cnLines    = [];
    $descs      = $_POST['line_desc']       ?? [];
    $qtys       = $_POST['line_qty']        ?? [];
    $prices     = $_POST['line_price']      ?? [];
    $productIds = $_POST['line_product_id'] ?? [];
    $serialNos  = $_POST['line_serial_no']  ?? [];

    foreach ($descs as $i => $desc) {
        $desc      = trim($desc);
        $qty       = max(1, (int)($qtys[$i]   ?? 1));
        $price     = max(0, (float)($prices[$i] ?? 0));
        $productId = is_numeric($productIds[$i] ?? '') && (int)$productIds[$i] > 0
                        ? (int)$productIds[$i] : null;
        $serialNo  = trim($serialNos[$i] ?? '');
        if ($desc !== '' && $price > 0) {
            $lineSubtotal = round($price * $qty, 2);
            $lineTotal    = round($price * $qty * (1 + $vatRate / 100), 2); // incl., authoritative
            $lineVat      = round($lineTotal - $lineSubtotal, 2);            // vat = incl - excl (no gap)
            $cnLines[] = [
                'description' => $desc,
                'qty'         => $qty,
                'unit_price'  => $price,
                'vat_rate'    => $vatRate,
                'vat_amount'  => $lineVat,
                'line_total'  => $lineTotal,
                'product_id'  => $productId,
                'serial_no'   => $serialNo,
            ];
        }
    }

    if ($reason === '')    $errors[] = 'Please provide a reason for the credit note.';
    if (empty($cnLines))   $errors[] = 'Please add at least one credit line item.';
    if ($returnToStock && !$returnWhId) $errors[] = 'Please select a warehouse to return stock to.';

    if (empty($errors)) {
        $subtotal   = 0;
        $grandTotal = 0;
        foreach ($cnLines as $ln) {
            $subtotal   += round($ln['unit_price'] * $ln['qty'], 2);
            $grandTotal += $ln['line_total'];
        }
        $subtotal   = round($subtotal,  2);
        $grandTotal = round($grandTotal, 2);
        $vatTotal   = round($grandTotal - $subtotal, 2); // vat = total - excl (no gap)

        try {
            $pdo->beginTransaction();

            // Generate CN number: CN-YYYYMM-####
            $monthPrefix = date('Ym');
            $cnCountQ    = $pdo->prepare("SELECT COUNT(*) FROM credit_notes WHERE credit_note_no LIKE :p");
            $cnCountQ->execute([':p' => "CN-{$monthPrefix}-%"]);
            $cnSeq       = (int)$cnCountQ->fetchColumn() + 1;
            $cnNo        = sprintf('CN-%s-%04d', $monthPrefix, $cnSeq);

            // Insert credit note header
            $insCN = $pdo->prepare(
                "INSERT INTO credit_notes (credit_note_no, invoice_id, reason, subtotal, vat_amount, total, status,
                                           return_to_stock, return_warehouse_id, return_condition, created_by, created_at)
                 VALUES (:no, :inv, :reason, :sub, :vat, :tot, 'open', :rts, :rwh, :rcond, :uid, NOW())"
            );
            $insCN->execute([
                ':no'     => $cnNo,
                ':inv'    => $invoiceId,
                ':reason' => $reason,
                ':sub'    => $subtotal,
                ':vat'    => $vatTotal,
                ':tot'    => $grandTotal,
                ':rts'    => $returnToStock ? 1 : 0,
                ':rwh'    => $returnWhId,
                ':rcond'  => $returnCond,
                ':uid'    => $_SESSION['user_id'],
            ]);
            $cnId = (int)$pdo->lastInsertId();

            // Insert line items (with product_id + serial_no for stock return tracking)
            $insLine = $pdo->prepare(
                "INSERT INTO credit_note_items (credit_note_id, product_id, serial_no, description, qty, unit_price, vat_rate, vat_amount, line_total)
                 VALUES (:cn, :pid, :sn, :desc, :qty, :price, :vr, :va, :lt)"
            );
            foreach ($cnLines as $ln) {
                $insLine->execute([
                    ':cn'    => $cnId,
                    ':pid'   => $ln['product_id'],
                    ':sn'    => $ln['serial_no'] ?: null,
                    ':desc'  => $ln['description'],
                    ':qty'   => $ln['qty'],
                    ':price' => $ln['unit_price'],
                    ':vr'    => $ln['vat_rate'],
                    ':va'    => $ln['vat_amount'],
                    ':lt'    => $ln['line_total'],
                ]);
            }

            // ---- Stock return processing ----
            if ($returnToStock && $returnWhId) {
                $upsertStock = $pdo->prepare(
                    "INSERT INTO inventory_stock (product_id, warehouse_id, qty)
                     VALUES (:pid, :wid, :qty)
                     ON DUPLICATE KEY UPDATE qty = qty + :qty2"
                );
                $restoreSerial = $pdo->prepare(
                    "UPDATE stock_items SET status = 'in_stock', warehouse_id = :wid
                     WHERE serial_no = :sn AND status = 'sold'"
                );
                $insMov = $pdo->prepare(
                    "INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                     VALUES (:prod, NULL, :twh, :qty, :uid, :ref, 'other', :notes, NOW())"
                );
                $insMovSerial = $pdo->prepare(
                    "INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid, :sn)"
                );

                foreach ($cnLines as $ln) {
                    if (empty($ln['product_id'])) continue; // skip free-text lines

                    if ($returnCond === 'resellable') {
                        // Add back to inventory quantity
                        $upsertStock->execute([
                            ':pid'  => $ln['product_id'],
                            ':wid'  => $returnWhId,
                            ':qty'  => $ln['qty'],
                            ':qty2' => $ln['qty'],
                        ]);
                        // Restore serialised unit to in_stock
                        if (!empty($ln['serial_no'])) {
                            $restoreSerial->execute([
                                ':wid' => $returnWhId,
                                ':sn'  => $ln['serial_no'],
                            ]);
                        }
                    }
                    // Always create a movement record (audit trail, both conditions)
                    $condLabel = $returnCond === 'damaged' ? 'DAMAGED — not returned to stock' : 'Resellable return';
                    $insMov->execute([
                        ':prod'  => $ln['product_id'],
                        ':twh'   => $returnWhId,
                        ':qty'   => $ln['qty'],
                        ':uid'   => $_SESSION['user_id'],
                        ':ref'   => $cnNo,
                        ':notes' => "Credit note return ({$condLabel}) — CN: {$cnNo} / Invoice: {$invoice['invoice_no']}",
                    ]);
                    $movId = (int)$pdo->lastInsertId();
                    if (!empty($ln['serial_no'])) {
                        $insMovSerial->execute([':mid' => $movId, ':sn' => $ln['serial_no']]);
                    }
                }
            }

            // Apply immediately to invoice if requested
            if ($applyNow) {
                $pdo->prepare(
                    "INSERT INTO invoice_payments (invoice_id, amount, payment_method, reference, credit_note_id, notes, created_by, created_at)
                     VALUES (:inv, :amt, 'credit_note', :ref, :cn, :notes, :uid, NOW())"
                )->execute([
                    ':inv'   => $invoiceId,
                    ':amt'   => $grandTotal,
                    ':ref'   => $cnNo,
                    ':cn'    => $cnId,
                    ':notes' => 'Credit note applied: ' . $reason,
                    ':uid'   => $_SESSION['user_id'],
                ]);

                $pdo->prepare("UPDATE credit_notes SET status = 'applied' WHERE id = :id")
                    ->execute([':id' => $cnId]);
            }

            logAudit($pdo, 'create_credit_note', 'credit_notes', $cnId,
                "Created credit note $cnNo for invoice {$invoice['invoice_no']}. Total: R $grandTotal. Applied: " . ($applyNow ? 'Yes' : 'No'));

            $pdo->commit();

            setFlash('success', "Credit note $cnNo created successfully." . ($applyNow ? " Applied to invoice — balance updated." : " It is open and can be applied from the invoice."));
            header('Location: ' . BASE_URL . '/pos/credit_note_view.php?id=' . $cnId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-title">Create Credit Note</h2>
        <p class="page-subtitle">Against invoice <?= htmlspecialchars($invoice['invoice_no']) ?> — <?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer') ?></p>
    </div>
    <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $invoiceId ?>" class="btn btn-outline">← Back to Invoice</a>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if (!empty($existingCNs)): ?>
<div class="alert alert-warning">
    <strong>Existing credit notes on this invoice:</strong>
    <?php foreach ($existingCNs as $ecn): ?>
        <span class="badge"><?= htmlspecialchars($ecn['credit_note_no']) ?> — R <?= number_format((float)$ecn['total'], 2) ?> (<?= $ecn['status'] ?>)</span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="two-col-layout">
<div class="col-main">

<div class="card">
    <div class="card-header"><h3 class="card-title">Invoice Reference</h3></div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th><th>Serial</th><th style="text-align:center">Qty</th>
                    <th style="text-align:right">Unit Price</th><th style="text-align:right">Line Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lineItems as $li): ?>
                <tr>
                    <td><?= htmlspecialchars($li['product_name']) ?> <small style="color:var(--color-muted)"><?= htmlspecialchars($li['sku']) ?></small></td>
                    <td><code><?= !empty($li['serial_no']) ? htmlspecialchars($li['serial_no']) : '—' ?></code></td>
                    <td style="text-align:center"><?= (int)$li['qty'] ?></td>
                    <td style="text-align:right">R <?= number_format((float)$li['unit_price'], 2) ?></td>
                    <td style="text-align:right">R <?= number_format((float)$li['line_total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right"><strong>Invoice Total</strong></td>
                    <td style="text-align:right"><strong>R <?= number_format((float)$invoice['total'], 2) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

</div><!-- /.col-main -->

<div class="col-side">
<div class="card">
    <div class="card-header"><h3 class="card-title">Credit Note Details</h3></div>
    <div class="card-body">

        <form method="POST" action="" id="cnForm" novalidate>

            <div class="form-group">
                <label class="form-label">Reason <span class="required">*</span></label>
                <textarea name="reason" class="form-control" rows="3"
                    placeholder="e.g. Returned faulty unit, Billing correction, Damaged in transit..."
                    required><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Credit Line Items <span class="required">*</span></label>
                <small class="form-hint">Add one row per item being credited. Prices are excl. VAT — 15% VAT will be added.</small>
                <div id="cnLines" style="margin-top:.5rem;">
                    <!-- Pre-fill from invoice items if GET param set -->
                    <?php
                    $prefillLines = [];
                    if (!empty($_GET['prefill'])) {
                        foreach ($lineItems as $li) {
                            $prefillLines[] = [
                                'desc'       => $li['product_name'] . (!empty($li['serial_no']) ? ' (' . $li['serial_no'] . ')' : ''),
                                'qty'        => (int)$li['qty'],
                                'price'      => number_format((float)$li['unit_price'], 2, '.', ''),
                                'product_id' => (int)$li['product_id'],
                                'serial_no'  => $li['serial_no'] ?? '',
                            ];
                        }
                    } else {
                        $prefillLines = [['desc' => '', 'qty' => 1, 'price' => '0.00', 'product_id' => 0, 'serial_no' => '']];
                    }

                    foreach ($prefillLines as $pf):
                    ?>
                    <div class="cn-line-row" style="display:grid;grid-template-columns:1fr 60px 110px 36px;gap:6px;margin-bottom:6px;">
                        <input type="hidden" name="line_product_id[]" value="<?= (int)($pf['product_id'] ?? 0) ?>">
                        <input type="hidden" name="line_serial_no[]"  value="<?= htmlspecialchars($pf['serial_no'] ?? '') ?>">
                        <input type="text" name="line_desc[]" class="form-control" placeholder="Description" value="<?= htmlspecialchars($pf['desc']) ?>" required>
                        <input type="number" name="line_qty[]" class="form-control" placeholder="Qty" min="1" value="<?= $pf['qty'] ?>" required>
                        <input type="number" name="line_price[]" class="form-control cn-price" placeholder="0.00" step="0.01" min="0" value="<?= $pf['price'] ?>">
                        <button type="button" class="btn btn-sm btn-danger cn-remove-line" title="Remove">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="addCnLine" class="btn btn-sm btn-outline" style="margin-top:.35rem;">+ Add Line</button>
            </div>

            <div class="form-group" style="background:#F8FAFC;padding:12px;border-radius:8px;font-size:.9rem;">
                <div style="display:flex;justify-content:space-between;padding:4px 0;">
                    <span>Subtotal (excl. VAT)</span><span id="cnSubtotal">R 0.00</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;">
                    <span>VAT (15%)</span><span id="cnVat">R 0.00</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-weight:700;border-top:1px solid var(--color-border);margin-top:4px;padding-top:8px;">
                    <span>Credit Note Total</span><span id="cnTotal">R 0.00</span>
                </div>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500;">
                    <input type="checkbox" name="apply_now" value="1" checked>
                    Apply immediately to invoice (deduct from outstanding balance)
                </label>
                <small class="form-hint">Uncheck to keep the credit note open for manual allocation later.</small>
            </div>

            <!-- ---- Stock Return ---- -->
            <div style="border:1.5px solid #E2E8F0;border-radius:10px;padding:1rem;margin-bottom:1rem;background:#F8FAFC;">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:600;margin-bottom:.5rem;">
                    <input type="checkbox" name="return_to_stock" value="1" id="returnStockChk"
                           <?= !empty($_POST['return_to_stock']) ? 'checked' : '' ?>>
                    Return items to stock
                </label>
                <small class="form-hint" style="margin-bottom:.75rem;display:block;">
                    Only applies to line items linked to products (pre-filled from invoice). Manual description-only lines are ignored.
                </small>

                <div id="returnStockDetails" style="display:<?= !empty($_POST['return_to_stock']) ? 'block' : 'none' ?>;">
                    <div class="form-group" style="margin-bottom:.75rem;">
                        <label class="form-label">Return to Warehouse <span class="required">*</span></label>
                        <select name="return_warehouse_id" class="form-control">
                            <option value="">— Select warehouse —</option>
                            <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>"
                                    <?= (int)($_POST['return_warehouse_id'] ?? 0) === (int)$wh['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($wh['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Item Condition</label>
                        <div style="display:flex;flex-direction:column;gap:.4rem;margin-top:.25rem;">
                            <label style="display:flex;align-items:flex-start;gap:.5rem;cursor:pointer;">
                                <input type="radio" name="return_condition" value="resellable"
                                       <?= ($_POST['return_condition'] ?? 'resellable') === 'resellable' ? 'checked' : '' ?>>
                                <span>
                                    <strong style="color:#16A34A;">✓ Resellable</strong> —
                                    Return to available stock. Serial numbers restored. Can be sold again.
                                </span>
                            </label>
                            <label style="display:flex;align-items:flex-start;gap:.5rem;cursor:pointer;">
                                <input type="radio" name="return_condition" value="damaged"
                                       <?= ($_POST['return_condition'] ?? '') === 'damaged' ? 'checked' : '' ?>>
                                <span>
                                    <strong style="color:#DC2626;">✗ Damaged / Write-off</strong> —
                                    Do NOT return to sellable stock. Movement recorded for audit purposes only.
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Credit Note</button>
                <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $invoiceId ?>" class="btn btn-outline">Cancel</a>
            </div>

            <?php if (!empty($lineItems)): ?>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--color-border);">
                <a href="?invoice_id=<?= $invoiceId ?>&prefill=1" class="btn btn-sm btn-outline">
                    Pre-fill all invoice lines
                </a>
                <small class="form-hint" style="display:block;margin-top:.35rem;">Pre-fills every line item from the original invoice.</small>
            </div>
            <?php endif; ?>

        </form>

    </div>
</div>
</div><!-- /.col-side -->
</div><!-- /.two-col-layout -->

<script>
(function () {
    var vatRate = 0.15;

    function recalc() {
        var sub = 0;
        document.querySelectorAll('.cn-line-row').forEach(function (row) {
            var qty   = parseFloat(row.querySelector('input[name="line_qty[]"]').value)   || 0;
            var price = parseFloat(row.querySelector('input[name="line_price[]"]').value) || 0;
            sub += qty * price;
        });
        var vat   = Math.round(sub * vatRate * 100) / 100;
        var total = Math.round((sub + vat) * 100) / 100;
        document.getElementById('cnSubtotal').textContent = 'R ' + sub.toFixed(2);
        document.getElementById('cnVat').textContent      = 'R ' + vat.toFixed(2);
        document.getElementById('cnTotal').textContent    = 'R ' + total.toFixed(2);
    }

    function bindRow(row) {
        row.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('input', recalc);
        });
        row.querySelector('.cn-remove-line').addEventListener('click', function () {
            row.remove();
            recalc();
        });
    }

    document.querySelectorAll('.cn-line-row').forEach(bindRow);

    document.getElementById('addCnLine').addEventListener('click', function () {
        var container = document.getElementById('cnLines');
        var row = document.createElement('div');
        row.className = 'cn-line-row';
        row.style.cssText = 'display:grid;grid-template-columns:1fr 60px 110px 36px;gap:6px;margin-bottom:6px;';
        row.innerHTML = '<input type="hidden" name="line_product_id[]" value="0">'
            + '<input type="hidden" name="line_serial_no[]" value="">'
            + '<input type="text" name="line_desc[]" class="form-control" placeholder="Description" required>'
            + '<input type="number" name="line_qty[]" class="form-control" placeholder="Qty" min="1" value="1" required>'
            + '<input type="number" name="line_price[]" class="form-control cn-price" placeholder="0.00" step="0.01" min="0" value="0.00">'
            + '<button type="button" class="btn btn-sm btn-danger cn-remove-line" title="Remove">&times;</button>';
        container.appendChild(row);
        bindRow(row);
        row.querySelector('input[type="text"]').focus();
    });

    // Stock return toggle
    var returnChk     = document.getElementById('returnStockChk');
    var returnDetails = document.getElementById('returnStockDetails');
    if (returnChk) {
        returnChk.addEventListener('change', function () {
            returnDetails.style.display = this.checked ? 'block' : 'none';
        });
    }

    recalc();
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
