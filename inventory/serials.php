<?php
// ============================================================
// Blackview SA Portal — Serial Numbers Browser
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Serial Numbers';

// ---- Filter dropdown data ----
$filterProducts   = $pdo->query("SELECT id, sku, name FROM products WHERE is_active = 1 AND COALESCE(product_type,'physical') = 'physical' ORDER BY name")->fetchAll();
$filterWarehouses = $pdo->query('SELECT id, name   FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();
$filterStatuses   = ['in_stock', 'moved', 'sold'];

// ---- Read GET filters ----
$fProductId   = isset($_GET['product_id'])   && is_numeric($_GET['product_id'])   ? (int)$_GET['product_id']   : 0;
$fWarehouseId = isset($_GET['warehouse_id']) && is_numeric($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$fStatus      = isset($_GET['status'])       && in_array($_GET['status'], $filterStatuses, true) ? $_GET['status'] : '';
$fSearch      = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 100;

// ---- Build WHERE clauses ----
$whereParts = [];
$params     = [];

if ($fProductId > 0) {
    $whereParts[] = 'si.product_id = :product_id';
    $params[':product_id'] = $fProductId;
}
if ($fWarehouseId > 0) {
    $whereParts[] = 'si.warehouse_id = :warehouse_id';
    $params[':warehouse_id'] = $fWarehouseId;
}
if ($fStatus !== '') {
    $whereParts[] = 'si.status = :status';
    $params[':status'] = $fStatus;
}
if ($fSearch !== '') {
    $whereParts[] = 'si.serial_no LIKE :search';
    $params[':search'] = '%' . $fSearch . '%';
}

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// ---- Base SELECT (with last-movement join) ----
// We join movement_serials + stock_movements to get the most recent movement per serial
$selectSql = "
    SELECT
        si.id,
        si.serial_no,
        p.name   AS product_name,
        p.sku    AS product_sku,
        w.name   AS warehouse_name,
        si.status,
        si.created_at,
        lm.channel    AS last_channel,
        lm.invoice_no AS last_invoice
    FROM stock_items si
    JOIN products   p  ON p.id  = si.product_id
    JOIN warehouses w  ON w.id  = si.warehouse_id
    LEFT JOIN (
        SELECT
            ms.serial_no,
            sm.channel,
            sm.invoice_no,
            sm.moved_at
        FROM movement_serials ms
        JOIN stock_movements sm ON sm.id = ms.movement_id
        WHERE sm.moved_at = (
            SELECT MAX(sm2.moved_at)
            FROM movement_serials ms2
            JOIN stock_movements sm2 ON sm2.id = ms2.movement_id
            WHERE ms2.serial_no = ms.serial_no
        )
    ) lm ON lm.serial_no = si.serial_no
";

// ---- Export CSV (no pagination) ----
if (isset($_GET['export']) && $_GET['export'] === '1') {
    while (ob_get_level() > 0) ob_end_clean();

    $exportSql  = $selectSql . "\n    $whereSql\n    ORDER BY si.created_at DESC";
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($params);
    $exportRows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="serials_export_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, ['Serial No', 'Product', 'SKU', 'Warehouse', 'Status', 'Last Channel', 'Last Invoice', 'Date Added']);
    foreach ($exportRows as $row) {
        fputcsv($out, [
            $row['serial_no'],
            $row['product_name'],
            $row['product_sku'],
            $row['warehouse_name'],
            $row['status'],
            $row['last_channel']  ?? '',
            $row['last_invoice']  ?? '',
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ---- Count total rows ----
$countSql  = "SELECT COUNT(*) FROM stock_items si JOIN products p ON p.id = si.product_id JOIN warehouses w ON w.id = si.warehouse_id $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ---- Paginated data ----
$dataSql  = $selectSql . "\n    $whereSql\n    ORDER BY si.created_at DESC\n    LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $val) {
    $dataStmt->bindValue($key, $val);
}
$dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$dataStmt->execute();
$serials = $dataStmt->fetchAll();

// ---- Build export URL (carries current filters) ----
$exportParams = array_filter([
    'export'       => '1',
    'product_id'   => $fProductId   ?: null,
    'warehouse_id' => $fWarehouseId ?: null,
    'status'       => $fStatus      ?: null,
    'search'       => $fSearch      ?: null,
]);
$exportUrl = '?' . http_build_query($exportParams);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Serial Numbers</h2>
    <p class="page-subtitle">Browse and search all serial numbers in the system.</p>
</div>

<!-- ---- Filter bar ---- -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
        <form method="GET" action="" class="form-row" style="align-items:flex-end; gap:.75rem;">
            <div class="form-group" style="flex:0 0 220px; margin-bottom:0;">
                <label class="form-label">Product</label>
                <select name="product_id" class="form-control form-select">
                    <option value="">— All products —</option>
                    <?php foreach ($filterProducts as $fp): ?>
                        <option value="<?= $fp['id'] ?>" <?= $fProductId === (int)$fp['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($fp['sku']) ?>] <?= htmlspecialchars($fp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:0 0 180px; margin-bottom:0;">
                <label class="form-label">Warehouse</label>
                <select name="warehouse_id" class="form-control form-select">
                    <option value="">— All warehouses —</option>
                    <?php foreach ($filterWarehouses as $fw): ?>
                        <option value="<?= $fw['id'] ?>" <?= $fWarehouseId === (int)$fw['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fw['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:0 0 140px; margin-bottom:0;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control form-select">
                    <option value="">— All statuses —</option>
                    <?php foreach ($filterStatuses as $fs): ?>
                        <option value="<?= $fs ?>" <?= $fStatus === $fs ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $fs)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:1; min-width:160px; margin-bottom:0;">
                <label class="form-label">Search Serial No.</label>
                <input type="text" name="search" class="form-control"
                       value="<?= htmlspecialchars($fSearch) ?>"
                       placeholder="Partial serial number…">
            </div>

            <div style="display:flex; gap:.5rem; margin-bottom:0;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="<?= BASE_URL ?>/inventory/serials.php" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ---- Results card ---- -->
<div class="card">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
        <h3 class="card-title">
            Serial Numbers
            <span class="text-muted" style="font-size:.85rem; font-weight:400;">
                — <?= number_format($totalRows) ?> record<?= $totalRows !== 1 ? 's' : '' ?> found
            </span>
        </h3>
        <a href="<?= $exportUrl ?>" class="btn btn-sm btn-outline">Export CSV</a>
    </div>

    <!-- Pagination info -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:.6rem 1.25rem; border-bottom:1px solid var(--color-border); font-size:.85rem; color:var(--color-muted);">
        Page <?= $page ?> of <?= $totalPages ?>
        (showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?>
        of <?= number_format($totalRows) ?>)
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Serial No</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Warehouse</th>
                    <th>Status</th>
                    <th>Channel (last mvt)</th>
                    <th>Invoice (last mvt)</th>
                    <th>Date Added</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($serials as $row): ?>
                <tr>
                    <td><code><?= htmlspecialchars($row['serial_no']) ?></code></td>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><code><?= htmlspecialchars($row['product_sku']) ?></code></td>
                    <td><?= htmlspecialchars($row['warehouse_name']) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                            <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                        </span>
                    </td>
                    <td><?= $row['last_channel']  ? htmlspecialchars(ucfirst($row['last_channel']))  : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $row['last_invoice']  ? '<code>' . htmlspecialchars($row['last_invoice']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($serials)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:2rem;">No serial numbers found matching your filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination links -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:.75rem 1.25rem; display:flex; gap:.4rem; flex-wrap:wrap; border-top:1px solid var(--color-border);">
        <?php
        // Build base query string without 'page'
        $baseQuery = array_filter([
            'product_id'   => $fProductId   ?: null,
            'warehouse_id' => $fWarehouseId ?: null,
            'status'       => $fStatus      ?: null,
            'search'       => $fSearch      ?: null,
        ]);

        $prevPage = $page - 1;
        $nextPage = $page + 1;
        ?>
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => $prevPage])) ?>"
               class="btn btn-sm btn-outline">« Prev</a>
        <?php endif; ?>

        <?php
        // Show a window of page links around current page
        $windowStart = max(1, $page - 3);
        $windowEnd   = min($totalPages, $page + 3);
        for ($pg = $windowStart; $pg <= $windowEnd; $pg++):
        ?>
            <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => $pg])) ?>"
               class="btn btn-sm <?= $pg === $page ? 'btn-primary' : 'btn-outline' ?>">
                <?= $pg ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => $nextPage])) ?>"
               class="btn btn-sm btn-outline">Next »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
