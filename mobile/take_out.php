<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Take Out Stock';
$activeNav = 'stock';
$showBack  = true;
$backUrl   = 'mobile/stock.php';

$validChannels = ['takealot' => 'Takealot', 'makro' => 'Makro', 'instore' => 'In-Store', 'email' => 'Email Order', 'other' => 'Other / Write-off'];

$errors  = [];
$success = false;
$summary = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel   = trim($_POST['channel']    ?? '');
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $notes     = trim($_POST['notes']      ?? '');

    $rawText        = $_POST['serials_text'] ?? '';
    $enteredSerials = array_values(array_unique(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $rawText))))));

    if (!array_key_exists($channel, $validChannels)) $errors[] = 'Please select a channel / reason.';
    if (empty($enteredSerials))                       $errors[] = 'Please enter at least one serial number.';

    $resolvedRows    = [];
    $notFoundSerials = [];

    if (empty($errors)) {
        $in   = implode(',', array_fill(0, count($enteredSerials), '?'));
        $stmt = $pdo->prepare(
            "SELECT si.serial_no, si.product_id, si.warehouse_id,
                    p.name AS product_name, p.sku, w.name AS warehouse_name
             FROM stock_items si
             JOIN products   p ON p.id = si.product_id
             JOIN warehouses w ON w.id = si.warehouse_id
             WHERE si.serial_no IN ($in) AND si.status = 'in_stock'"
        );
        $stmt->execute($enteredSerials);
        $found    = $stmt->fetchAll();
        $foundMap = [];
        foreach ($found as $row) { $foundMap[$row['serial_no']] = $row; }

        foreach ($enteredSerials as $sn) {
            if (isset($foundMap[$sn])) {
                $resolvedRows[] = $foundMap[$sn];
            } else {
                $notFoundSerials[] = $sn;
            }
        }

        if (!empty($notFoundSerials)) $errors[] = 'Not found in stock: ' . implode(', ', $notFoundSerials);
        if (empty($resolvedRows))     $errors[] = 'No valid in-stock serials found.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $groups = [];
            foreach ($resolvedRows as $row) {
                $key = $row['product_id'] . '_' . $row['warehouse_id'];
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'product_id'     => $row['product_id'],
                        'product_name'   => $row['product_name'] . ' (' . $row['sku'] . ')',
                        'warehouse_id'   => $row['warehouse_id'],
                        'warehouse_name' => $row['warehouse_name'],
                        'serials'        => [],
                    ];
                }
                $groups[$key]['serials'][] = $row['serial_no'];
            }

            $insMov = $pdo->prepare(
                'INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                 VALUES (:pid, :fwid, NULL, :qty, :uid, :inv, :ch, :notes, NOW())'
            );
            $insMS  = $pdo->prepare('INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid, :sn)');
            $updSI  = $pdo->prepare("UPDATE stock_items SET status = 'sold' WHERE serial_no = :sn");
            $decr   = $pdo->prepare(
                'INSERT INTO inventory_stock (product_id, warehouse_id, qty, updated_at)
                 VALUES (:pid, :wid, 0, NOW())
                 ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty - :qty), updated_at = NOW()'
            );

            $summaryLines = [];
            foreach ($groups as $grp) {
                $qty = count($grp['serials']);
                $insMov->execute([':pid' => $grp['product_id'], ':fwid' => $grp['warehouse_id'],
                    ':qty' => $qty, ':uid' => $_SESSION['user_id'], ':inv' => $invoiceNo, ':ch' => $channel, ':notes' => $notes]);
                $movId = (int)$pdo->lastInsertId();

                foreach ($grp['serials'] as $sn) {
                    $insMS->execute([':mid' => $movId, ':sn' => $sn]);
                    $updSI->execute([':sn' => $sn]);
                }
                $decr->execute([':pid' => $grp['product_id'], ':wid' => $grp['warehouse_id'], ':qty' => $qty]);

                logAudit($pdo, 'take_out_stock', 'stock_movements', $movId,
                    "Mobile: Took out $qty x \"{$grp['product_name']}\" from \"{$grp['warehouse_name']}\" via $channel.");

                $summaryLines[] = "$qty × {$grp['product_name']} from {$grp['warehouse_name']}";
            }

            $pdo->commit();
            $summary = ['lines' => $summaryLines, 'qty' => count($resolvedRows), 'channel' => $channel];
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
        <strong>Taken out <?= $summary['qty'] ?> unit<?= $summary['qty'] !== 1 ? 's' : '' ?></strong>
        via <?= ucfirst(htmlspecialchars($summary['channel'])) ?>.<br>
        <?php foreach ($summary['lines'] as $line): ?>&bull; <?= htmlspecialchars($line) ?><br><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">

        <div class="field">
            <label>Channel / Reason <span style="color:var(--danger)">*</span></label>
            <select name="channel" class="field-select" required>
                <option value="">— Select —</option>
                <?php foreach ($validChannels as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= (!empty($_POST['channel']) && $_POST['channel'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
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
            <div class="field-hint">The system will find the product and warehouse for each serial automatically.</div>
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
            <button type="submit" class="btn-primary btn-danger"
                onclick="return confirm('Mark these serials as sold / removed?')">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-4M15 3h6v6M10 14L21 3" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Take Out Stock
            </button>
            <a href="<?= BASE_URL ?>/mobile/index.php" class="btn-secondary">Cancel</a>
        </div>

    </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
