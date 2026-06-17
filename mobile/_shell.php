<?php
// Mobile app shell — shared <head> + topbar
// $pageTitle   string  — tab/page title
// $showBack    bool    — show back arrow (true) or brand (false)
// $backUrl     string  — where back goes (default: mobile/index.php)
// $activeNav   string  — 'home'|'scan'|'move'|'takeout'|'pos'
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2563EB">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= htmlspecialchars($pageTitle ?? 'Blackview') ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/mobile/app.css">
</head>
<body>
<?php $user = currentUser(); ?>
<div class="app-wrap">

<!-- Topbar -->
<div class="app-topbar">
    <?php if (!empty($showBack)): ?>
    <a href="<?= BASE_URL . '/' . ($backUrl ?? 'mobile/index.php') ?>" class="topbar-back" aria-label="Back">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M15 18l-6-6 6-6" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <?php else: ?>
    <div style="width:40px;"></div>
    <?php endif; ?>

    <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Blackview') ?></div>

    <?php if ($user): ?>
    <div class="topbar-avatar" onclick="openUserSheet()" role="button" aria-label="Account">
        <?= strtoupper(substr($user['name'], 0, 1)) ?>
    </div>
    <?php endif; ?>
</div>

<!-- User sheet -->
<?php if ($user): ?>
<div class="user-sheet-overlay" id="userSheetOverlay">
    <div class="user-sheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-user-row">
            <div class="sheet-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <div>
                <div class="sheet-user-name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="sheet-user-role"><?= htmlspecialchars($user['role']) ?></div>
            </div>
        </div>
        <div class="sheet-actions">
            <a href="<?= BASE_URL ?>/dashboard.php?desktop=1" class="sheet-btn sheet-btn-desktop">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                Switch to Desktop Site
            </a>
            <a href="<?= BASE_URL ?>/index.php?logout=1" class="sheet-btn sheet-btn-signout">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Sign Out
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Flash messages -->
<?php if (!empty($_SESSION['flash'])): ?>
<div style="padding:12px 16px 0;">
    <?php foreach ($_SESSION['flash'] as $flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endforeach; ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<!-- Scrollable content -->
<div class="app-scroll">
