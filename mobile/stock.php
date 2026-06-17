<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Stock';
$activeNav = 'stock';
$showBack  = false;

// Quick stats
try {
    $totalUnits = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM inventory_stock")->fetchColumn();
} catch (Throwable $e) { $totalUnits = 0; }
try {
    $todayScanIns = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='scan_in' AND DATE(created_at)=CURDATE()")->fetchColumn();
} catch (Throwable $e) { $todayScanIns = 0; }

require_once __DIR__ . '/_shell.php';
?>

<div class="page-pad" style="padding-bottom:8px;">
    <div class="section-title">Stock Operations</div>
</div>

<div class="hub-list" style="margin:0 16px 16px;">
    <a href="<?= BASE_URL ?>/mobile/scan_in.php" class="hub-item">
        <div class="hub-item-icon action-icon-blue">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="3" width="5" height="5" rx="1" stroke="#2563EB" stroke-width="2"/>
                <rect x="16" y="3" width="5" height="5" rx="1" stroke="#2563EB" stroke-width="2"/>
                <rect x="3" y="16" width="5" height="5" rx="1" stroke="#2563EB" stroke-width="2"/>
                <path d="M16 16h5v5" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="hub-item-body">
            <div class="hub-item-label">Scan In</div>
            <div class="hub-item-desc">Receive stock with serial numbers</div>
        </div>
        <svg class="hub-item-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <a href="<?= BASE_URL ?>/mobile/move_stock.php" class="hub-item">
        <div class="hub-item-icon action-icon-purple">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                <path d="M5 12h14M13 6l6 6-6 6" stroke="#7C3AED" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="hub-item-body">
            <div class="hub-item-label">Move Stock</div>
            <div class="hub-item-desc">Transfer between warehouses</div>
        </div>
        <svg class="hub-item-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <a href="<?= BASE_URL ?>/mobile/take_out.php" class="hub-item">
        <div class="hub-item-icon action-icon-red">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                <path d="M9 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-4M15 3h6v6M10 14L21 3" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="hub-item-body">
            <div class="hub-item-label">Take Out</div>
            <div class="hub-item-desc">Mark serials as removed / written off</div>
        </div>
        <svg class="hub-item-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <a href="<?= BASE_URL ?>/mobile/view_stock.php" class="hub-item">
        <div class="hub-item-icon action-icon-amber">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" stroke="#D97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="#D97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="hub-item-body">
            <div class="hub-item-label">View Stock Levels</div>
            <div class="hub-item-desc"><?= number_format($totalUnits) ?> units across all warehouses</div>
        </div>
        <svg class="hub-item-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <a href="<?= BASE_URL ?>/mobile/serials.php" class="hub-item">
        <div class="hub-item-icon action-icon-green">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                <circle cx="11" cy="11" r="8" stroke="#16A34A" stroke-width="2"/>
                <path d="M21 21l-4.35-4.35" stroke="#16A34A" stroke-width="2" stroke-linecap="round"/>
                <path d="M8 11h6M11 8v6" stroke="#16A34A" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="hub-item-body">
            <div class="hub-item-label">Serial Lookup</div>
            <div class="hub-item-desc">Check status of any serial number</div>
        </div>
        <svg class="hub-item-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
