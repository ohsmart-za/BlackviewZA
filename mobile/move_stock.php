<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Move Stock';
$activeNav = 'stock';
$showBack  = true;
$backUrl   = 'mobile/stock.php';

$products   = $pdo->query('SELECT id, sku, name FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();

$validChannels = ['takealot' => 'Takealot', 'makro' => 'Makro', 'instore' => 'In-Store', 'email' => 'Email Order', 'transfer' => 'Transfer', 'received' => 'Received'];

$errors  = [];
$success = false;
$summary = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId     = (int)($_POST['product_id']       ?? 0);
    $fromWarehouse = (int)($_POST['from_warehouse_id'] ?? 0);
    $toWarehouse   = (int)($_POST['to_warehouse_id']   ?? 0);
    $channel       = trim($_POST['channel']            ?? '');
    $invoiceNo     = trim($_POST['invoice_no']         ?? '');
    $notes         = trim($_POST['notes']              ?? '');

    $rawText         = $_POST['serials_text'] ?? '';
    $selectedSerials = array_values(array_unique(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $rawText))))));

    if ($productId     <= 0) $errors[] = 'Please select a product.';
    if ($fromWarehouse <= 0) $errors[] = 'Please select the source warehouse.';
    if ($toWarehouse   <= 0) $errors[] = 'Please select the destination warehouse.';
    if ($fromWarehouse === $toWarehouse && $fromWarehouse > 0) $errors[] = 'Source and destination cannot be the same.';
    if (!array_key_exists($channel, $validChannels)) $errors[] = 'Please select a channel.';
    if (empty($selectedSerials)) $errors[] = 'Please enter at least one serial number.';

    if (empty($errors)) {
        $in  = implode(',', array_fill(0, count($selectedSerials), '?'));
        $chk = $pdo->prepare(
            "SELECT serial_no FROM stock_items
             WHERE serial_no IN ($in) AND product_id = ? AND warehouse_id = ? AND status = 'in_stock'"
        );
        $chk->execute(array_merge($selectedSerials, [$productId, $fromWarehouse]));
        $validSerials = $chk->fetchAll(PDO::FETCH_COLUMN);

        if (count($validSerials) !== count($selectedSerials)) {
            $missing = array_diff($selectedSerials, $validSerials);
            $errors[] = 'Serial(s) not found/in stock at source: ' . implode(', ', $missing);
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $qty = count($selectedSerials);

            $insMov = $pdo->prepare(
                'INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                 VALUES (:pid, :fwid, :twid, :qty, :uid, :inv, :ch, :notes, NOW())'
            );
            $insMov->execute([':pid' => $productId, ':fwid' => $fromWarehouse, ':twid' => $toWarehouse,
                ':qty' => $qty, ':uid' => $_SESSION['user_id'], ':inv' => $invoiceNo, ':ch' => $channel, ':notes' => $notes]);
            $movId = (int)$pdo->lastInsertId();

            $insMS = $pdo->prepare('INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid, :sn)');
            $updSI = $pdo->prepare("UPDATE stock_items SET warehouse_id = :wid, status = 'moved' WHERE serial_no = :sn");
            foreach ($selectedSerials as $sn) {
                $insMS->execute([':mid' => $movId, ':sn' => $sn]);
                $updSI->execute([':wid' => $toWarehouse, ':sn' => $sn]);
            }

            $pdo->prepare('INSERT INTO inventory_stock (product_id, warehouse_id, qty, updated_at) VALUES (:pid, :wid, 0, NOW()) ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty - :qty), updated_at = NOW()')
                ->execute([':pid' => $productId, ':wid' => $fromWarehouse, ':qty' => $qty]);
            $pdo->prepare('INSERT INTO inventory_stock (product_id, warehouse_id, qty, updated_at) VALUES (:pid, :wid, :qty, NOW()) ON DUPLICATE KEY UPDATE qty = qty + :qty2, updated_at = NOW()')
                ->execute([':pid' => $productId, ':wid' => $toWarehouse, ':qty' => $qty, ':qty2' => $qty]);

            $pName = ''; $fName = ''; $tName = '';
            foreach ($products   as $p) { if ($p['id'] == $productId)     $pName = $p['name']; }
            foreach ($warehouses as $w) { if ($w['id'] == $fromWarehouse) $fName = $w['name']; }
            foreach ($warehouses as $w) { if ($w['id'] == $toWarehouse)   $tName = $w['name']; }

            logAudit($pdo, 'move_stock', 'stock_movements', $movId,
                "Mobile: Moved $qty x \"$pName\" from \"$fName\" to \"$tName\" via $channel.");

            $pdo->commit();
            $summary = ['product' => $pName, 'from' => $fName, 'to' => $tName, 'qty' => $qty, 'channel' => $channel];
            $success = true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/_shell.php';
?>

<div class="form-section">

    <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Moved <?= $summary['qty'] ?> unit<?= $summary['qty'] !== 1 ? 's' : '' ?></strong>
        of <?= htmlspecialchars($summary['product']) ?>
        from <?= htmlspecialchars($summary['from']) ?> to <?= htmlspecialchars($summary['to']) ?>
        via <?= ucfirst(htmlspecialchars($summary['channel'])) ?>.
    </div>
    <?php endif; ?>

    <form method="POST" action="">

        <div class="field">
            <label>Product <span style="color:var(--danger)">*</span></label>
            <select name="product_id" class="field-select" required>
                <option value="">— Select product —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (!empty($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>>
                    [<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>From Warehouse <span style="color:var(--danger)">*</span></label>
            <select name="from_warehouse_id" class="field-select" required>
                <option value="">— Select source —</option>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= $w['id'] ?>" <?= (!empty($_POST['from_warehouse_id']) && $_POST['from_warehouse_id'] == $w['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>To Warehouse <span style="color:var(--danger)">*</span></label>
            <select name="to_warehouse_id" class="field-select" required>
                <option value="">— Select destination —</option>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= $w['id'] ?>" <?= (!empty($_POST['to_warehouse_id']) && $_POST['to_warehouse_id'] == $w['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Serial Numbers <span style="color:var(--danger)">*</span></label>
            <div class="serial-chip-area">
                <input class="chip-input" type="text" placeholder="Scan or type, then Enter…" autocomplete="off" autocorrect="off" spellcheck="false">
            </div>
            <input type="hidden" name="serials_text">
            <div class="serial-count">0 serials entered</div>
        </div>

        <div class="field">
            <label>Channel <span style="color:var(--danger)">*</span></label>
            <select name="channel" class="field-select" required>
                <option value="">— Select channel —</option>
                <?php foreach ($validChannels as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= (!empty($_POST['channel']) && $_POST['channel'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Invoice / Reference</label>
            <input type="text" name="invoice_no" class="field-input"
                placeholder="e.g. INV-2024-001"
                value="<?= htmlspecialchars($_POST['invoice_no'] ?? '') ?>">
        </div>

        <div class="field">
            <label>Notes</label>
            <textarea name="notes" class="field-textarea" rows="3" placeholder="Optional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Move Stock
            </button>
            <a href="<?= BASE_URL ?>/mobile/index.php" class="btn-secondary">Cancel</a>
        </div>

    </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
