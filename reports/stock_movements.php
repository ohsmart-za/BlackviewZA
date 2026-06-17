<?php
// ============================================================
// Blackview SA Portal — Reports: Stock Movements
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Stock Movements Report';

$products   = $pdo->query('SELECT id, sku, name FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();

// Filters
$filterDateFrom  = trim($_GET['date_from']    ?? '');
$filterDateTo    = trim($_GET['date_to']      ?? '');
$filterChannel   = trim($_GET['channel']      ?? '');
$filterWarehouse = (int)($_GET['warehouse_id'] ?? 0);
$filterProduct   = (int)($_GET['product_id']   ?? 0);

$validChannels = ['takealot','makro','instore','email','transfer','received'];

// Build WHERE
$where  = [];
$params = [];

if ($filterDateFrom !== '') {
    $where[]           = 'sm.moved_at >= :dfrom';
    $params[':dfrom']  = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[]           = 'sm.moved_at <= :dto';
    $params[':dto']    = $filterDateTo . ' 23:59:59';
}
if ($filterChannel !== '' && in_array($filterChannel, $validChannels, true)) {
    $where[]           = 'sm.channel = :ch';
    $params[':ch']     = $filterChannel;
}
if ($filterWarehouse > 0) {
    $where[]           = '(sm.from_warehouse_id = :wid OR sm.to_warehouse_id = :wid2)';
    $params[':wid']    = $filterWarehouse;
    $params[':wid2']   = $filterWarehouse;
}
if ($filterProduct > 0) {
    $where[]           = 'sm.product_id = :pid';
    $params[':pid']    = $filterProduct;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- CSV Export ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sqlExport = "
        SELECT
            sm.moved_at,
            p.name  AS product,
            p.sku,
            fw.name AS from_warehouse,
            tw.name AS to_warehouse,
            sm.channel,
            sm.qty,
            sm.invoice_no,
            u.name  AS moved_by,
            sm.notes,
            GROUP_CONCAT(ms.serial_no ORDER BY ms.serial_no SEPARATOR ', ') AS serials
        FROM stock_movements sm
        JOIN products   p  ON p.id  = sm.product_id
        JOIN users      u  ON u.id  = sm.moved_by
        LEFT JOIN warehouses fw ON fw.id = sm.from_warehouse_id
        LEFT JOIN warehouses tw ON tw.id = sm.to_warehouse_id
        LEFT JOIN movement_serials ms ON ms.movement_id = sm.id
        $whereSQL
        GROUP BY sm.id
        ORDER BY sm.moved_at DESC
    ";
    $stmtExport = $pdo->prepare($sqlExport);
    $stmtExport->execute($params);
    $rows = $stmtExport->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stock_movements_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Product', 'SKU', 'From Warehouse', 'To Warehouse', 'Channel', 'Qty', 'Invoice No', 'Moved By', 'Notes', 'Serial Numbers']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['moved_at'],
            $row['product'],
            $row['sku'],
            $row['from_warehouse'] ?? '',
            $row['to_warehouse']   ?? '',
            $row['channel'],
            $row['qty'],
            $row['invoice_no'],
            $row['moved_by'],
            $row['notes']   ?? '',
            $row['serials'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// --- Main query ---
$sql = "
    SELECT
        sm.id,
        sm.moved_at,
        sm.qty,
        sm.channel,
        sm.invoice_no,
        sm.notes,
        p.name  AS product_name,
        p.sku,
        fw.name AS from_wh,
        tw.name AS to_wh,
        u.name  AS moved_by_name,
        GROUP_CONCAT(ms.serial_no ORDER BY ms.serial_no SEPARATOR ', ') AS serials
    FROM stock_movements sm
    JOIN products   p  ON p.id  = sm.product_id
    JOIN users      u  ON u.id  = sm.moved_by
    LEFT JOIN warehouses fw ON fw.id = sm.from_warehouse_id
    LEFT JOIN warehouses tw ON tw.id = sm.to_warehouse_id
    LEFT JOIN movement_serials ms ON ms.movement_id = sm.id
    $whereSQL
    GROUP BY sm.id
    ORDER BY sm.moved_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

$totalQty = array_sum(array_column($movements, 'qty'));

// Build export query string
$exportQS = http_build_query(array_merge($_GET, ['export' => 'csv']));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-title">Stock Movements Report</h2>
        <p class="page-subtitle">Filter and export stock movement history.</p>
    </div>
    <?php if (!empty($movements)): ?>
    <div>
        <a href="?<?= htmlspecialchars($exportQS) ?>" class="btn btn-success">Export CSV</a>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card filter-card">
    <form method="GET" action="" class="filter-form">
        <div class="form-row">
            <div class="form-group form-group--fifth">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control"
                       value="<?= htmlspecialchars($filterDateFrom) ?>">
            </div>
            <div class="form-group form-group--fifth">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control"
                       value="<?= htmlspecialchars($filterDateTo) ?>">
            </div>
            <div class="form-group form-group--fifth">
                <label class="form-label">Channel</label>
                <select name="channel" class="form-control form-select">
                    <option value="">All Channels</option>
                    <?php foreach ($validChannels as $ch): ?>
                        <option value="<?= $ch ?>" <?= $filterChannel === $ch ? 'selected' : '' ?>>
                            <?= ucfirst($ch) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--fifth">
                <label class="form-label">Warehouse</label>
                <select name="warehouse_id" class="form-control form-select">
                    <option value="">All Warehouses</option>
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['id'] ?>" <?= $filterWarehouse == $w['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($w['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--fifth">
                <label class="form-label">Product</label>
                <select name="product_id" class="form-control form-select">
                    <option value="">All Products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProduct == $p['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="<?= BASE_URL ?>/reports/stock_movements.php" class="btn btn-outline">Reset</a>
        </div>
    </form>
</div>

<div class="results-summary">
    <?= count($movements) ?> movement(s) found &mdash; Total units moved: <strong><?= number_format($totalQty) ?></strong>
</div>

<?php if (empty($movements)): ?>
    <div class="alert alert-info">No stock movements found matching your criteria.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover table-sm">
        <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th>SKU</th>
                <th class="text-right">Qty</th>
                <th>Serial Numbers</th>
                <th>From</th>
                <th>To</th>
                <th>Channel</th>
                <th>Invoice</th>
                <th>Moved By</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movements as $mv): ?>
            <tr>
                <td class="text-nowrap"><?= date('d M Y H:i', strtotime($mv['moved_at'])) ?></td>
                <td><?= htmlspecialchars($mv['product_name']) ?></td>
                <td><code><?= htmlspecialchars($mv['sku']) ?></code></td>
                <td class="text-right"><strong><?= (int)$mv['qty'] ?></strong></td>
                <td class="serial-cell">
                    <?php if ($mv['serials']): ?>
                        <span class="serial-preview"><?= htmlspecialchars(mb_strimwidth($mv['serials'], 0, 40, '…')) ?></span>
                        <?php if (strlen($mv['serials']) > 40): ?>
                            <button type="button" class="btn btn-xs btn-outline serial-toggle"
                                    data-full="<?= htmlspecialchars($mv['serials']) ?>">Show all</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($mv['from_wh'] ?? '—') ?></td>
                <td><?= htmlspecialchars($mv['to_wh']   ?? '—') ?></td>
                <td><span class="badge badge-channel badge-<?= $mv['channel'] ?>"><?= ucfirst($mv['channel']) ?></span></td>
                <td><?= htmlspecialchars($mv['invoice_no'] ?: '—') ?></td>
                <td><?= htmlspecialchars($mv['moved_by_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($mv['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="table-total-row">
                <td colspan="3"><strong>Total</strong></td>
                <td class="text-right"><strong><?= number_format($totalQty) ?></strong></td>
                <td colspan="7"></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
