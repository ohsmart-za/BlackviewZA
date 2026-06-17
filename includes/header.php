<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<?php
// ---- Load app settings (graceful if settings table doesn't exist yet) ----
if (!isset($_appSettings)) {
    $_appSettings = [];
    try {
        require_once __DIR__ . '/../config/settings.php';
        if (!isset($pdo)) {
            require_once __DIR__ . '/../config/db.php';
            $pdo = getDB();
        }
        $_appSettings = getSettings($pdo);
    } catch (Throwable $_settingsEx) {
        // Settings table not yet created — fall back to APP_NAME defaults
        $_appSettings = [];
    }
}
$_companyName = !empty($_appSettings['company_name']) ? $_appSettings['company_name'] : APP_NAME;
$_logoPath    = $_appSettings['logo_path'] ?? '';

// ---- Top 3 salespeople (all-time by total revenue) ----
$_topSales = [];
try {
    if (!isset($pdo)) { require_once __DIR__ . '/../config/db.php'; $pdo = getDB(); }
    $_topQ = $pdo->query(
        "SELECT u.name AS seller_name,
                COUNT(inv.id)   AS sale_count,
                SUM(inv.total)  AS total_rev,
                MAX(inv.created_at) AS last_sale
         FROM invoices inv
         JOIN users u ON u.id = inv.created_by
         GROUP BY inv.created_by
         ORDER BY total_rev DESC
         LIMIT 3"
    );
    $_topSales = $_topQ ? $_topQ->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $_e) { $_topSales = []; }

$_trophyMeta = [
    ['label' => 'Gold',   'color' => '#DAA520', 'border' => 'rgba(218,165,32,.55)',  'bg' => 'rgba(218,165,32,.13)', 'ordinal' => '1st', 'medal' => '🥇'],
    ['label' => 'Silver', 'color' => '#A0A0A8', 'border' => 'rgba(160,160,168,.55)', 'bg' => 'rgba(160,160,168,.13)', 'ordinal' => '2nd', 'medal' => '🥈'],
    ['label' => 'Bronze', 'color' => '#CD7F32', 'border' => 'rgba(205,127,50,.55)',  'bg' => 'rgba(205,127,50,.13)', 'ordinal' => '3rd', 'medal' => '🥉'],
];

// Build sales data array (always 3 slots, null for unclaimed)
$_salesForJs = [];
for ($_i = 0; $_i < 3; $_i++) {
    $_e = $_topSales[$_i] ?? null;
    $_salesForJs[] = $_e ? [
        'name'  => $_e['seller_name'],
        'count' => (int)$_e['sale_count'],
        'total' => (float)$_e['total_rev'],
        'last'  => $_e['last_sale'],
    ] : null;
}

function _trophySvg($c) {
    return '<svg viewBox="0 0 100 120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . '<path d="M19 16 Q3 16 3 36 Q3 54 19 54" stroke="'.$c.'" stroke-width="7" fill="none" stroke-linecap="round"/>'
        . '<path d="M81 16 Q97 16 97 36 Q97 54 81 54" stroke="'.$c.'" stroke-width="7" fill="none" stroke-linecap="round"/>'
        . '<path d="M19 6 H81 L73 58 Q65 76 50 76 Q35 76 27 58 Z" fill="'.$c.'"/>'
        . '<ellipse cx="43" cy="20" rx="8" ry="4" fill="rgba(255,255,255,0.35)" transform="rotate(-15 43 20)"/>'
        . '<text x="50" y="50" text-anchor="middle" font-size="28" fill="rgba(255,255,255,0.18)">★</text>'
        . '<rect x="44" y="76" width="12" height="18" fill="'.$c.'" rx="2"/>'
        . '<rect x="24" y="94" width="52" height="14" fill="'.$c.'" rx="4"/>'
        . '<rect x="24" y="94" width="52" height="5" fill="rgba(0,0,0,0.1)" rx="2"/>'
        . '</svg>';
}
?>

<!-- ============================================================
     TOP HEADER BAR
     ============================================================ -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar">
    <!-- Hamburger — mobile only -->
    <button class="topbar-hamburger" id="menuToggle" aria-label="Open navigation">
        <svg width="22" height="18" viewBox="0 0 22 18" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="22" height="2.5" rx="1.25" fill="currentColor"/>
            <rect y="7.75" width="22" height="2.5" rx="1.25" fill="currentColor"/>
            <rect y="15.5" width="22" height="2.5" rx="1.25" fill="currentColor"/>
        </svg>
    </button>

    <div class="topbar-brand"></div>
    <span class="topbar-mobile-brand">Blackview</span>

    <!-- Trophy leaderboard -->
    <div class="topbar-trophies">
        <?php foreach ($_trophyMeta as $rank => $tm):
            $entry = $_topSales[$rank] ?? null;
        ?>
        <button type="button" class="trophy-badge"
                onclick="openTrophyCert(<?= $rank ?>)"
                title="<?= $tm['label'] ?> — <?= $entry ? htmlspecialchars($entry['seller_name']) . ' · R ' . number_format((float)$entry['total_rev'], 0) : 'Click to view' ?>"
                style="background:<?= $tm['bg'] ?>;border-color:<?= $tm['border'] ?>;">
            <span class="trophy-svg-wrap"><?= _trophySvg($tm['color']) ?></span>
            <div class="trophy-info">
                <?php if ($entry): ?>
                    <span class="trophy-rank-label" style="color:<?= $tm['color'] ?>;"><?= $tm['label'] ?></span>
                    <span class="trophy-name"><?= htmlspecialchars(explode(' ', $entry['seller_name'])[0]) ?></span>
                    <span class="trophy-detail">R <?= number_format((float)$entry['total_rev'], 0) ?></span>
                <?php else: ?>
                    <span class="trophy-rank-label" style="color:<?= $tm['color'] ?>;opacity:.7;"><?= $tm['label'] ?></span>
                    <span class="trophy-name" style="opacity:.38;font-style:italic;">Unclaimed</span>
                    <span class="trophy-detail" style="opacity:.25;">— — —</span>
                <?php endif; ?>
            </div>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="topbar-right">
        <?php $user = currentUser(); if ($user): ?>
            <span class="topbar-user">
                <span class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
                <span class="topbar-user-name"><?= htmlspecialchars($user['name']) ?></span>
                <span class="user-role-badge user-role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
            </span>
            <a href="<?= BASE_URL ?>/index.php?logout=1" class="btn btn-sm btn-outline-light topbar-logout">Logout</a>
        <?php endif; ?>
    </div>
</header>

<!-- ============================================================
     TROPHY CERTIFICATE MODAL
     ============================================================ -->
<div id="trophy-cert-overlay" onclick="if(event.target===this)closeTrophyCert()">
    <div id="trophy-cert-modal">
        <button class="cert-close-x" onclick="closeTrophyCert()" title="Close">&#x2715;</button>
        <div id="cert-content"></div>
        <div class="cert-actions no-print">
            <button onclick="window.print()" class="btn btn-primary" style="min-width:180px;">&#128438; Print / Save as PDF</button>
            <button onclick="closeTrophyCert()" class="btn btn-outline">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var _sd = <?= json_encode($_salesForJs) ?>;
    var _meta = <?= json_encode(array_map(function($m){ return ['label'=>$m['label'],'color'=>$m['color'],'ordinal'=>$m['ordinal'],'medal'=>$m['medal']]; }, $_trophyMeta)) ?>;
    var _company = <?= json_encode($_companyName) ?>;
    var _logo    = <?= json_encode($_logoPath ? BASE_URL . '/' . $_logoPath : '') ?>;
    var _today   = new Date().toLocaleDateString('en-ZA', {day:'numeric', month:'long', year:'numeric'});

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function fmtDate(s) { try { return new Date(s).toLocaleDateString('en-ZA',{day:'numeric',month:'long',year:'numeric'}); } catch(e){ return s; } }
    function fmtMoney(n) { return 'R ' + Number(n).toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    window.openTrophyCert = function(rank) {
        var e = _sd[rank], m = _meta[rank];
        var logoHtml = _logo
            ? '<img src="' + esc(_logo) + '" class="cert-logo-img" alt="logo">'
            : '<div class="cert-logo-fallback">' + esc(_company.substring(0,2).toUpperCase()) + '</div>';

        var statsHtml = e
            ? '<div class="cert-stats">'
                + '<div class="cert-stat"><div class="cert-stat-val">' + fmtMoney(e.total) + '</div><div class="cert-stat-lbl">Total Revenue</div></div>'
                + '<div class="cert-stat-sep"></div>'
                + '<div class="cert-stat"><div class="cert-stat-val">' + e.count + '</div><div class="cert-stat-lbl">Invoices Issued</div></div>'
                + '<div class="cert-stat-sep"></div>'
                + '<div class="cert-stat"><div class="cert-stat-val">' + fmtDate(e.last) + '</div><div class="cert-stat-lbl">Last Sale Date</div></div>'
                + '</div>'
            : '<div class="cert-unclaimed">This position is yet to be claimed.<br>Be the first to make a sale and earn this trophy!</div>';

        document.getElementById('cert-content').innerHTML =
            '<div class="cert-paper" style="--cc:' + m.color + ';">'
            + '<div class="cert-outer-border">'
            + '<div class="cert-inner-border">'

            + '<div class="cert-corner-deco cert-tl">&#10022;</div>'
            + '<div class="cert-corner-deco cert-tr">&#10022;</div>'
            + '<div class="cert-corner-deco cert-bl">&#10022;</div>'
            + '<div class="cert-corner-deco cert-br">&#10022;</div>'

            + '<div class="cert-brand-row">' + logoHtml + '<div class="cert-company-name">' + esc(_company) + '</div></div>'

            + '<div class="cert-rule"><span>&#10022;</span></div>'
            + '<div class="cert-heading">Certificate of Sales Excellence</div>'
            + '<div class="cert-rule"><span>' + m.medal + '</span></div>'

            + '<div class="cert-preamble">This is to proudly certify that</div>'
            + '<div class="cert-recipient">' + (e ? esc(e.name) : '<span style="opacity:.4;font-style:italic;">— Position Open —</span>') + '</div>'

            + '<div class="cert-achievement">has achieved <strong style="color:' + m.color + ';">' + m.medal + ' ' + m.label + ' Rank &mdash; ' + m.ordinal + ' Place</strong><br>'
            + 'in sales performance at <em>' + esc(_company) + '</em></div>'

            + statsHtml

            + '<div class="cert-footer-row">'
            + '<div class="cert-sig-block"><div class="cert-sig-line"></div><div class="cert-sig-label">Authorised Signature</div></div>'
            + '<div class="cert-seal" style="border-color:' + m.color + ';color:' + m.color + ';">'
            +   '<div class="cert-seal-medal">' + m.medal + '</div>'
            +   '<div class="cert-seal-text">' + m.label.toUpperCase() + '</div>'
            +   '<div class="cert-seal-sub">EXCELLENCE</div>'
            + '</div>'
            + '<div class="cert-sig-block"><div class="cert-sig-line"></div><div class="cert-sig-label">Branch Manager</div></div>'
            + '</div>'

            + '<div class="cert-issued">Issued: ' + _today + '</div>'

            + '</div></div></div>';

        document.getElementById('trophy-cert-overlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.closeTrophyCert = function () {
        document.getElementById('trophy-cert-overlay').style.display = 'none';
        document.body.style.overflow = '';
    };

    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeTrophyCert(); });
}());
</script>

<!-- ============================================================
     INACTIVITY AUTO-LOGOUT
     ============================================================ -->
<?php if (!empty($_SESSION['user_id'])): ?>
<style>
#session-overlay {
    display:none;position:fixed;inset:0;z-index:99999;
    background:rgba(15,23,42,.72);backdrop-filter:blur(4px);
    align-items:center;justify-content:center;
}
#session-overlay.active { display:flex; }
#session-box {
    background:#fff;border-radius:14px;padding:2rem 2.25rem;
    max-width:380px;width:90%;text-align:center;
    box-shadow:0 20px 60px rgba(0,0,0,.35);
    animation:slideUp .22s ease;
}
@keyframes slideUp {
    from{transform:translateY(24px);opacity:0}
    to{transform:translateY(0);opacity:1}
}
#session-ring {
    width:72px;height:72px;margin:0 auto 1rem;position:relative;
}
#session-ring svg { transform:rotate(-90deg); }
#session-ring-track { stroke:#e2e8f0; }
#session-ring-fill  { stroke:#ef4444;stroke-dasharray:188;stroke-dashoffset:0;
                       transition:stroke-dashoffset .9s linear; }
#session-ring-num {
    position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:1.5rem;font-weight:700;color:#ef4444;
}
#session-expired-icon { font-size:3rem;margin-bottom:.75rem; }
.session-title   { font-size:1.1rem;font-weight:700;color:#0f172a;margin-bottom:.4rem; }
.session-sub     { font-size:.875rem;color:#64748b;margin-bottom:1.25rem; }
.session-stay-btn{
    background:#2563eb;color:#fff;border:none;border-radius:8px;
    padding:.6rem 1.6rem;font-size:.9rem;font-weight:600;cursor:pointer;
    width:100%;margin-bottom:.5rem;
}
.session-stay-btn:hover{ background:#1d4ed8; }
.session-logout-btn{
    background:none;border:none;color:#94a3b8;font-size:.8rem;
    cursor:pointer;text-decoration:underline;
}
</style>

<!-- Countdown warning modal -->
<div id="session-overlay">
  <div id="session-box">
    <!-- Warning state -->
    <div id="session-warn-state">
      <div id="session-ring">
        <svg width="72" height="72" viewBox="0 0 72 72">
          <circle id="session-ring-track" cx="36" cy="36" r="30" fill="none" stroke-width="6"/>
          <circle id="session-ring-fill"  cx="36" cy="36" r="30" fill="none" stroke-width="6" stroke-linecap="round"/>
        </svg>
        <div id="session-ring-num">30</div>
      </div>
      <div class="session-title">Are you still there?</div>
      <div class="session-sub">You'll be signed out in <strong id="inactivity-countdown">30</strong> seconds due to inactivity.</div>
      <button class="session-stay-btn" onclick="staySignedIn()">Yes, keep me signed in</button>
      <br>
      <button class="session-logout-btn" onclick="doLogoutNow()">Sign out now</button>
    </div>
    <!-- Expired state -->
    <div id="session-expired-state" style="display:none;">
      <div id="session-expired-icon">🔒</div>
      <div class="session-title">Session Expired</div>
      <div class="session-sub">You've been signed out due to inactivity.<br>Redirecting to login…</div>
    </div>
  </div>
</div>

<script>
(function () {
    var TIMEOUT_MS  = <?= (int)($_SESSION['session_timeout'] ?? SESSION_TIMEOUT) * 1000 ?>;
    var WARNING_SEC = 30;
    var CIRCUMFERENCE = 2 * Math.PI * 30; // r=30
    var logoutUrl   = <?= json_encode(BASE_URL . '/index.php?logout=1&timeout=1') ?>;

    var timer      = null;
    var warnTimer  = null;
    var countTimer = null;
    var inWarning  = false;   // true while countdown is visible — blocks activity resets

    var overlay    = document.getElementById('session-overlay');
    var warnState  = document.getElementById('session-warn-state');
    var expState   = document.getElementById('session-expired-state');
    var countdown  = document.getElementById('inactivity-countdown');
    var ringNum    = document.getElementById('session-ring-num');
    var ringFill   = document.getElementById('session-ring-fill');

    // Init ring
    ringFill.style.strokeDasharray  = CIRCUMFERENCE;
    ringFill.style.strokeDashoffset = 0;

    // Called only by the "Stay Signed In" button — explicit user action
    window.staySignedIn = function() {
        inWarning = false;
        resetTimers();
        overlay.classList.remove('active');
    };

    window.doLogoutNow = function() {
        inWarning = false;
        clearAllTimers();
        warnState.style.display = 'none';
        expState.style.display  = 'block';
        overlay.classList.add('active');
        setTimeout(function() { window.location.href = logoutUrl; }, 1800);
    };

    function doLogout() {
        inWarning = false;
        clearAllTimers();
        warnState.style.display = 'none';
        expState.style.display  = 'block';
        // Overlay stays active (was already showing warning)
        setTimeout(function() { window.location.href = logoutUrl; }, 1800);
    }

    function showWarning() {
        inWarning = true;
        var secs = WARNING_SEC;
        countdown.textContent = secs;
        ringNum.textContent   = secs;
        ringFill.style.strokeDashoffset = 0;
        warnState.style.display = 'block';
        expState.style.display  = 'none';
        overlay.classList.add('active');

        countTimer = setInterval(function () {
            secs--;
            if (secs <= 0) {
                clearInterval(countTimer);
                doLogout();
                return;
            }
            countdown.textContent = secs;
            ringNum.textContent   = secs;
            var progress = 1 - (secs / WARNING_SEC);
            ringFill.style.strokeDashoffset = progress * CIRCUMFERENCE;
        }, 1000);
    }

    function clearAllTimers() {
        clearTimeout(timer);
        clearTimeout(warnTimer);
        clearInterval(countTimer);
    }

    function resetTimers() {
        clearAllTimers();
        var warnAt = Math.max(0, TIMEOUT_MS - WARNING_SEC * 1000);
        warnTimer = setTimeout(showWarning, warnAt);
        timer     = setTimeout(doLogout,    TIMEOUT_MS);
    }

    // Activity handler — ignored while warning is visible
    function onActivity() {
        if (inWarning) return;   // ← key fix: don't reset while countdown is showing
        resetTimers();
    }

    ['mousemove','mousedown','keydown','scroll','touchstart','click'].forEach(function (evt) {
        document.addEventListener(evt, onActivity, { passive: true });
    });

    resetTimers();
}());
</script>
<?php endif; ?>

<!-- ============================================================
     FLASH MESSAGES
     ============================================================ -->
<?php if (!empty($_SESSION['flash'])): ?>
<div class="flash-container">
    <?php foreach ($_SESSION['flash'] as $flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
            <button class="alert-close" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endforeach; ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<!-- ============================================================
     LAYOUT WRAPPER
     ============================================================ -->
<div class="layout-wrapper">
    <!-- Main content -->
    <main class="main-content">
        <div class="content-inner">
