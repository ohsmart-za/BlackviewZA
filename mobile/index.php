<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$user = currentUser();

// Stats
try {
    $totalStock = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM inventory_stock")->fetchColumn();
} catch (Throwable $e) { $totalStock = 0; }

try {
    $todaySales = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE DATE(created_at)=CURDATE()")->fetchColumn();
} catch (Throwable $e) { $todaySales = 0; }

try {
    $openPOs = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('cancelled','received')")->fetchColumn();
} catch (Throwable $e) { $openPOs = 0; }

try {
    $totalWarehouses = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE is_active=1")->fetchColumn();
} catch (Throwable $e) { $totalWarehouses = 0; }

$pageTitle = 'Blackview';
$activeNav = 'home';
$showBack  = false;

require_once __DIR__ . '/_shell.php';
?>

<div class="page-pad">

    <!-- Greeting -->
    <?php $firstName = explode(' ', $user['name'])[0]; ?>
    <div class="greeting">Hi, <?= htmlspecialchars($firstName) ?> 👋</div>
    <div class="greeting-sub">Here's what's happening today.</div>

    <!-- Stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-val"><?= number_format($totalStock) ?></div>
            <div class="stat-lbl">Units in Stock</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $todaySales ?></div>
            <div class="stat-lbl">Sales Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $openPOs ?></div>
            <div class="stat-lbl">Open POs</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $totalWarehouses ?></div>
            <div class="stat-lbl">Warehouses</div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="section-title">Quick Actions</div>
    <div class="action-grid">
        <a href="<?= BASE_URL ?>/mobile/pos.php" class="action-btn">
            <div class="action-icon action-icon-green">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <rect x="2" y="6" width="20" height="14" rx="2" stroke="#16A34A" stroke-width="2"/>
                    <path d="M16 2v4M8 2v4M2 10h20" stroke="#16A34A" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            Point of Sale
        </a>
        <a href="<?= BASE_URL ?>/mobile/scan_in.php" class="action-btn">
            <div class="action-icon action-icon-blue">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="3" width="5" height="5" rx="1" stroke="#2563EB" stroke-width="2"/>
                    <rect x="16" y="3" width="5" height="5" rx="1" stroke="#2563EB" stroke-width="2"/>
                    <rect x="3" y="16" width="5" height="5" rx="1" stroke="#2563EB" stroke-width="2"/>
                    <path d="M16 16h5v5" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            Scan In
        </a>
        <a href="<?= BASE_URL ?>/mobile/invoices.php" class="action-btn">
            <div class="action-icon" style="background:#F0F9FF;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="#0891B2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="#0891B2" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            Invoices
        </a>
        <a href="<?= BASE_URL ?>/mobile/crm.php" class="action-btn">
            <div class="action-icon" style="background:#FDF4FF;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="#9333EA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="9" cy="7" r="4" stroke="#9333EA" stroke-width="2"/>
                    <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="#9333EA" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            Customers
        </a>
    </div>

    <!-- Secondary actions row -->
    <div class="action-grid">
        <a href="<?= BASE_URL ?>/mobile/move_stock.php" class="action-btn">
            <div class="action-icon action-icon-purple">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M5 12h14M13 6l6 6-6 6" stroke="#7C3AED" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            Move Stock
        </a>
        <a href="<?= BASE_URL ?>/mobile/take_out.php" class="action-btn">
            <div class="action-icon action-icon-red">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M9 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-4M15 3h6v6M10 14L21 3" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            Take Out
        </a>
        <a href="<?= BASE_URL ?>/mobile/quotes.php" class="action-btn">
            <div class="action-icon action-icon-amber">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" stroke="#D97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            Quotes
        </a>
        <a href="<?= BASE_URL ?>/mobile/serials.php" class="action-btn">
            <div class="action-icon action-icon-green">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <circle cx="11" cy="11" r="8" stroke="#16A34A" stroke-width="2"/>
                    <path d="M21 21l-4.35-4.35" stroke="#16A34A" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            Serials
        </a>
    </div>

    <!-- View stock card -->
    <a href="<?= BASE_URL ?>/mobile/stock.php" class="card" style="display:block;text-decoration:none;">
        <div class="card-body" style="display:flex;align-items:center;gap:14px;">
            <div class="action-icon action-icon-amber" style="width:44px;height:44px;flex-shrink:0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" stroke="#D97706" stroke-width="2"/>
                    <path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="#D97706" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <div style="flex:1;">
                <div style="font-weight:600;font-size:15px;">Stock Hub</div>
                <div style="font-size:13px;color:var(--text-muted);"><?= number_format($totalStock) ?> units · <?= $totalWarehouses ?> warehouse<?= $totalWarehouses !== 1 ? 's' : '' ?></div>
            </div>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M9 18l6-6-6-6" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
    </a>

</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
