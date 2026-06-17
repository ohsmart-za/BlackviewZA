<?php
// ============================================================
// Blackview SA Portal — Executive Stock Report
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Executive Stock Report';

// ============================================================
// Filters
// ============================================================
$dateFrom    = $_GET['date_from']   ?? date('Y-m-01');          // first of this month
$dateTo      = $_GET['date_to']     ?? date('Y-m-d');
$whFilter    = isset($_GET['warehouse_id']) && is_numeric($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$catFilter   = trim($_GET['category'] ?? '');
$search      = trim($_GET['search'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// ============================================================
// Support data
// ============================================================
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();
$categories = $pdo->query(
    "SELECT DISTINCT category FROM products WHERE category != '' AND COALESCE(product_type,'physical') = 'physical' ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// Header KPI stats
// ============================================================

// Total units currently in stock — physical products only
$kpiStockSql = $whFilter
    ? "SELECT COALESCE(SUM(inv_s.qty),0) FROM inventory_stock inv_s JOIN products kp ON kp.id = inv_s.product_id WHERE inv_s.warehouse_id = :wh AND COALESCE(kp.product_type,'physical') = 'physical'"
    : "SELECT COALESCE(SUM(inv_s.qty),0) FROM inventory_stock inv_s JOIN products kp ON kp.id = inv_s.product_id WHERE COALESCE(kp.product_type,'physical') = 'physical'";
$kpiStockQ = $pdo->prepare($kpiStockSql);
$kpiStockQ->execute($whFilter ? [':wh' => $whFilter] : []);
$totalUnitsInStock = (int)$kpiStockQ->fetchColumn();

// Total units sold in period (invoice_items rows in date range)
$soldSql = "SELECT COALESCE(SUM(ii.qty),0)
            FROM invoice_items ii
            JOIN invoices inv ON inv.id = ii.invoice_id
            WHERE inv.created_at BETWEEN :df AND :dt";
$soldParams = [':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];
if ($catFilter !== '') {
    $soldSql .= " AND ii.product_id IN (SELECT id FROM products WHERE category = :cat)";
    $soldParams[':cat'] = $catFilter;
}
$soldQ = $pdo->prepare($soldSql);
$soldQ->execute($soldParams);
$totalUnitsSold = (int)$soldQ->fetchColumn();

// Total revenue (incl. VAT) in period
$revSql = "SELECT COALESCE(SUM(inv.total),0)
           FROM invoices inv
           WHERE inv.created_at BETWEEN :df AND :dt";
$revParams = [':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];
if ($catFilter !== '') {
    $revSql .= " AND inv.id IN (
        SELECT DISTINCT invoice_id FROM invoice_items ii2
        JOIN products p2 ON p2.id = ii2.product_id
        WHERE p2.category = :cat
    )";
    $revParams[':cat'] = $catFilter;
}
$revQ = $pdo->prepare($revSql);
$revQ->execute($revParams);
$totalRevenue = (float)$revQ->fetchColumn();

// Gross profit in period (revenue - cost of sold items)
$gpSql = "SELECT COALESCE(SUM(ii.qty * p.cost_price), 0)
          FROM invoice_items ii
          JOIN invoices inv ON inv.id = ii.invoice_id
          JOIN products p ON p.id = ii.product_id
          WHERE inv.created_at BETWEEN :df AND :dt";
$gpParams = [':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];
if ($catFilter !== '') {
    $gpSql .= " AND p.category = :cat";
    $gpParams[':cat'] = $catFilter;
}
$gpQ = $pdo->prepare($gpSql);
$gpQ->execute($gpParams);
$totalCost   = (float)$gpQ->fetchColumn();
$grossProfit = $totalRevenue - $totalCost;

// ============================================================
// Per-product breakdown
// ============================================================
$productSql = "
    SELECT
        p.id,
        p.sku,
        p.name,
        p.category,
        p.brand,
        p.selling_price,
        p.cost_price,
        COALESCE(p.is_serialised, 1) AS is_serialised,

        -- Units in stock (per warehouse filter)
        COALESCE(
            (SELECT SUM(inv2.qty)
             FROM inventory_stock inv2
             WHERE inv2.product_id = p.id
             " . ($whFilter ? "AND inv2.warehouse_id = :wh_sub" : "") . "
            ), 0
        ) AS units_in_stock,

        -- Units sold in period
        COALESCE(
            (SELECT SUM(ii2.qty)
             FROM invoice_items ii2
             JOIN invoices inv3 ON inv3.id = ii2.invoice_id
             WHERE ii2.product_id = p.id
               AND inv3.created_at BETWEEN :df_sub AND :dt_sub
            ), 0
        ) AS units_sold,

        -- Revenue in period
        COALESCE(
            (SELECT SUM(ii3.line_total)
             FROM invoice_items ii3
             JOIN invoices inv4 ON inv4.id = ii3.invoice_id
             WHERE ii3.product_id = p.id
               AND inv4.created_at BETWEEN :df_sub2 AND :dt_sub2
            ), 0
        ) AS revenue,

        -- Stock value at cost (units_in_stock × cost_price)
        COALESCE(
            (SELECT SUM(inv5.qty)
             FROM inventory_stock inv5
             WHERE inv5.product_id = p.id
             " . ($whFilter ? "AND inv5.warehouse_id = :wh_sub3" : "") . "
            ), 0
        ) * COALESCE(p.cost_price, 0) AS stock_value

    FROM products p
    WHERE p.is_active = 1
      AND COALESCE(p.product_type, 'physical') = 'physical'
";

$productParams = [
    ':df_sub'  => $dateFrom . ' 00:00:00',
    ':dt_sub'  => $dateTo   . ' 23:59:59',
    ':df_sub2' => $dateFrom . ' 00:00:00',
    ':dt_sub2' => $dateTo   . ' 23:59:59',
];
if ($whFilter) {
    $productParams[':wh_sub']  = $whFilter;
    $productParams[':wh_sub3'] = $whFilter;
}
if ($catFilter !== '') {
    $productSql .= " AND p.category = :cat";
    $productParams[':cat'] = $catFilter;
}
if ($search !== '') {
    $productSql .= " AND (p.name LIKE :search OR p.sku LIKE :search2)";
    $productParams[':search']  = '%' . $search . '%';
    $productParams[':search2'] = '%' . $search . '%';
}

// When a warehouse is selected, only show products that actually have stock there
if ($whFilter) {
    $productSql .= " AND EXISTS (
        SELECT 1 FROM inventory_stock inv_ex
        WHERE inv_ex.product_id = p.id
          AND inv_ex.warehouse_id = :wh_exists
          AND inv_ex.qty > 0
    )";
    $productParams[':wh_exists'] = $whFilter;
}

$productSql .= " ORDER BY units_in_stock DESC, p.name ASC";

$productQ = $pdo->prepare($productSql);
$productQ->execute($productParams);
$productRows = $productQ->fetchAll();

// ============================================================
// Per-warehouse stock breakdown (for the detail panel)
// ============================================================
// Load per-product per-warehouse counts in one query
$whStockQ = $pdo->query(
    "SELECT inv.product_id, inv.warehouse_id, w.name AS warehouse_name, inv.qty
     FROM inventory_stock inv
     JOIN warehouses w ON w.id = inv.warehouse_id
     WHERE inv.qty > 0
     ORDER BY w.name ASC"
);
$whStockRows = $whStockQ->fetchAll();
$whStockMap  = []; // [product_id][warehouse_name] = qty
foreach ($whStockRows as $row) {
    $whStockMap[$row['product_id']][$row['warehouse_name']] = (int)$row['qty'];
}

// Collect warehouse names — if filtered, limit to just the selected warehouse
if ($whFilter) {
    $whName = '';
    foreach ($warehouses as $wh) {
        if ((int)$wh['id'] === $whFilter) { $whName = $wh['name']; break; }
    }
    $allWarehouseNames = $whName !== '' ? [$whName] : [];
} else {
    $allWarehouseNames = array_unique(array_column($whStockRows, 'warehouse_name'));
    sort($allWarehouseNames);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 class="page-title">Executive Stock Report</h2>
        <p class="page-subtitle">Stock levels and sales performance by product.</p>
    </div>
    <button type="button" class="btn btn-outline" onclick="window.print()">Print / Export</button>
</div>

<!-- ============================================================ -->
<!-- Filters                                                        -->
<!-- ============================================================ -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-body" style="padding-top:.75rem;padding-bottom:.75rem;">
        <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">
            <div class="form-group" style="margin:0;min-width:130px;">
                <label class="form-label" style="font-size:.78rem;">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="form-group" style="margin:0;min-width:130px;">
                <label class="form-label" style="font-size:.78rem;">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="form-group" style="margin:0;min-width:150px;">
                <label class="form-label" style="font-size:.78rem;">Warehouse</label>
                <select name="warehouse_id" class="form-control form-control-sm">
                    <option value="0">All Warehouses</option>
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>" <?= $whFilter === (int)$wh['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($wh['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:150px;">
                <label class="form-label" style="font-size:.78rem;">Category</label>
                <select name="category" class="form-control form-control-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:180px;">
                <label class="form-label" style="font-size:.78rem;">Search Product</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name or SKU…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
                <a href="?" class="btn btn-outline btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- KPI Cards                                                      -->
<!-- ============================================================ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem;">

    <div class="card" style="text-align:center;padding:1rem;">
        <div style="font-size:2rem;font-weight:700;color:var(--color-primary);">
            <?= number_format($totalUnitsInStock) ?>
        </div>
        <div style="font-size:.8rem;color:var(--color-muted);margin-top:.25rem;">Units In Stock</div>
        <?php if ($whFilter): ?>
        <div style="font-size:.72rem;color:var(--color-muted);">
            (<?= htmlspecialchars(current(array_filter($warehouses, fn($w) => $w['id'] === $whFilter))['name'] ?? '') ?>)
        </div>
        <?php endif; ?>
    </div>

    <div class="card" style="text-align:center;padding:1rem;">
        <div style="font-size:2rem;font-weight:700;color:#B45309;">
            <?= number_format($totalUnitsSold) ?>
        </div>
        <div style="font-size:.8rem;color:var(--color-muted);margin-top:.25rem;">Units Sold</div>
        <div style="font-size:.72rem;color:var(--color-muted);">
            <?= date('d M', strtotime($dateFrom)) ?> – <?= date('d M Y', strtotime($dateTo)) ?>
        </div>
    </div>

    <div class="card" style="text-align:center;padding:1rem;">
        <div style="font-size:2rem;font-weight:700;color:#166534;">
            R <?= number_format($totalRevenue, 0) ?>
        </div>
        <div style="font-size:.8rem;color:var(--color-muted);margin-top:.25rem;">Revenue (incl. VAT)</div>
        <div style="font-size:.72rem;color:var(--color-muted);">
            <?= date('d M', strtotime($dateFrom)) ?> – <?= date('d M Y', strtotime($dateTo)) ?>
        </div>
    </div>

    <div class="card" style="text-align:center;padding:1rem;">
        <div style="font-size:2rem;font-weight:700;color:<?= $grossProfit >= 0 ? '#166534' : '#DC2626' ?>;">
            R <?= number_format($grossProfit, 0) ?>
        </div>
        <div style="font-size:.8rem;color:var(--color-muted);margin-top:.25rem;">Gross Profit</div>
        <div style="font-size:.72rem;color:var(--color-muted);">Revenue minus cost of goods</div>
    </div>

    <div class="card" style="text-align:center;padding:1rem;">
        <div style="font-size:2rem;font-weight:700;color:#1D4ED8;">
            R <?= number_format(array_sum(array_column($productRows, 'stock_value')), 0) ?>
        </div>
        <div style="font-size:.8rem;color:var(--color-muted);margin-top:.25rem;">Stock Value (at cost)</div>
        <div style="font-size:.72rem;color:var(--color-muted);">
            <?= $whFilter ? 'Selected warehouse' : 'All warehouses' ?>
        </div>
    </div>

</div>

<!-- ============================================================ -->
<!-- Product Table                                                  -->
<!-- ============================================================ -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">Product Breakdown</h3>
        <span style="font-size:.82rem;color:var(--color-muted);">
            <?= count($productRows) ?> product(s) &nbsp;|&nbsp;
            Period: <?= date('d M Y', strtotime($dateFrom)) ?> – <?= date('d M Y', strtotime($dateTo)) ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="exec-table">
            <thead>
                <tr>
                    <th style="min-width:80px;">SKU</th>
                    <th>Product</th>
                    <th>Category</th>
                    <?php foreach ($allWarehouseNames as $whn): ?>
                    <th class="text-right" style="white-space:nowrap;"><?= htmlspecialchars($whn) ?></th>
                    <?php endforeach; ?>
                    <th class="text-right">Total Stock</th>
                    <th class="text-right">Stock Value</th>
                    <th class="text-right">Units Sold</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">GP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productRows as $row):
                    $gp      = (float)$row['revenue'] - ((float)$row['cost_price'] * (int)$row['units_sold']);
                    $gpColor = $gp >= 0 ? '#166534' : '#DC2626';
                ?>
                <tr>
                    <td><code style="font-size:.8rem;"><?= htmlspecialchars($row['sku']) ?></code></td>
                    <td>
                        <?= htmlspecialchars($row['name']) ?>
                        <?php if (!$row['is_serialised']): ?>
                            <span style="font-size:.72rem;background:#FEF9C3;color:#854D0E;padding:1px 5px;border-radius:3px;vertical-align:middle;">bulk</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.83rem;color:var(--color-muted);"><?= htmlspecialchars($row['category']) ?></td>
                    <?php foreach ($allWarehouseNames as $whn): ?>
                    <td class="text-right">
                        <?php
                        $qty = $whStockMap[$row['id']][$whn] ?? 0;
                        echo $qty > 0 ? '<strong>' . number_format($qty) . '</strong>' : '<span style="color:var(--color-muted);">—</span>';
                        ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="text-right">
                        <strong style="<?= (int)$row['units_in_stock'] === 0 ? 'color:#DC2626;' : '' ?>">
                            <?= number_format((int)$row['units_in_stock']) ?>
                        </strong>
                    </td>
                    <td class="text-right">
                        <?php if ((float)$row['stock_value'] > 0): ?>
                            R <?= number_format((float)$row['stock_value'], 2) ?>
                        <?php elseif ((float)$row['cost_price'] == 0): ?>
                            <span style="color:var(--color-muted);font-size:.78rem;" title="No cost price set">—</span>
                        <?php else: ?>
                            <span style="color:var(--color-muted);">R 0.00</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?= number_format((int)$row['units_sold']) ?></td>
                    <td class="text-right">
                        <?= (float)$row['revenue'] > 0 ? 'R ' . number_format((float)$row['revenue'], 2) : '<span style="color:var(--color-muted);">—</span>' ?>
                    </td>
                    <td class="text-right" style="color:<?= $gpColor ?>;font-weight:<?= $row['units_sold'] > 0 ? '600' : '400' ?>;">
                        <?= $row['units_sold'] > 0 ? 'R ' . number_format($gp, 2) : '<span style="color:var(--color-muted);">—</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($productRows)): ?>
                <tr><td colspan="<?= 8 + count($allWarehouseNames) ?>" class="text-center text-muted">No products found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($productRows)): ?>
            <tfoot>
                <tr style="background:var(--color-bg-alt);font-weight:700;">
                    <td colspan="<?= 3 + count($allWarehouseNames) ?>">Totals</td>
                    <td class="text-right"><?= number_format(array_sum(array_column($productRows, 'units_in_stock'))) ?></td>
                    <td class="text-right">R <?= number_format(array_sum(array_column($productRows, 'stock_value')), 2) ?></td>
                    <td class="text-right"><?= number_format(array_sum(array_column($productRows, 'units_sold'))) ?></td>
                    <td class="text-right">R <?= number_format(array_sum(array_column($productRows, 'revenue')), 2) ?></td>
                    <td class="text-right" style="color:<?= $grossProfit >= 0 ? '#166534' : '#DC2626' ?>;">
                        R <?= number_format($grossProfit, 2) ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<style>
@media print {
    .page-header button, .card form, nav, .sidebar, .topbar { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc !important; }
    body { font-size: 12px; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
