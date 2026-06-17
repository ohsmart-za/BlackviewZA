<?php
// ============================================================
// Blackview SA Portal — Purchasing: View / Receive Purchase Order
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo = getDB();

$poId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($poId === 0) {
    setFlash('error', 'Invalid purchase order ID.');
    header('Location: ' . BASE_URL . '/purchasing/orders.php');
    exit;
}

// Load PO
$poStmt = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name, w.id AS wh_id,
            u.name AS created_by_name
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN warehouses w ON w.id = po.warehouse_id
     LEFT JOIN users u ON u.id = po.created_by
     WHERE po.id = :id LIMIT 1"
);
$poStmt->execute([':id' => $poId]);
$po = $poStmt->fetch();

if (!$po) {
    setFlash('error', 'Purchase Order not found.');
    header('Location: ' . BASE_URL . '/purchasing/orders.php');
    exit;
}

$pageTitle = 'Purchase Order — ' . $po['po_number'];

// Load line items
$lineItems = $pdo->prepare(
    "SELECT poi.*, p.name AS product_name, p.sku AS product_sku
     FROM purchase_order_items poi
     JOIN products p ON p.id = poi.product_id
     WHERE poi.po_id = :po"
);
$lineItems->execute([':po' => $poId]);
$lineItems = $lineItems->fetchAll();

$statusLabels = [
    'draft'     => 'Draft',
    'ordered'   => 'Ordered',
    'partial'   => 'Partial',
    'received'  => 'Received',
    'cancelled' => 'Cancelled',
];

$errors  = [];
$summary = [];

// ============================================================
// POST: Receive Stock
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_stock') {

    if (in_array($po['status'], ['cancelled', 'received'], true)) {
        $errors[] = 'This PO cannot receive stock in its current status.';
    } else {
        try {
            $pdo->beginTransaction();

            $totalReceived = 0;

            // Insert a stock_movement to represent this receive batch
            $movUserId = $_SESSION['user_id'] ?? null;
            $movStmt = $pdo->prepare(
                "INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                 VALUES (:prod, NULL, :wh, :qty, :uid, :po_num, 'received', :notes, NOW())"
            );

            $siStmt = $pdo->prepare(
                "INSERT INTO stock_items (product_id, warehouse_id, po_id, serial_no, status, created_at)
                 VALUES (:prod, :wh, :po, :sn, 'in_stock', NOW())"
            );
            $msStmt = $pdo->prepare(
                "INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid, :sn)"
            );
            $upsert = $pdo->prepare(
                "INSERT INTO inventory_stock (product_id, warehouse_id, qty)
                 VALUES (:prod, :wh, :qty)
                 ON DUPLICATE KEY UPDATE qty = qty + :qty2"
            );

            foreach ($lineItems as $li) {
                $outstanding  = max(0, (int)$li['qty_ordered'] - (int)$li['qty_received']);
                $textareaKey  = 'serials_' . $li['id'];
                $qtyKey       = 'qty_receive_' . $li['id'];
                $rawInput     = trim($_POST[$textareaKey] ?? '');
                $manualQty    = max(0, (int)($_POST[$qtyKey] ?? 0));

                // Parse serials if entered
                $serials = [];
                if ($rawInput !== '') {
                    $seen = [];
                    foreach (explode("\n", $rawInput) as $line) {
                        $sn = trim($line);
                        if ($sn === '') continue;
                        $lower = strtolower($sn);
                        if (isset($seen[$lower])) continue;
                        $seen[$lower] = true;
                        $serials[] = $sn;
                    }
                }

                // Determine how many to receive
                $useSerials   = !empty($serials);
                $receiveQty   = $useSerials ? count($serials) : $manualQty;

                if ($receiveQty <= 0) continue;
                if ($receiveQty > $outstanding) {
                    $errors[] = "Cannot receive {$receiveQty} units of \"{$li['product_name']}\" — only {$outstanding} outstanding.";
                    continue;
                }

                // Check for duplicate serials in DB
                $newSerials = $serials;
                if ($useSerials) {
                    $inSql  = implode(',', array_fill(0, count($serials), '?'));
                    $dupChk = $pdo->prepare("SELECT serial_no FROM stock_items WHERE serial_no IN ($inSql)");
                    $dupChk->execute($serials);
                    $existingSerials = array_flip(array_column($dupChk->fetchAll(), 'serial_no'));
                    $newSerials = [];
                    foreach ($serials as $sn) {
                        if (isset($existingSerials[$sn])) {
                            $errors[] = "Serial {$sn} already exists — skipped.";
                        } else {
                            $newSerials[] = $sn;
                        }
                    }
                    if (empty($newSerials)) continue;
                    $receiveQty = count($newSerials);
                }

                // Insert stock movement
                $movStmt->execute([
                    ':prod'   => $li['product_id'],
                    ':wh'     => $po['wh_id'],
                    ':qty'    => $receiveQty,
                    ':uid'    => $movUserId,
                    ':po_num' => $po['po_number'],
                    ':notes'  => 'Received via PO #' . $po['po_number'],
                ]);
                $movId = (int)$pdo->lastInsertId();

                if ($useSerials) {
                    // Serialised: create one stock_item per serial
                    foreach ($newSerials as $sn) {
                        $siStmt->execute([':prod' => $li['product_id'], ':wh' => $po['wh_id'], ':po' => $poId, ':sn' => $sn]);
                        $msStmt->execute([':mid' => $movId, ':sn' => $sn]);
                    }
                }
                // Non-serialised: just update inventory_stock (no individual stock_items)

                $upsert->execute([':prod' => $li['product_id'], ':wh' => $po['wh_id'], ':qty' => $receiveQty, ':qty2' => $receiveQty]);

                $pdo->prepare("UPDATE purchase_order_items SET qty_received = qty_received + :n WHERE id = :id")
                    ->execute([':n' => $receiveQty, ':id' => $li['id']]);

                $totalReceived += $receiveQty;
                $serialNote = $useSerials ? ' (serialised)' : ' (no serials)';
                $summary[]  = $receiveQty . ' × ' . htmlspecialchars($li['product_name']) . $serialNote . ' received.';
            }

            if ($totalReceived > 0 && empty($errors)) {
                // Check if all items are fully received
                $chkStmt = $pdo->prepare(
                    "SELECT SUM(qty_ordered) AS total_ordered, SUM(qty_received) AS total_received
                     FROM purchase_order_items WHERE po_id = :po"
                );
                $chkStmt->execute([':po' => $poId]);
                $totals      = $chkStmt->fetch();
                $newPoStatus = ((int)$totals['total_received'] >= (int)$totals['total_ordered'])
                               ? 'received' : 'partial';

                $pdo->prepare("UPDATE purchase_orders SET status = :s WHERE id = :id")
                    ->execute([':s' => $newPoStatus, ':id' => $poId]);

                logAudit($pdo, 'receive_stock', 'purchase_orders', $poId,
                    "Received $totalReceived unit(s) against PO #{$po['po_number']}. Status → $newPoStatus");
            }

            $pdo->commit();

            if ($totalReceived > 0) {
                setFlash('success', "Stock received: $totalReceived unit(s) added to warehouse.");
            } elseif (empty($errors)) {
                setFlash('warning', 'No serials were entered or all were duplicates.');
            }

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // Reload PO and line items after update
    $poStmt->execute([':id' => $poId]);
    $po = $poStmt->fetch();

    $liStmt2 = $pdo->prepare(
        "SELECT poi.*, p.name AS product_name, p.sku AS product_sku
         FROM purchase_order_items poi
         JOIN products p ON p.id = poi.product_id
         WHERE poi.po_id = :po"
    );
    $liStmt2->execute([':po' => $poId]);
    $lineItems = $liStmt2->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Purchase Order — <?= htmlspecialchars($po['po_number']) ?></h2>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/purchasing/orders.php" class="btn btn-outline">← Back to Orders</a>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if (!empty($summary)): ?>
<div class="alert alert-success">
    <strong>Stock Received:</strong>
    <ul style="margin:.35rem 0 0 1rem;">
        <?php foreach ($summary as $line): ?>
        <li><?= $line ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- PO Details Card -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 class="card-title">Order Details</h3>
        <span class="badge badge-<?= htmlspecialchars($po['status']) ?>" style="margin-left:.75rem;">
            <?= htmlspecialchars($statusLabels[$po['status']] ?? ucfirst($po['status'])) ?>
        </span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;">
            <div>
                <div class="invoice-section-label">PO Number</div>
                <div class="invoice-section-value"><strong><?= htmlspecialchars($po['po_number']) ?></strong></div>
            </div>
            <div>
                <div class="invoice-section-label">Supplier</div>
                <div class="invoice-section-value"><?= htmlspecialchars($po['supplier_name'] ?? '—') ?></div>
            </div>
            <div>
                <div class="invoice-section-label">Warehouse</div>
                <div class="invoice-section-value"><?= htmlspecialchars($po['warehouse_name']) ?></div>
            </div>
            <div>
                <div class="invoice-section-label">Order Date</div>
                <div class="invoice-section-value"><?= $po['order_date'] ? date('d M Y', strtotime($po['order_date'])) : '—' ?></div>
            </div>
            <div>
                <div class="invoice-section-label">Expected Date</div>
                <div class="invoice-section-value"><?= $po['expected_date'] ? date('d M Y', strtotime($po['expected_date'])) : '—' ?></div>
            </div>
            <div>
                <div class="invoice-section-label">Created By</div>
                <div class="invoice-section-value"><?= htmlspecialchars($po['created_by_name'] ?? '—') ?></div>
            </div>
        </div>
        <?php if ($po['notes']): ?>
        <div style="margin-top:1rem;">
            <div class="invoice-section-label">Notes</div>
            <div class="invoice-section-value"><?= nl2br(htmlspecialchars($po['notes'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Line Items Table -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h3 class="card-title">Line Items</h3></div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th class="text-right">Ordered</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Outstanding</th>
                    <th class="text-right">Unit Cost</th>
                    <th class="text-right">Line Total</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grandTotal = 0;
                foreach ($lineItems as $li):
                    $outstanding = max(0, (int)$li['qty_ordered'] - (int)$li['qty_received']);
                    $lineTotal   = (float)$li['unit_cost'] * (int)$li['qty_ordered'];
                    $grandTotal += $lineTotal;
                ?>
                <tr>
                    <td><?= htmlspecialchars($li['product_name']) ?></td>
                    <td><code><?= htmlspecialchars($li['product_sku']) ?></code></td>
                    <td class="text-right"><?= (int)$li['qty_ordered'] ?></td>
                    <td class="text-right"><?= (int)$li['qty_received'] ?></td>
                    <td class="text-right">
                        <?php if ($outstanding > 0): ?>
                        <strong style="color:#92400E;"><?= $outstanding ?></strong>
                        <?php else: ?>
                        <span style="color:#166534;">&#10003;</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">R <?= number_format((float)$li['unit_cost'], 2) ?></td>
                    <td class="text-right">R <?= number_format($lineTotal, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($lineItems)): ?>
                <tr><td colspan="7" class="text-center text-muted">No line items.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($lineItems)): ?>
            <tfoot>
                <tr>
                    <td colspan="6" class="text-right"><strong>Grand Total:</strong></td>
                    <td class="text-right"><strong>R <?= number_format($grandTotal, 2) ?></strong></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Receive Stock Section -->
<?php
$canReceive = !in_array($po['status'], ['cancelled', 'received'], true);
$receivableItems = array_filter($lineItems, fn($li) => max(0, (int)$li['qty_ordered'] - (int)$li['qty_received']) > 0);
?>

<?php if ($canReceive && !empty($receivableItems)): ?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Receive Stock</h3></div>
    <div class="card-body">
        <p style="color:var(--color-muted);font-size:.875rem;margin-bottom:1rem;">
            For <strong>serialised</strong> items: scan or paste serial numbers (one per line) — quantity is counted automatically.<br>
            For <strong>non-serialised</strong> items: leave serials blank and enter the quantity to receive.
        </p>
        <form method="POST" action="" novalidate>
            <input type="hidden" name="action" value="receive_stock">

            <?php foreach ($receivableItems as $li):
                $outstanding = max(0, (int)$li['qty_ordered'] - (int)$li['qty_received']);
            ?>
            <div class="card" style="margin-bottom:1rem;border:1px solid var(--color-border);">
                <div class="card-header" style="padding:.6rem 1rem;background:#F8FAFC;">
                    <strong><?= htmlspecialchars($li['product_name']) ?></strong>
                    <code style="margin-left:.5rem;font-size:.8rem;"><?= htmlspecialchars($li['product_sku']) ?></code>
                    <span style="margin-left:.75rem;color:#92400E;font-weight:600;"><?= $outstanding ?> outstanding</span>
                </div>
                <div class="card-body" style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:start;">
                    <div>
                        <label class="form-label">Serial Numbers <span class="form-label-note">— one per line (leave blank if non-serialised)</span></label>
                        <textarea name="serials_<?= $li['id'] ?>" class="form-control serial-textarea"
                                  rows="<?= min($outstanding, 5) ?>"
                                  placeholder="Scan or paste up to <?= $outstanding ?> serial numbers..."></textarea>
                    </div>
                    <div style="min-width:130px;">
                        <label class="form-label">Qty to Receive <span class="form-label-note">— if no serials</span></label>
                        <input type="number" name="qty_receive_<?= $li['id'] ?>" class="form-control"
                               min="0" max="<?= $outstanding ?>" value="0"
                               placeholder="0">
                        <small class="form-hint">Max: <?= $outstanding ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('Confirm receiving stock? Serial numbers will be added to inventory.')">
                    Receive Stock
                </button>
            </div>
        </form>
    </div>
</div>
<?php elseif ($po['status'] === 'received'): ?>
<div class="alert alert-success">All items on this Purchase Order have been fully received.</div>
<?php elseif ($po['status'] === 'cancelled'): ?>
<div class="alert alert-error">This Purchase Order has been cancelled — no further stock can be received.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
