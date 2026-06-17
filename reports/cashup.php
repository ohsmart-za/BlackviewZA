<?php
// ============================================================
// Blackview SA Portal — Daily Cashup Report
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Daily Cashup';

// ---- Date filter ----
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$dateStart = $selectedDate . ' 00:00:00';
$dateEnd   = $selectedDate . ' 23:59:59';

// ---- Load invoices for selected date ----
$invStmt = $pdo->prepare(
    "SELECT inv.*,
            c.name  AS customer_name,
            c.phone AS customer_phone,
            u.name  AS created_by_name
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     LEFT JOIN users u     ON u.id = inv.created_by
     WHERE inv.created_at BETWEEN :ds AND :de
     ORDER BY inv.created_at ASC"
);
$invStmt->execute([':ds' => $dateStart, ':de' => $dateEnd]);
$invoices = $invStmt->fetchAll();

// ---- Load payment methods from DB (fallback to hardcoded if table not yet created) ----
$pmLabels = ['cash' => 'Cash', 'eft' => 'EFT', 'card' => 'Card'];
$pmIcons  = ['cash' => '💵',   'eft' => '🏦',   'card' => '💳'];
try {
    $pmRows = $pdo->query(
        "SELECT code, name, icon FROM payment_methods ORDER BY sort_order ASC, name ASC"
    )->fetchAll();
    if (!empty($pmRows)) {
        $pmLabels = [];
        $pmIcons  = [];
        foreach ($pmRows as $pmr) {
            $pmLabels[$pmr['code']] = $pmr['name'];
            $pmIcons[$pmr['code']]  = $pmr['icon'];
        }
    }
} catch (Throwable $e) {
    // payment_methods table not yet created — use hardcoded defaults above
}

// ---- Totals by payment method (built dynamically) ----
$totals = [];
foreach ($pmLabels as $code => $label) {
    $totals[$code] = ['count' => 0, 'subtotal' => 0, 'vat' => 0, 'total' => 0];
}
$grandTotal   = 0;
$grandVat     = 0;
$grandSubtotal= 0;
$grandCount   = 0;

foreach ($invoices as $inv) {
    $pm = $inv['payment_method'] ?? 'cash';
    if (!isset($totals[$pm])) $pm = 'cash';
    $totals[$pm]['count']++;
    $totals[$pm]['subtotal'] += (float)$inv['subtotal'];
    $totals[$pm]['vat']      += (float)$inv['vat_amount'];
    $totals[$pm]['total']    += (float)$inv['total'];
    $grandTotal    += (float)$inv['total'];
    $grandVat      += (float)$inv['vat_amount'];
    $grandSubtotal += (float)$inv['subtotal'];
    $grandCount++;
}

// ---- Credit notes issued on this day (not voided) ----
$cnStmt = $pdo->prepare(
    "SELECT cn.*,
            inv.invoice_no,
            c.name  AS customer_name,
            u.name  AS created_by_name
     FROM credit_notes cn
     JOIN invoices inv ON inv.id = cn.invoice_id
     LEFT JOIN customers c ON c.id = inv.customer_id
     LEFT JOIN users u     ON u.id = cn.created_by
     WHERE cn.created_at BETWEEN :ds AND :de
       AND cn.status != 'voided'
     ORDER BY cn.created_at ASC"
);
$cnStmt->execute([':ds' => $dateStart, ':de' => $dateEnd]);
$creditNotes = $cnStmt->fetchAll();

$cnTotal    = 0;
$cnVat      = 0;
$cnSubtotal = 0;
$cnCount    = count($creditNotes);
foreach ($creditNotes as $cn) {
    $cnTotal    += (float)$cn['total'];
    $cnVat      += (float)$cn['vat_amount'];
    $cnSubtotal += (float)$cn['subtotal'];
}

// ---- Channel breakdown ----
$channelTotals = [];
foreach ($invoices as $inv) {
    $ch = $inv['channel'] ?? 'other';
    if (!isset($channelTotals[$ch])) {
        $channelTotals[$ch] = ['count' => 0, 'total' => 0];
    }
    $channelTotals[$ch]['count']++;
    $channelTotals[$ch]['total'] += (float)$inv['total'];
}

$channelLabels = [
    'takealot' => 'Takealot', 'makro' => 'Makro',
    'instore'  => 'In-Store', 'email' => 'Email', 'other' => 'Other',
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Daily Cashup</h2>
    <p class="page-subtitle">Sales summary and invoice list for a single trading day.</p>
</div>

<!-- Date picker -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-body" style="padding:.75rem 1rem;">
        <form method="GET" action="" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <label class="form-label" style="margin:0;font-weight:600;">Date</label>
            <input type="date" name="date" class="form-control" style="width:auto;"
                   value="<?= htmlspecialchars($selectedDate) ?>">
            <button type="submit" class="btn btn-primary btn-sm">View</button>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm">Today</a>
            <?php
                $prev = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
                $next = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
            ?>
            <a href="?date=<?= $prev ?>" class="btn btn-outline btn-sm">← Previous Day</a>
            <?php if ($next <= date('Y-m-d')): ?>
            <a href="?date=<?= $next ?>" class="btn btn-outline btn-sm">Next Day →</a>
            <?php endif; ?>
            <span style="color:var(--color-muted);font-size:.85rem;margin-left:auto;">
                <?= date('l, d F Y', strtotime($selectedDate)) ?>
            </span>
        </form>
    </div>
</div>

<?php if ($grandCount === 0): ?>
<div class="alert" style="background:#F0F9FF;border-color:#BAE6FD;color:#0369A1;">
    No sales recorded for <?= date('d F Y', strtotime($selectedDate)) ?>.
</div>
<?php else: ?>

<!-- Payment Method Summary Cards -->
<div class="cashup-summary-grid" style="margin-bottom:1.25rem;">
    <?php foreach ($totals as $pm => $t): ?>
    <div class="cashup-pm-card cashup-pm-<?= $pm ?>">
        <div class="cashup-pm-icon"><?= $pmIcons[$pm] ?></div>
        <div class="cashup-pm-label"><?= $pmLabels[$pm] ?></div>
        <div class="cashup-pm-amount">R <?= number_format($t['total'], 2) ?></div>
        <div class="cashup-pm-sub"><?= $t['count'] ?> sale<?= $t['count'] !== 1 ? 's' : '' ?> &middot; VAT R <?= number_format($t['vat'], 2) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="cashup-pm-card cashup-pm-total">
        <div class="cashup-pm-icon">🧾</div>
        <div class="cashup-pm-label">Gross Sales</div>
        <div class="cashup-pm-amount">R <?= number_format($grandTotal, 2) ?></div>
        <div class="cashup-pm-sub"><?= $grandCount ?> sale<?= $grandCount !== 1 ? 's' : '' ?> &middot; VAT R <?= number_format($grandVat, 2) ?></div>
    </div>
    <?php if ($cnCount > 0): ?>
    <div class="cashup-pm-card" style="background:#FEF2F2;border-color:#FECACA;">
        <div class="cashup-pm-icon">↩️</div>
        <div class="cashup-pm-label" style="color:#991B1B;">Credit Notes</div>
        <div class="cashup-pm-amount" style="color:#DC2626;">- R <?= number_format($cnTotal, 2) ?></div>
        <div class="cashup-pm-sub"><?= $cnCount ?> credit note<?= $cnCount !== 1 ? 's' : '' ?> &middot; VAT R <?= number_format($cnVat, 2) ?></div>
    </div>
    <div class="cashup-pm-card" style="background:#F0FDF4;border-color:#BBF7D0;">
        <div class="cashup-pm-icon">✅</div>
        <div class="cashup-pm-label" style="color:#166534;">Net Total</div>
        <div class="cashup-pm-amount" style="color:#166534;">R <?= number_format($grandTotal - $cnTotal, 2) ?></div>
        <div class="cashup-pm-sub">After credit notes</div>
    </div>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">

    <!-- Totals breakdown table -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Payment Breakdown</h3></div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Method</th>
                        <th class="text-right">Sales</th>
                        <th class="text-right">Excl. VAT</th>
                        <th class="text-right">VAT</th>
                        <th class="text-right">Incl. VAT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($totals as $pm => $t): if ($t['count'] === 0) continue; ?>
                    <tr>
                        <td><?= $pmIcons[$pm] ?> <?= $pmLabels[$pm] ?></td>
                        <td class="text-right"><?= $t['count'] ?></td>
                        <td class="text-right">R <?= number_format($t['subtotal'], 2) ?></td>
                        <td class="text-right">R <?= number_format($t['vat'], 2) ?></td>
                        <td class="text-right"><strong>R <?= number_format($t['total'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                    <?php if ($cnCount > 0): ?>
                    <tr style="color:#DC2626;font-size:.875rem;">
                        <td>↩️ Credit Notes</td>
                        <td class="text-right"><?= $cnCount ?></td>
                        <td class="text-right">- R <?= number_format($cnSubtotal, 2) ?></td>
                        <td class="text-right">- R <?= number_format($cnVat, 2) ?></td>
                        <td class="text-right"><strong>- R <?= number_format($cnTotal, 2) ?></strong></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
                <tfoot>
                    <tr style="background:#F8FAFC;font-weight:700;">
                        <td>Gross Total</td>
                        <td class="text-right"><?= $grandCount ?></td>
                        <td class="text-right">R <?= number_format($grandSubtotal, 2) ?></td>
                        <td class="text-right">R <?= number_format($grandVat, 2) ?></td>
                        <td class="text-right">R <?= number_format($grandTotal, 2) ?></td>
                    </tr>
                    <?php if ($cnCount > 0): ?>
                    <tr style="background:#F0FDF4;font-weight:700;color:#166534;">
                        <td>Net Total</td>
                        <td class="text-right"><?= $grandCount ?></td>
                        <td class="text-right">R <?= number_format($grandSubtotal - $cnSubtotal, 2) ?></td>
                        <td class="text-right">R <?= number_format($grandVat - $cnVat, 2) ?></td>
                        <td class="text-right">R <?= number_format($grandTotal - $cnTotal, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Channel breakdown -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Channel Breakdown</h3></div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th class="text-right">Sales</th>
                        <th class="text-right">Total (incl. VAT)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channelTotals as $ch => $ct): ?>
                    <tr>
                        <td><?= htmlspecialchars($channelLabels[$ch] ?? ucfirst($ch)) ?></td>
                        <td class="text-right"><?= $ct['count'] ?></td>
                        <td class="text-right"><strong>R <?= number_format($ct['total'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Invoice List -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">Invoices — <?= date('d F Y', strtotime($selectedDate)) ?></h3>
        <button onclick="window.print()" class="btn btn-sm btn-outline">🖨 Print Cashup</button>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Channel</th>
                    <th>Payment</th>
                    <th class="text-right">Excl. VAT</th>
                    <th class="text-right">VAT</th>
                    <th class="text-right">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td style="white-space:nowrap;color:var(--color-muted);font-size:.85rem;">
                        <?= date('H:i', strtotime($inv['created_at'])) ?>
                    </td>
                    <td style="font-family:monospace;font-size:.85rem;">
                        <?= htmlspecialchars($inv['invoice_no']) ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($inv['customer_name'] ?? 'Walk-in') ?>
                        <?php if (!empty($inv['customer_phone'])): ?>
                            <small style="color:var(--color-muted);display:block;"><?= htmlspecialchars($inv['customer_phone']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($channelLabels[$inv['channel']] ?? ucfirst($inv['channel'])) ?></td>
                    <td>
                        <span class="badge badge-pm-<?= htmlspecialchars($inv['payment_method'] ?? 'cash') ?>">
                            <?= $pmIcons[$inv['payment_method'] ?? 'cash'] ?? '' ?>
                            <?= htmlspecialchars($pmLabels[$inv['payment_method'] ?? 'cash'] ?? ucfirst($inv['payment_method'] ?? 'cash')) ?>
                        </span>
                    </td>
                    <td class="text-right">R <?= number_format((float)$inv['subtotal'], 2) ?></td>
                    <td class="text-right">R <?= number_format((float)$inv['vat_amount'], 2) ?></td>
                    <td class="text-right"><strong>R <?= number_format((float)$inv['total'], 2) ?></strong></td>
                    <td>
                        <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $inv['id'] ?>"
                           class="btn btn-sm btn-outline" target="_blank">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#F8FAFC;font-weight:700;">
                    <td colspan="5">Day Total — <?= $grandCount ?> invoice<?= $grandCount !== 1 ? 's' : '' ?></td>
                    <td class="text-right">R <?= number_format($grandSubtotal, 2) ?></td>
                    <td class="text-right">R <?= number_format($grandVat, 2) ?></td>
                    <td class="text-right">R <?= number_format($grandTotal, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($cnCount > 0): ?>
<!-- Credit Notes List -->
<div class="card" style="margin-top:1.25rem;border-color:#FECACA;">
    <div class="card-header" style="background:#FEF2F2;display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title" style="color:#991B1B;">↩️ Credit Notes — <?= date('d F Y', strtotime($selectedDate)) ?></h3>
        <span style="font-size:.82rem;color:#DC2626;font-weight:600;">
            <?= $cnCount ?> credit note<?= $cnCount !== 1 ? 's' : '' ?> &nbsp;|&nbsp; Total: - R <?= number_format($cnTotal, 2) ?>
        </span>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr style="background:#FEF2F2;">
                    <th>Time</th>
                    <th>Credit Note #</th>
                    <th>Against Invoice</th>
                    <th>Customer</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th class="text-right">Excl. VAT</th>
                    <th class="text-right">VAT</th>
                    <th class="text-right">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($creditNotes as $cn): ?>
                <tr style="background:#FFFBFB;">
                    <td style="white-space:nowrap;color:var(--color-muted);font-size:.85rem;">
                        <?= date('H:i', strtotime($cn['created_at'])) ?>
                    </td>
                    <td style="font-family:monospace;font-size:.85rem;color:#DC2626;">
                        <?= htmlspecialchars($cn['credit_note_no']) ?>
                    </td>
                    <td style="font-family:monospace;font-size:.85rem;">
                        <?= htmlspecialchars($cn['invoice_no']) ?>
                    </td>
                    <td><?= htmlspecialchars($cn['customer_name'] ?? 'Walk-in') ?></td>
                    <td style="font-size:.83rem;color:var(--color-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($cn['reason'] ?? '') ?>">
                        <?= htmlspecialchars($cn['reason'] ?? '—') ?>
                    </td>
                    <td>
                        <span style="font-size:.78rem;font-weight:600;text-transform:capitalize;
                            color:<?= $cn['status'] === 'applied' ? '#166534' : '#D97706' ?>;">
                            <?= htmlspecialchars($cn['status']) ?>
                        </span>
                    </td>
                    <td class="text-right" style="color:#DC2626;">- R <?= number_format((float)$cn['subtotal'], 2) ?></td>
                    <td class="text-right" style="color:#DC2626;">- R <?= number_format((float)$cn['vat_amount'], 2) ?></td>
                    <td class="text-right"><strong style="color:#DC2626;">- R <?= number_format((float)$cn['total'], 2) ?></strong></td>
                    <td>
                        <a href="<?= BASE_URL ?>/pos/credit_note_view.php?id=<?= $cn['id'] ?>"
                           class="btn btn-sm btn-outline" target="_blank">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#FEF2F2;font-weight:700;color:#DC2626;">
                    <td colspan="6">Credit Notes Total — <?= $cnCount ?> note<?= $cnCount !== 1 ? 's' : '' ?></td>
                    <td class="text-right">- R <?= number_format($cnSubtotal, 2) ?></td>
                    <td class="text-right">- R <?= number_format($cnVat, 2) ?></td>
                    <td class="text-right">- R <?= number_format($cnTotal, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Net Day Summary -->
<div class="card" style="margin-top:1rem;background:#F0FDF4;border-color:#BBF7D0;">
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;padding:.85rem 1.25rem;">
        <div style="font-size:1rem;font-weight:700;color:#166534;">
            Net Day Total (after credit notes)
        </div>
        <div style="display:flex;gap:2rem;font-size:.9rem;color:#166534;">
            <span>Excl. VAT: <strong>R <?= number_format($grandSubtotal - $cnSubtotal, 2) ?></strong></span>
            <span>VAT: <strong>R <?= number_format($grandVat - $cnVat, 2) ?></strong></span>
            <span style="font-size:1.1rem;">Net Total: <strong>R <?= number_format($grandTotal - $cnTotal, 2) ?></strong></span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
