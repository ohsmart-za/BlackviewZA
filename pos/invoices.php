<?php
// ============================================================
// Blackview SA Portal — Invoice History
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Invoice History';

// ============================================================
// Shared labels
// ============================================================
$channelLabels = [
    'takealot' => 'Takealot',
    'makro'    => 'Makro',
    'instore'  => 'In-Store',
    'email'    => 'Email',
    'other'    => 'Other',
];
$pmLabels = ['cash' => 'Cash', 'eft' => 'EFT', 'card' => 'Card'];
try {
    $pmRows = $pdo->query("SELECT code, name FROM payment_methods ORDER BY sort_order ASC, name ASC")->fetchAll();
    if (!empty($pmRows)) {
        $pmLabels = [];
        foreach ($pmRows as $pmr) $pmLabels[$pmr['code']] = $pmr['name'];
    }
} catch (Throwable $e) {
    // payment_methods table not yet created — use hardcoded defaults above
}

// ============================================================
// Active tab
// ============================================================
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'takeout') ? 'takeout' : 'pos';

// ============================================================
// POS Invoices — filters & query
// ============================================================
$dateFrom   = $_GET['date_from']   ?? date('Y-m-d', strtotime('-30 days'));
$dateTo     = $_GET['date_to']     ?? date('Y-m-d');
$custSearch = trim($_GET['customer'] ?? '');
$pmFilter   = $_GET['payment']     ?? 'all';
$chFilter   = $_GET['channel']     ?? 'all';

// Sanitise dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

$validPayments = array_merge(['all'], array_keys($pmLabels));
if (!in_array($pmFilter, $validPayments, true)) $pmFilter = 'all';

$validChannels = ['all', 'takealot', 'makro', 'instore', 'email', 'other'];
if (!in_array($chFilter, $validChannels, true)) $chFilter = 'all';

// Pagination for POS tab
$posPage    = max(1, (int)($_GET['pos_page'] ?? 1));
$perPage    = 50;
$posOffset  = ($posPage - 1) * $perPage;

// Build where clauses for POS invoices
$posWhere  = ['inv.created_at >= :df', 'inv.created_at <= :dt'];
$posParams = [
    ':df' => $dateFrom . ' 00:00:00',
    ':dt' => $dateTo   . ' 23:59:59',
];

if ($custSearch !== '') {
    $posWhere[]             = '(c.name LIKE :cs OR c.email LIKE :cs2)';
    $posParams[':cs']  = '%' . $custSearch . '%';
    $posParams[':cs2'] = '%' . $custSearch . '%';
}
if ($pmFilter !== 'all') {
    $posWhere[]          = 'inv.payment_method = :pm';
    $posParams[':pm']    = $pmFilter;
}
if ($chFilter !== 'all') {
    $posWhere[]          = 'inv.channel = :ch';
    $posParams[':ch']    = $chFilter;
}

$posWhereSQL = 'WHERE ' . implode(' AND ', $posWhere);

// Count total for pagination
$posCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     $posWhereSQL"
);
$posCountStmt->execute($posParams);
$posTotalRows = (int)$posCountStmt->fetchColumn();
$posTotalPages = max(1, (int)ceil($posTotalRows / $perPage));
if ($posPage > $posTotalPages) $posPage = $posTotalPages;

// Grand total for footer
$posTotStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(inv.subtotal),0)  AS subtotal_sum,
            COALESCE(SUM(inv.vat_amount),0) AS vat_sum,
            COALESCE(SUM(inv.total),0)      AS total_sum
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     $posWhereSQL"
);
$posTotStmt->execute($posParams);
$posTotals = $posTotStmt->fetch();

// Fetch page of invoices
$posInvStmt = $pdo->prepare(
    "SELECT inv.id, inv.invoice_no, inv.channel, inv.payment_method,
            inv.subtotal, inv.vat_amount, inv.total, inv.created_at,
            COALESCE(inv.status,'active') AS status,
            c.name AS customer_name,
            (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = inv.id) AS item_count,
            COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = inv.id), 0) AS total_paid,
            (SELECT COUNT(*) FROM credit_notes cn WHERE cn.invoice_id = inv.id AND cn.status != 'voided') AS cn_count
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     $posWhereSQL
     ORDER BY inv.created_at DESC
     LIMIT $perPage OFFSET $posOffset"
);
$posInvStmt->execute($posParams);
$posInvoices = $posInvStmt->fetchAll();

// ============================================================
// Take-Out Movements — filters & query
// ============================================================
$toDateFrom = $_GET['to_date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$toDateTo   = $_GET['to_date_to']   ?? date('Y-m-d');
$toChannel  = $_GET['to_channel']   ?? 'all';
$toWarehouse= $_GET['to_warehouse'] ?? 'all';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDateFrom)) $toDateFrom = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDateTo))   $toDateTo   = date('Y-m-d');

if (!in_array($toChannel, $validChannels, true)) $toChannel = 'all';

// Load warehouses for filter dropdown
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();
$validWarehouses = array_merge(['all'], array_column($warehouses, 'id'));

// Takeout pagination
$toPage   = max(1, (int)($_GET['to_page'] ?? 1));
$toOffset = ($toPage - 1) * $perPage;

$toWhere  = [
    'sm.to_warehouse_id IS NULL',
    "sm.channel NOT IN ('received','transfer')",
    'sm.moved_at >= :tdf',
    'sm.moved_at <= :tdt',
];
$toParams = [
    ':tdf' => $toDateFrom . ' 00:00:00',
    ':tdt' => $toDateTo   . ' 23:59:59',
];

if ($toChannel !== 'all') {
    $toWhere[]        = 'sm.channel = :tch';
    $toParams[':tch'] = $toChannel;
}
if ($toWarehouse !== 'all' && ctype_digit((string)$toWarehouse)) {
    $toWhere[]        = 'sm.from_warehouse_id = :twh';
    $toParams[':twh'] = (int)$toWarehouse;
}

$toWhereSQL = 'WHERE ' . implode(' AND ', $toWhere);

// Count
$toCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM stock_movements sm $toWhereSQL"
);
$toCountStmt->execute($toParams);
$toTotalRows  = (int)$toCountStmt->fetchColumn();
$toTotalPages = max(1, (int)ceil($toTotalRows / $perPage));
if ($toPage > $toTotalPages) $toPage = $toTotalPages;

// Fetch page
$toMoveStmt = $pdo->prepare(
    "SELECT sm.id, sm.product_id, sm.from_warehouse_id, sm.qty, sm.moved_by,
            sm.invoice_no, sm.channel, sm.notes, sm.moved_at,
            p.name AS product_name, p.sku AS product_sku,
            w.name AS warehouse_name,
            u.name AS moved_by_name
     FROM stock_movements sm
     LEFT JOIN products p   ON p.id = sm.product_id
     LEFT JOIN warehouses w ON w.id = sm.from_warehouse_id
     LEFT JOIN users u      ON u.id = sm.moved_by
     $toWhereSQL
     ORDER BY sm.moved_at DESC
     LIMIT $perPage OFFSET $toOffset"
);
$toMoveStmt->execute($toParams);
$toMovements = $toMoveStmt->fetchAll();

// Load serials for each movement
$movementSerials = [];
if (!empty($toMovements)) {
    $movIds      = array_column($toMovements, 'id');
    $inPlaces    = implode(',', array_fill(0, count($movIds), '?'));
    $serialsStmt = $pdo->prepare("SELECT movement_id, serial_no FROM movement_serials WHERE movement_id IN ($inPlaces) ORDER BY movement_id ASC, serial_no ASC");
    $serialsStmt->execute($movIds);
    foreach ($serialsStmt->fetchAll() as $row) {
        $movementSerials[$row['movement_id']][] = $row['serial_no'];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div>
        <h2 class="page-title">Invoice History</h2>
        <p class="page-subtitle">POS invoices and take-out stock movements.</p>
    </div>
    <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-primary btn-sm">+ New Invoice</a>
</div>

<!-- ============================================================
     TAB BUTTONS
     ============================================================ -->
<div class="tab-bar" style="display:flex;gap:.5rem;margin-bottom:1.25rem;border-bottom:2px solid var(--color-border);padding-bottom:0;">
    <button type="button" id="tab-btn-pos"
            class="tab-btn <?= $activeTab === 'pos' ? 'active' : '' ?>"
            onclick="switchTab('pos')"
            style="padding:.5rem 1.25rem;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--color-muted);">
        POS Invoices
        <?php if ($posTotalRows > 0): ?>
            <span class="badge" style="margin-left:.35rem;"><?= number_format($posTotalRows) ?></span>
        <?php endif; ?>
    </button>
    <button type="button" id="tab-btn-takeout"
            class="tab-btn <?= $activeTab === 'takeout' ? 'active' : '' ?>"
            onclick="switchTab('takeout')"
            style="padding:.5rem 1.25rem;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--color-muted);">
        Take-Out Movements
        <?php if ($toTotalRows > 0): ?>
            <span class="badge" style="margin-left:.35rem;"><?= number_format($toTotalRows) ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- ============================================================
     TAB PANEL: POS INVOICES
     ============================================================ -->
<div id="panel-pos" class="tab-panel" style="display:<?= $activeTab === 'pos' ? 'block' : 'none' ?>;">

    <!-- Filter bar -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-body" style="padding:.75rem 1rem;">
            <form method="GET" action="" id="pos-filter-form"
                  style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.75rem;">
                <input type="hidden" name="tab" value="pos">
                <input type="hidden" name="pos_page" value="1">
                <div class="form-group" style="margin:0;min-width:130px;">
                    <label class="form-label" style="font-size:.8rem;">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:130px;">
                    <label class="form-label" style="font-size:.8rem;">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:160px;">
                    <label class="form-label" style="font-size:.8rem;">Customer</label>
                    <input type="text" name="customer" class="form-control form-control-sm"
                           placeholder="Name or email…" value="<?= htmlspecialchars($custSearch) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:120px;">
                    <label class="form-label" style="font-size:.8rem;">Payment</label>
                    <select name="payment" class="form-control form-control-sm">
                        <option value="all" <?= $pmFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach ($pmLabels as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $pmFilter === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:120px;">
                    <label class="form-label" style="font-size:.8rem;">Channel</label>
                    <select name="channel" class="form-control form-control-sm">
                        <option value="all" <?= $chFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach ($channelLabels as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $chFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:.5rem;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="?tab=pos" class="btn btn-outline btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Results table -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">POS Invoices</h3>
            <span style="font-size:.85rem;color:var(--color-muted);">
                <?= number_format($posTotalRows) ?> invoice<?= $posTotalRows !== 1 ? 's' : '' ?>
                &mdash; Grand Total: <strong>R <?= number_format((float)$posTotals['total_sum'], 2) ?></strong>
            </span>
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <table class="table" style="margin:0;width:100%;">
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Channel</th>
                        <th>Payment</th>
                        <th class="text-right">Items</th>
                        <th class="text-right">Total (incl. VAT)</th>
                        <th class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posInvoices as $inv):
                        $invIsVoided = ($inv['status'] ?? 'active') === 'voided';
                        $balance  = $invIsVoided ? 0 : round((float)$inv['total'] - (float)$inv['total_paid'], 2);
                        $isPaid   = $balance <= 0.00;
                        $hasCN    = (int)$inv['cn_count'] > 0;
                        $rowStyle = $invIsVoided
                            ? 'background:#f9fafb;opacity:.65;'
                            : ($hasCN ? 'background:#FFF8F8;' : '');
                    ?>
                    <tr style="<?= $rowStyle ?>cursor:pointer;"
                        onclick="window.location='<?= BASE_URL ?>/pos/invoice.php?id=<?= $inv['id'] ?>'"
                        title="View invoice <?= htmlspecialchars($inv['invoice_no']) ?>">
                        <td style="font-family:monospace;font-size:.85rem;white-space:nowrap;">
                            <?= htmlspecialchars($inv['invoice_no']) ?>
                            <?php if ($invIsVoided): ?>
                                <span style="display:inline-block;margin-left:4px;font-size:.7rem;font-weight:700;background:#fee2e2;color:#dc2626;padding:1px 6px;border-radius:4px;vertical-align:middle;font-family:sans-serif;text-decoration:line-through;">
                                    VOIDED
                                </span>
                            <?php elseif ($hasCN): ?>
                                <span style="display:inline-block;margin-left:4px;font-size:.7rem;font-weight:700;background:#FEE2E2;color:#DC2626;padding:1px 6px;border-radius:4px;vertical-align:middle;font-family:sans-serif;">
                                    CREDITED
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:.85rem;">
                            <?= date('d M Y', strtotime($inv['created_at'])) ?><br>
                            <span style="color:var(--color-muted);font-size:.78rem;"><?= date('H:i', strtotime($inv['created_at'])) ?></span>
                        </td>
                        <td><?= htmlspecialchars($inv['customer_name'] ?? 'Walk-in') ?></td>
                        <td><?= htmlspecialchars($channelLabels[$inv['channel']] ?? ucfirst($inv['channel'])) ?></td>
                        <td>
                            <span class="badge badge-pm-<?= htmlspecialchars($inv['payment_method'] ?? 'cash') ?>">
                                <?= htmlspecialchars($pmLabels[$inv['payment_method']] ?? ucfirst($inv['payment_method'] ?? '')) ?>
                            </span>
                        </td>
                        <td class="text-right"><?= (int)$inv['item_count'] ?></td>
                        <td class="text-right"><strong>R <?= number_format((float)$inv['total'], 2) ?></strong></td>
                        <td class="text-right" style="white-space:nowrap;">
                            <?php if ($invIsVoided): ?>
                                <span style="color:#dc2626;font-weight:600;font-size:.82rem;">🚫 Voided</span>
                            <?php elseif ($isPaid): ?>
                                <span style="color:#16A34A;font-weight:600;font-size:.82rem;">✓ Paid</span>
                            <?php else: ?>
                                <span style="color:#D97706;font-weight:600;">R <?= number_format($balance, 2) ?></span><br>
                                <span style="color:var(--color-muted);font-size:.75rem;">outstanding</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($posInvoices)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--color-muted);padding:2rem;">
                            No invoices found for the selected filters.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($posInvoices)): ?>
                <tfoot>
                    <tr style="background:#F8FAFC;font-weight:700;">
                        <td colspan="5">
                            Total — <?= number_format($posTotalRows) ?> invoice<?= $posTotalRows !== 1 ? 's' : '' ?>
                            (showing <?= count($posInvoices) ?>)
                        </td>
                        <td></td>
                        <td class="text-right">R <?= number_format((float)$posTotals['total_sum'], 2) ?></td>
                        <td class="text-right" style="color:var(--color-muted);font-size:.82rem;">
                            <?php
                            $pageBalance = array_sum(array_map(function($i) {
                                return max(0, round((float)$i['total'] - (float)$i['total_paid'], 2));
                            }, $posInvoices));
                            if ($pageBalance > 0):
                            ?>
                                <span style="color:#D97706;">R <?= number_format($pageBalance, 2) ?> outstanding</span>
                            <?php else: ?>
                                <span style="color:#16A34A;">All paid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($posTotalPages > 1): ?>
        <div class="card-body" style="padding:.75rem 1rem;border-top:1px solid var(--color-border);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <?php
            $baseUrl = '?' . http_build_query(array_filter([
                'tab'       => 'pos',
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'customer'  => $custSearch,
                'payment'   => $pmFilter !== 'all' ? $pmFilter : '',
                'channel'   => $chFilter !== 'all' ? $chFilter : '',
            ]));
            ?>
            <span style="font-size:.85rem;color:var(--color-muted);">
                Page <?= $posPage ?> of <?= $posTotalPages ?>
            </span>
            <?php if ($posPage > 1): ?>
                <a href="<?= $baseUrl ?>&pos_page=<?= $posPage - 1 ?>" class="btn btn-sm btn-outline">← Previous</a>
            <?php endif; ?>
            <?php
            $start = max(1, $posPage - 2);
            $end   = min($posTotalPages, $posPage + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
                <a href="<?= $baseUrl ?>&pos_page=<?= $p ?>"
                   class="btn btn-sm <?= $p === $posPage ? 'btn-primary' : 'btn-outline' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($posPage < $posTotalPages): ?>
                <a href="<?= $baseUrl ?>&pos_page=<?= $posPage + 1 ?>" class="btn btn-sm btn-outline">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /#panel-pos -->

<!-- ============================================================
     TAB PANEL: TAKE-OUT MOVEMENTS
     ============================================================ -->
<div id="panel-takeout" class="tab-panel" style="display:<?= $activeTab === 'takeout' ? 'block' : 'none' ?>;">

    <!-- Filter bar -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-body" style="padding:.75rem 1rem;">
            <form method="GET" action=""
                  style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.75rem;">
                <input type="hidden" name="tab" value="takeout">
                <input type="hidden" name="to_page" value="1">
                <div class="form-group" style="margin:0;min-width:130px;">
                    <label class="form-label" style="font-size:.8rem;">Date From</label>
                    <input type="date" name="to_date_from" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($toDateFrom) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:130px;">
                    <label class="form-label" style="font-size:.8rem;">Date To</label>
                    <input type="date" name="to_date_to" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($toDateTo) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:120px;">
                    <label class="form-label" style="font-size:.8rem;">Channel</label>
                    <select name="to_channel" class="form-control form-control-sm">
                        <option value="all" <?= $toChannel === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach ($channelLabels as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $toChannel === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:150px;">
                    <label class="form-label" style="font-size:.8rem;">Warehouse</label>
                    <select name="to_warehouse" class="form-control form-control-sm">
                        <option value="all" <?= $toWarehouse === 'all' ? 'selected' : '' ?>>All Warehouses</option>
                        <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= (string)$toWarehouse === (string)$wh['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($wh['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:.5rem;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="?tab=takeout" class="btn btn-outline btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Results table -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Take-Out Movements</h3>
            <span style="font-size:.85rem;color:var(--color-muted);">
                <?= number_format($toTotalRows) ?> movement<?= $toTotalRows !== 1 ? 's' : '' ?>
            </span>
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <table class="table" style="margin:0;width:100%;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice / Ref</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th>Channel</th>
                        <th class="text-right">Qty</th>
                        <th>Serials</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($toMovements as $mv): ?>
                    <?php $mvSerials = $movementSerials[$mv['id']] ?? []; ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:.85rem;">
                            <?= date('d M Y', strtotime($mv['moved_at'])) ?><br>
                            <span style="color:var(--color-muted);font-size:.78rem;"><?= date('H:i', strtotime($mv['moved_at'])) ?></span>
                        </td>
                        <td style="font-family:monospace;font-size:.85rem;">
                            <?= !empty($mv['invoice_no']) ? htmlspecialchars($mv['invoice_no']) : '<span style="color:var(--color-muted);">—</span>' ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($mv['product_name'] ?? '—') ?></strong><br>
                            <small style="color:var(--color-muted);"><?= htmlspecialchars($mv['product_sku'] ?? '') ?></small>
                        </td>
                        <td><?= htmlspecialchars($mv['warehouse_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($channelLabels[$mv['channel']] ?? ucfirst($mv['channel'] ?? '')) ?></td>
                        <td class="text-right"><?= (int)$mv['qty'] ?></td>
                        <td style="font-family:monospace;font-size:.8rem;">
                            <?php if (empty($mvSerials)): ?>
                                <span style="color:var(--color-muted);">—</span>
                            <?php else: ?>
                                <?php $shown = array_slice($mvSerials, 0, 2); $hidden = array_slice($mvSerials, 2); ?>
                                <?php foreach ($shown as $sn): ?>
                                    <div><?= htmlspecialchars($sn) ?></div>
                                <?php endforeach; ?>
                                <?php if (!empty($hidden)): ?>
                                <div class="serial-toggle-wrap">
                                    <span class="serial-toggle-hidden" style="display:none;">
                                        <?php foreach ($hidden as $sn): ?>
                                            <div><?= htmlspecialchars($sn) ?></div>
                                        <?php endforeach; ?>
                                    </span>
                                    <a href="#" class="serial-toggle-link" style="font-size:.75rem;color:var(--color-primary);">
                                        + <?= count($hidden) ?> more
                                    </a>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:200px;font-size:.85rem;">
                            <?= !empty($mv['notes']) ? htmlspecialchars($mv['notes']) : '<span style="color:var(--color-muted);">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($toMovements)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--color-muted);padding:2rem;">
                            No take-out movements found for the selected filters.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($toMovements)): ?>
                <tfoot>
                    <tr style="background:#F8FAFC;font-weight:700;">
                        <td colspan="5">
                            Total — <?= number_format($toTotalRows) ?> movement<?= $toTotalRows !== 1 ? 's' : '' ?>
                            (showing <?= count($toMovements) ?>)
                        </td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($toTotalPages > 1): ?>
        <div class="card-body" style="padding:.75rem 1rem;border-top:1px solid var(--color-border);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <?php
            $toBaseUrl = '?' . http_build_query(array_filter([
                'tab'          => 'takeout',
                'to_date_from' => $toDateFrom,
                'to_date_to'   => $toDateTo,
                'to_channel'   => $toChannel !== 'all' ? $toChannel : '',
                'to_warehouse' => $toWarehouse !== 'all' ? $toWarehouse : '',
            ]));
            ?>
            <span style="font-size:.85rem;color:var(--color-muted);">
                Page <?= $toPage ?> of <?= $toTotalPages ?>
            </span>
            <?php if ($toPage > 1): ?>
                <a href="<?= $toBaseUrl ?>&to_page=<?= $toPage - 1 ?>" class="btn btn-sm btn-outline">← Previous</a>
            <?php endif; ?>
            <?php
            $tStart = max(1, $toPage - 2);
            $tEnd   = min($toTotalPages, $toPage + 2);
            for ($p = $tStart; $p <= $tEnd; $p++):
            ?>
                <a href="<?= $toBaseUrl ?>&to_page=<?= $p ?>"
                   class="btn btn-sm <?= $p === $toPage ? 'btn-primary' : 'btn-outline' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($toPage < $toTotalPages): ?>
                <a href="<?= $toBaseUrl ?>&to_page=<?= $toPage + 1 ?>" class="btn btn-sm btn-outline">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /#panel-takeout -->

<style>
.tab-btn { transition: color .15s, border-bottom-color .15s; }
.tab-btn.active { color: var(--color-primary) !important; border-bottom-color: var(--color-primary) !important; }
.tab-btn:hover  { color: var(--color-primary) !important; }
.text-right { text-align: right; }
tbody tr[onclick]:hover { background: #EFF6FF !important; }
</style>

<script>
(function () {
    function switchTab(tab) {
        document.querySelectorAll('.tab-panel').forEach(function (p) { p.style.display = 'none'; });
        document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        var panel = document.getElementById('panel-' + tab);
        var btn   = document.getElementById('tab-btn-' + tab);
        if (panel) panel.style.display = 'block';
        if (btn)   btn.classList.add('active');
        // Update URL without reload
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        history.replaceState(null, '', url.toString());
    }
    window.switchTab = switchTab;

    // Serial toggle links
    document.querySelectorAll('.serial-toggle-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var wrap   = link.closest('.serial-toggle-wrap');
            var hidden = wrap.querySelector('.serial-toggle-hidden');
            if (hidden.style.display === 'none') {
                hidden.style.display = 'block';
                link.textContent = 'Show less';
            } else {
                hidden.style.display = 'none';
                var count = wrap.querySelectorAll('.serial-toggle-hidden div').length;
                link.textContent = '+ ' + count + ' more';
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
