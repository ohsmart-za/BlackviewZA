<?php
// Mobile app footer — closes .app-scroll, renders bottom nav, loads JS
// $activeNav  string  — 'home'|'pos'|'invoices'|'crm'|'stock'
$_nav = $activeNav ?? 'home';

function _navItem($href, $navId, $active, $label, $icon) {
    $cls = 'nav-item' . ($active === $navId ? ' active' : '');
    return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">' . $icon . '<span>' . $label . '</span></a>';
}

$_base = BASE_URL . '/mobile/';

$_navItems = [
    _navItem($_base . 'index.php',    'home',     $_nav, 'Home',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 12L12 4l9 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'),

    _navItem($_base . 'pos.php',      'pos',      $_nav, 'POS',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M2 10h20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M7 15h.01M12 15h.01M17 15h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'),

    _navItem($_base . 'invoices.php', 'invoices', $_nav, 'Invoices',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'),

    _navItem($_base . 'crm.php',      'crm',      $_nav, 'Customers',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'),

    _navItem($_base . 'stock.php',    'stock',    $_nav, 'Stock',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'),
];
?>
</div><!-- /.app-scroll -->

<!-- Bottom navigation -->
<nav class="app-nav" role="navigation" aria-label="Main navigation">
    <?= implode('', $_navItems) ?>
</nav>

</div><!-- /.app-wrap -->

<script>var BVZA_BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/mobile/app.js"></script>
</body>
</html>
