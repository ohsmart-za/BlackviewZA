<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Point of Sale';
$activeNav = 'pos';
$showBack  = true;
$backUrl   = 'mobile/index.php';

// ---- AJAX: validate serial ----
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_serial') {
    header('Content-Type: application/json');
    $sn = trim($_GET['serial_no'] ?? '');
    if ($sn === '') { echo json_encode(['found' => false]); exit; }
    $stmt = $pdo->prepare(
        "SELECT si.serial_no, si.warehouse_id, w.name AS warehouse_name,
                p.id AS product_id, p.name AS product_name, p.sku, p.selling_price
         FROM stock_items si
         JOIN products p ON p.id = si.product_id
         JOIN warehouses w ON w.id = si.warehouse_id
         WHERE si.serial_no = :sn AND si.status = 'in_stock' LIMIT 1"
    );
    $stmt->execute([':sn' => $sn]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        echo json_encode(['found' => true, 'product_id' => $item['product_id'],
            'product_name' => $item['product_name'], 'sku' => $item['sku'],
            'warehouse_id' => $item['warehouse_id'], 'warehouse_name' => $item['warehouse_name'],
            'selling_price' => number_format((float)$item['selling_price'], 2, '.', ''),
            'serial_no' => $item['serial_no']]);
    } else {
        echo json_encode(['found' => false, 'message' => 'Serial not found or not in stock.']);
    }
    exit;
}

// ---- POST: create invoice ----
$posErrors = [];
$validPayments = ['cash', 'eft', 'card'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_invoice') {
    $custName      = trim($_POST['customer_name']    ?? '');
    $custPhone     = trim($_POST['customer_phone']   ?? '');
    $custEmail     = trim($_POST['customer_email']   ?? '');
    $custAddress   = trim($_POST['customer_address'] ?? '');
    $custIdNum     = trim($_POST['customer_id_number'] ?? '');
    $paymentMethod = trim($_POST['payment_method']   ?? 'cash');
    $channel       = 'instore';
    $notes         = trim($_POST['notes']            ?? '');
    $discountPct   = min(10.0, max(0.0, (float)($_POST['discount_pct'] ?? 0)));

    if (!in_array($paymentMethod, $validPayments, true)) $paymentMethod = 'cash';

    if ($custName === '') $posErrors[] = 'Customer name is required.';

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemSerials    = $_POST['item_serial_no']   ?? [];
    $itemPrices     = $_POST['item_unit_price']  ?? [];

    $saleItems = [];
    foreach ($itemProductIds as $i => $pid) {
        $pid   = (int)$pid;
        $sn    = trim($itemSerials[$i] ?? '');
        $price = (float)($itemPrices[$i] ?? 0);
        if ($pid > 0) {
            $saleItems[] = ['product_id' => $pid, 'serial_no' => $sn, 'unit_price' => $price, 'qty' => 1, 'warehouse_id' => null];
        }
    }
    if (empty($saleItems)) $posErrors[] = 'Please add at least one item.';

    if (empty($posErrors)) {
        foreach ($saleItems as &$si) {
            if ($si['serial_no'] !== '') {
                $chk = $pdo->prepare("SELECT warehouse_id FROM stock_items WHERE serial_no = :sn AND status = 'in_stock' LIMIT 1");
                $chk->execute([':sn' => $si['serial_no']]);
                $row = $chk->fetch();
                if (!$row) { $posErrors[] = "Serial \"{$si['serial_no']}\" is no longer in stock."; }
                else        { $si['warehouse_id'] = $row['warehouse_id']; }
            }
        }
        unset($si);
    }

    if (empty($posErrors)) {
        try {
            $pdo->beginTransaction();

            // Customer
            $customerId = null;
            if ($custEmail !== '') {
                $cstChk = $pdo->prepare('SELECT id FROM customers WHERE email = :e LIMIT 1');
                $cstChk->execute([':e' => $custEmail]);
                $existCust = $cstChk->fetch();
                if ($existCust) {
                    $customerId = (int)$existCust['id'];
                    $pdo->prepare('UPDATE customers SET name=:n, phone=:p, address=:a, id_number=:i WHERE id=:id')
                        ->execute([':n'=>$custName,':p'=>$custPhone,':a'=>$custAddress,':i'=>$custIdNum,':id'=>$customerId]);
                }
            }
            if ($customerId === null) {
                $pdo->prepare('INSERT INTO customers (name, email, phone, address, id_number, created_at) VALUES (:n,:e,:p,:a,:i,NOW())')
                    ->execute([':n'=>$custName,':e'=>$custEmail,':p'=>$custPhone,':a'=>$custAddress,':i'=>$custIdNum]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Invoice number
            $monthPrefix = date('Ym');
            $invCountQ   = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE :p");
            $invCountQ->execute([':p' => "INV-{$monthPrefix}-%"]);
            $invSeq    = (int)$invCountQ->fetchColumn() + 1;
            $invoiceNo = sprintf('INV-%s-%04d', $monthPrefix, $invSeq);

            // Totals
            $subtotal = 0;
            $vatRate  = 15.0;
            foreach ($saleItems as $si) { $subtotal += $si['unit_price'] * $si['qty']; }
            $discountAmount = round($subtotal * $discountPct / 100, 2);
            $discountedSub  = $subtotal - $discountAmount;
            $vatTotal       = round($discountedSub * ($vatRate / 100), 2);
            $grandTotal     = round($discountedSub + $vatTotal, 2);

            $pdo->prepare(
                "INSERT INTO invoices (invoice_no, customer_id, channel, payment_method, discount_pct, discount_amount, subtotal, vat_amount, total, notes, created_by, created_at)
                 VALUES (:no,:cust,:ch,:pm,:dpct,:damt,:sub,:vat,:tot,:notes,:uid,NOW())"
            )->execute([':no'=>$invoiceNo,':cust'=>$customerId,':ch'=>$channel,':pm'=>$paymentMethod,
                ':dpct'=>$discountPct,':damt'=>$discountAmount,':sub'=>$discountedSub,':vat'=>$vatTotal,
                ':tot'=>$grandTotal,':notes'=>$notes,':uid'=>$_SESSION['user_id']??null]);
            $invoiceId = (int)$pdo->lastInsertId();

            $insItem = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id, product_id, serial_no, warehouse_id, qty, unit_price, vat_rate, vat_amount, line_total)
                 VALUES (:inv,:prod,:sn,:wh,:qty,:price,:vr,:va,:lt)"
            );
            $insMov = $pdo->prepare(
                "INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                 VALUES (:prod,:fwh,NULL,1,:uid,:inv_no,:ch,:notes,NOW())"
            );
            $insMovSerial = $pdo->prepare("INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid,:sn)");
            $updStock     = $pdo->prepare("UPDATE stock_items SET status='sold' WHERE serial_no=:sn");
            $decInv       = $pdo->prepare("UPDATE inventory_stock SET qty=GREATEST(0,qty-1) WHERE product_id=:prod AND warehouse_id=:wh");

            foreach ($saleItems as $si) {
                $lineSubtotal = $si['unit_price'] * $si['qty'];
                $vatAmt       = round($lineSubtotal * ($vatRate / 100), 2);
                $lineTotal    = round($lineSubtotal * (1 + $vatRate / 100), 2);

                $insItem->execute([':inv'=>$invoiceId,':prod'=>$si['product_id'],
                    ':sn'=>$si['serial_no']?:null,':wh'=>$si['warehouse_id'],
                    ':qty'=>$si['qty'],':price'=>$si['unit_price'],':vr'=>$vatRate,':va'=>$vatAmt,':lt'=>$lineTotal]);

                if ($si['serial_no'] !== '') {
                    $insMov->execute([':prod'=>$si['product_id'],':fwh'=>$si['warehouse_id'],
                        ':uid'=>$_SESSION['user_id']??null,':inv_no'=>$invoiceNo,':ch'=>$channel,
                        ':notes'=>"POS Sale — $invoiceNo"]);
                    $movId = (int)$pdo->lastInsertId();
                    $insMovSerial->execute([':mid'=>$movId,':sn'=>$si['serial_no']]);
                    $updStock->execute([':sn'=>$si['serial_no']]);
                    $decInv->execute([':prod'=>$si['product_id'],':wh'=>$si['warehouse_id']]);
                }
            }

            logAudit($pdo, 'pos_sale', 'invoices', $invoiceId,
                "Mobile POS — Invoice $invoiceNo, customer: $custName, items: " . count($saleItems) . ", total: R " . number_format($grandTotal, 2));

            $pdo->commit();

            header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $posErrors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/_shell.php';
?>

<div class="form-section">

    <?php foreach ($posErrors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" action="" id="posForm">
        <input type="hidden" name="action" value="create_invoice">
        <input type="hidden" name="payment_method" id="paymentMethodInput" value="cash">

        <!-- Scan bar -->
        <div class="section-title">Add Items</div>
        <div class="scan-bar">
            <input type="text" id="posSerialInput" class="scan-input"
                placeholder="Scan serial number…" autocomplete="off" autocorrect="off" spellcheck="false">
            <button type="button" id="posScanBtn" class="scan-btn">Add</button>
        </div>

        <!-- Cart -->
        <div id="posEmpty" style="text-align:center;color:var(--text-muted);padding:20px 0;font-size:14px;">
            No items yet. Scan a serial number above.
        </div>
        <div id="posCart"></div>

        <!-- Totals -->
        <div class="pos-totals">
            <div class="totals-row">
                <span>Subtotal</span>
                <span id="posSubtotal">R 0.00</span>
            </div>
            <div class="totals-row">
                <span>
                    Discount
                    (<input type="number" name="discount_pct" id="posDiscountPct"
                        value="0" min="0" max="10" step="0.5"
                        style="width:40px;border:none;border-bottom:1px solid var(--border);background:none;font-size:14px;text-align:center;outline:none;">%)
                </span>
                <span id="posDiscountAmt" style="color:var(--success);">- R 0.00</span>
            </div>
            <div class="totals-row total-final">
                <span>Total (incl. VAT)</span>
                <span id="posTotal">R 0.00</span>
            </div>
        </div>

        <!-- Customer -->
        <div class="section-title">Customer</div>
        <div class="card" style="margin-bottom:14px;">
            <div class="card-body" style="padding-top:12px;">
                <div class="field" style="margin-bottom:12px;">
                    <label>Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="customer_name" id="mob-cust-name" class="field-input"
                        placeholder="Customer name" required
                        value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>">
                </div>
                <div class="field" style="margin-bottom:12px;">
                    <label>Phone</label>
                    <input type="tel" name="customer_phone" class="field-input"
                        placeholder="e.g. 082 000 0000"
                        value="<?= htmlspecialchars($_POST['customer_phone'] ?? '') ?>">
                </div>
                <div class="field" style="margin-bottom:12px;">
                    <label>Email</label>
                    <input type="email" name="customer_email" class="field-input"
                        placeholder="Optional — for receipt"
                        value="<?= htmlspecialchars($_POST['customer_email'] ?? '') ?>">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label>Account No</label>
                    <input type="text" name="customer_id_number" id="mob-cust-accno" class="field-input"
                        placeholder="Auto-generated"
                        value="<?= htmlspecialchars($_POST['customer_id_number'] ?? '') ?>"
                        style="font-family:monospace;">
                </div>
            </div>
        </div>

        <!-- Payment method -->
        <div class="section-title">Payment Method</div>
        <div class="payment-pills" style="margin-bottom:14px;">
            <button type="button" class="pay-pill active" data-method="cash">Cash</button>
            <button type="button" class="pay-pill" data-method="eft">EFT</button>
            <button type="button" class="pay-pill" data-method="card">Card</button>
        </div>

        <div class="field">
            <label>Notes</label>
            <textarea name="notes" class="field-textarea" rows="2"
                placeholder="Optional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" id="posSubmitBtn" class="btn-primary" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Create Invoice
            </button>
            <a href="<?= BASE_URL ?>/mobile/index.php" class="btn-secondary">Cancel</a>
        </div>

    </form>
</div>

<script>
(function () {
    var nameIn = document.getElementById('mob-cust-name');
    var accIn  = document.getElementById('mob-cust-accno');
    if (!nameIn || !accIn) return;
    var _t = null;
    function genAcc(name) {
        var cur = accIn.value.trim();
        if (cur !== '' && !/^[A-Z]{2,4}-\d{6}-\d{3}$/.test(cur)) return;
        fetch('<?= BASE_URL ?>/pos/index.php?ajax=gen_account_no&name=' + encodeURIComponent(name))
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.account_no) accIn.value = d.account_no; })
            .catch(function () {});
    }
    nameIn.addEventListener('input', function () {
        clearTimeout(_t);
        var n = this.value.trim();
        if (n.length >= 2) _t = setTimeout(function () { genAcc(n); }, 450);
    });
}());
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
