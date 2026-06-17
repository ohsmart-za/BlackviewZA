<?php
// ============================================================
// Blackview SA Portal — View Stock Levels
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'View Stock';

$products   = $pdo->query("SELECT id, sku, name FROM products WHERE is_active = 1 AND COALESCE(product_type,'physical') = 'physical' ORDER BY name")->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();

// Filters
$filterProduct   = (int)($_GET['product_id']   ?? 0);
$filterWarehouse = (int)($_GET['warehouse_id'] ?? 0);

// Build query
$where  = ["inv.qty > 0", "COALESCE(p.product_type,'physical') = 'physical'"];
$params = [];

if ($filterProduct > 0) {
    $where[]              = 'p.id = :pid';
    $params[':pid']       = $filterProduct;
}
if ($filterWarehouse > 0) {
    $where[]              = 'w.id = :wid';
    $params[':wid']       = $filterWarehouse;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        p.id   AS product_id,
        p.sku,
        p.name AS product_name,
        p.brand,
        w.id   AS warehouse_id,
        w.name AS warehouse_name,
        inv.qty
    FROM inventory_stock inv
    JOIN products   p ON p.id = inv.product_id
    JOIN warehouses w ON w.id = inv.warehouse_id
    $whereSQL
    ORDER BY p.name ASC, w.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stockRows = $stmt->fetchAll();

// Fetch serials for each product+warehouse combo (for expandable)
$serialMap = [];
if (!empty($stockRows)) {
    foreach ($stockRows as $row) {
        $sStmt = $pdo->prepare(
            "SELECT serial_no FROM stock_items
             WHERE product_id = :pid AND warehouse_id = :wid AND status = 'in_stock'
             ORDER BY serial_no ASC"
        );
        $sStmt->execute([':pid' => $row['product_id'], ':wid' => $row['warehouse_id']]);
        $serialMap[$row['product_id'] . '_' . $row['warehouse_id']] = $sStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Grand total
$totalUnits = array_sum(array_column($stockRows, 'qty'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">View Stock</h2>
    <p class="page-subtitle">Current stock levels per warehouse.</p>
</div>

<!-- Filter Form -->
<div class="card filter-card">
    <form method="GET" action="" class="filter-form">
        <div class="form-row align-end">
            <div class="form-group form-group--third">
                <label for="product_id" class="form-label">Filter by Product</label>
                <select id="product_id" name="product_id" class="form-control form-select">
                    <option value="">All Products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProduct == $p['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group form-group--third">
                <label for="warehouse_id" class="form-label">Filter by Warehouse</label>
                <select id="warehouse_id" name="warehouse_id" class="form-control form-select">
                    <option value="">All Warehouses</option>
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['id'] ?>" <?= $filterWarehouse == $w['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($w['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group form-group--auto">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="<?= BASE_URL ?>/inventory/view_stock.php" class="btn btn-outline">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="results-summary">
    Showing <strong><?= count($stockRows) ?></strong> row(s) &mdash;
    Total units: <strong><?= number_format($totalUnits) ?></strong>
</div>

<?php if (empty($stockRows)): ?>
    <div class="alert alert-info">No stock found matching your filters.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Brand</th>
                <th>Warehouse</th>
                <th class="text-right">Qty In Stock</th>
                <th>Serial Numbers</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stockRows as $row):
                $key     = $row['product_id'] . '_' . $row['warehouse_id'];
                $serials = $serialMap[$key] ?? [];
                $serialId = 'serials_' . $key;
            ?>
            <tr>
                <td><?= htmlspecialchars($row['product_name']) ?></td>
                <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                <td><?= htmlspecialchars($row['brand']) ?></td>
                <td><?= htmlspecialchars($row['warehouse_name']) ?></td>
                <td class="text-right"><strong><?= number_format($row['qty']) ?></strong></td>
                <td>
                    <?php if (!empty($serials)): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline serial-toggle"
                            data-target="<?= $serialId ?>">
                            Show <?= count($serials) ?> serial(s)
                        </button>
                        <div id="<?= $serialId ?>" class="serial-list-expand" style="display:none;">
                            <?php foreach ($serials as $sn): ?>
                                <span class="serial-chip"><?= htmlspecialchars($sn) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">No serials</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="table-total-row">
                <td colspan="4"><strong>Total</strong></td>
                <td class="text-right"><strong><?= number_format($totalUnits) ?></strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
