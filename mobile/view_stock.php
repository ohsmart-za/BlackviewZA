<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'View Stock';
$activeNav = 'stock';
$showBack  = true;
$backUrl   = 'mobile/stock.php';

$products   = $pdo->query('SELECT id, sku, name FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();

$filterProduct   = (int)($_GET['product_id']   ?? 0);
$filterWarehouse = (int)($_GET['warehouse_id'] ?? 0);

$where  = ['inv.qty > 0'];
$params = [];
if ($filterProduct   > 0) { $where[] = 'p.id = :pid'; $params[':pid'] = $filterProduct; }
if ($filterWarehouse > 0) { $where[] = 'w.id = :wid'; $params[':wid'] = $filterWarehouse; }

$whereSQL  = 'WHERE ' . implode(' AND ', $where);
$stockRows = $pdo->prepare(
    "SELECT p.id AS product_id, p.sku, p.name AS product_name, w.id AS warehouse_id, w.name AS warehouse_name, inv.qty
     FROM inventory_stock inv
     JOIN products   p ON p.id = inv.product_id
     JOIN warehouses w ON w.id = inv.warehouse_id
     $whereSQL
     ORDER BY p.name ASC, w.name ASC"
);
$stockRows->execute($params);
$stockRows = $stockRows->fetchAll();

$serialMap = [];
foreach ($stockRows as $row) {
    $sStmt = $pdo->prepare("SELECT serial_no FROM stock_items WHERE product_id = :pid AND warehouse_id = :wid AND status = 'in_stock' ORDER BY serial_no ASC");
    $sStmt->execute([':pid' => $row['product_id'], ':wid' => $row['warehouse_id']]);
    $serialMap[$row['product_id'] . '_' . $row['warehouse_id']] = $sStmt->fetchAll(PDO::FETCH_COLUMN);
}

$totalUnits = array_sum(array_column($stockRows, 'qty'));

require_once __DIR__ . '/_shell.php';
?>

<!-- Filters -->
<form method="GET" action="">
    <div class="filter-row">
        <select name="product_id" class="filter-select" onchange="this.form.submit()">
            <option value="">All Products</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $filterProduct == $p['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['sku']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="warehouse_id" class="filter-select" onchange="this.form.submit()">
            <option value="">All Warehouses</option>
            <?php foreach ($warehouses as $w): ?>
            <option value="<?= $w['id'] ?>" <?= $filterWarehouse == $w['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($w['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterProduct || $filterWarehouse): ?>
        <a href="?" class="filter-apply" style="background:#F1F5F9;color:var(--text);">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div style="padding:12px 16px 4px;font-size:13px;color:var(--text-muted);">
    <?= count($stockRows) ?> line<?= count($stockRows) !== 1 ? 's' : '' ?> &middot; <?= number_format($totalUnits) ?> total units
</div>

<?php if (empty($stockRows)): ?>
<div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"><rect x="2" y="7" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2" stroke="currentColor" stroke-width="1.5"/></svg>
    <p>No stock found<?= ($filterProduct || $filterWarehouse) ? ' for the selected filter.' : '.' ?></p>
</div>
<?php else: ?>

<div style="padding:0 12px 16px;">
    <div class="card" style="overflow:hidden;margin-bottom:0;">
        <table class="stock-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Warehouse</th>
                    <th style="text-align:center;">Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stockRows as $row):
                    $key     = $row['product_id'] . '_' . $row['warehouse_id'];
                    $serials = $serialMap[$key] ?? [];
                ?>
                <tr onclick="toggleSerials('<?= $key ?>')" style="cursor:pointer;">
                    <td>
                        <div style="font-weight:600;font-size:14px;"><?= htmlspecialchars($row['product_name']) ?></div>
                        <div class="sku-tag"><?= htmlspecialchars($row['sku']) ?></div>
                    </td>
                    <td style="font-size:13px;"><?= htmlspecialchars($row['warehouse_name']) ?></td>
                    <td style="text-align:center;"><span class="qty-badge"><?= $row['qty'] ?></span></td>
                </tr>
                <?php if (!empty($serials)): ?>
                <tr id="serials-<?= $key ?>" class="serial-list-row">
                    <td colspan="3" style="padding:0 12px 10px;">
                        <div class="serial-chips-wrap">
                            <?php foreach ($serials as $sn): ?>
                            <span class="s-chip"><?= htmlspecialchars($sn) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<script>
function toggleSerials(key) {
    var row = document.getElementById('serials-' + key);
    if (row) row.classList.toggle('open');
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
