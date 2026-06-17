<?php
// ============================================================
// Blackview SA Portal — Dashboard
// ============================================================

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

requireLogin();

// Mobile redirect — skip if ?desktop=1 is set (user explicitly wants desktop)
if (empty($_GET['desktop']) && empty($_SESSION['force_desktop'])) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
        // Root-relative so it works from any host (localhost, LAN IP, domain)
        $mobilePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/mobile/index.php';
        header('Location: ' . $mobilePath);
        exit;
    }
}

$pdo       = getDB();
$user      = currentUser();
$pageTitle = 'Dashboard';

// --- Stat: Total products ---
$stmtProducts = $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1');
$totalProducts = (int)$stmtProducts->fetchColumn();

// --- Stat: Total units in stock ---
$stmtUnits = $pdo->query("SELECT COALESCE(SUM(qty), 0) FROM inventory_stock");
$totalUnits = (int)$stmtUnits->fetchColumn();

// --- Stat: Active warehouses ---
$stmtWH = $pdo->query('SELECT COUNT(*) FROM warehouses WHERE is_active = 1');
$totalWarehouses = (int)$stmtWH->fetchColumn();

// --- Stat: Movements this month ---
$stmtMov = $pdo->query(
    "SELECT COUNT(*) FROM stock_movements
     WHERE YEAR(moved_at) = YEAR(NOW()) AND MONTH(moved_at) = MONTH(NOW())"
);
$movementsThisMonth = (int)$stmtMov->fetchColumn();

// --- Recent movements (last 10) ---
$stmtRecent = $pdo->query(
    "SELECT sm.id, sm.moved_at, sm.qty, sm.channel, sm.invoice_no,
            p.name AS product_name, p.sku,
            fw.name AS from_wh, tw.name AS to_wh,
            u.name AS moved_by_name
     FROM stock_movements sm
     JOIN products   p  ON p.id  = sm.product_id
     JOIN users      u  ON u.id  = sm.moved_by
     LEFT JOIN warehouses fw ON fw.id = sm.from_warehouse_id
     LEFT JOIN warehouses tw ON tw.id = sm.to_warehouse_id
     ORDER BY sm.moved_at DESC
     LIMIT 10"
);
$recentMovements = $stmtRecent->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-title">Dashboard</h2>
        <p class="page-subtitle">Welcome back, <?= htmlspecialchars($user['name']) ?> — <?= date('l, d F Y') ?></p>
    </div>
    <a href="<?= BASE_URL ?>/mobile/index.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#EFF6FF;color:#2563EB;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="5" y="2" width="14" height="20" rx="3" stroke="currentColor" stroke-width="2"/><line x1="9" y1="18" x2="15" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Mobile App
    </a>
</div>

<?php if (isset($_GET['denied'])): ?>
    <div class="alert alert-error">Access denied. You do not have permission to view that page.</div>
<?php endif; ?>

<!-- ============================================================
     STAT CARDS
     ============================================================ -->
<div class="stat-grid">

    <div class="stat-card stat-card--blue">
        <div class="stat-card-icon icon-box"></div>
        <div class="stat-card-body">
            <span class="stat-card-value"><?= number_format($totalProducts) ?></span>
            <span class="stat-card-label">Total Products</span>
        </div>
    </div>

    <div class="stat-card stat-card--teal">
        <div class="stat-card-icon icon-layers"></div>
        <div class="stat-card-body">
            <span class="stat-card-value"><?= number_format($totalUnits) ?></span>
            <span class="stat-card-label">Units In Stock</span>
        </div>
    </div>

    <div class="stat-card stat-card--navy">
        <div class="stat-card-icon icon-warehouse"></div>
        <div class="stat-card-body">
            <span class="stat-card-value"><?= number_format($totalWarehouses) ?></span>
            <span class="stat-card-label">Active Warehouses</span>
        </div>
    </div>

    <div class="stat-card stat-card--accent">
        <div class="stat-card-icon icon-chart"></div>
        <div class="stat-card-body">
            <span class="stat-card-value"><?= number_format($movementsThisMonth) ?></span>
            <span class="stat-card-label">Movements This Month</span>
        </div>
    </div>

</div><!-- /.stat-grid -->

<!-- ============================================================
     QUICK ACTIONS
     ============================================================ -->
<div class="section-header">
    <h3 class="section-title">Quick Actions</h3>
</div>
<div class="quick-actions">
    <a href="<?= BASE_URL ?>/inventory/scan_in.php"   class="btn btn-primary">Scan In Stock</a>
    <a href="<?= BASE_URL ?>/inventory/move_stock.php" class="btn btn-secondary">Move Stock</a>
    <a href="<?= BASE_URL ?>/inventory/view_stock.php" class="btn btn-outline">View Stock</a>
    <a href="<?= BASE_URL ?>/reports/stock_movements.php" class="btn btn-outline">Reports</a>
</div>

<!-- ============================================================
     RECENT STOCK MOVEMENTS
     ============================================================ -->
<div class="section-header" style="margin-top:2rem;">
    <h3 class="section-title">Recent Stock Movements</h3>
    <a href="<?= BASE_URL ?>/reports/stock_movements.php" class="btn btn-sm btn-outline">View All</a>
</div>

<?php if (empty($recentMovements)): ?>
    <div class="alert alert-info">No stock movements recorded yet.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>From</th>
                <th>To</th>
                <th>Channel</th>
                <th>Invoice</th>
                <th>Moved By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentMovements as $mv): ?>
            <tr>
                <td><?= date('d M Y H:i', strtotime($mv['moved_at'])) ?></td>
                <td><?= htmlspecialchars($mv['product_name']) ?></td>
                <td><code><?= htmlspecialchars($mv['sku']) ?></code></td>
                <td><strong><?= (int)$mv['qty'] ?></strong></td>
                <td><?= htmlspecialchars($mv['from_wh'] ?? '—') ?></td>
                <td><?= htmlspecialchars($mv['to_wh']   ?? '—') ?></td>
                <td><span class="badge badge-channel badge-<?= $mv['channel'] ?>"><?= ucfirst($mv['channel']) ?></span></td>
                <td><?= htmlspecialchars($mv['invoice_no'] ?: '—') ?></td>
                <td><?= htmlspecialchars($mv['moved_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
