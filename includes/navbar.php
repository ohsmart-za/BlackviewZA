<?php
// ============================================================
// Blackview SA Portal — Sidebar Nav
// ============================================================

$navLinks    = getNavLinks($pdo);
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$_navUser    = currentUser();

// Split links into regular and grouped
$regularLinks = [];
$groupedLinks = []; // keyed by group_label

foreach ($navLinks as $link) {
    $grp = $link['group_label'] ?? null;
    if ($grp) {
        $groupedLinks[$grp][] = $link;
    } else {
        $regularLinks[] = $link;
    }
}
?>

<nav class="sidebar" id="sidebar">

    <!-- Sidebar header: brand + close button -->
    <div class="sidebar-header">
        <div class="sidebar-brand-text">
            <div class="sidebar-brand-name">Blackview</div>
            <div class="sidebar-brand-sub">Master Portal</div>
        </div>
        <button class="sidebar-close-btn" id="sidebarClose" aria-label="Close menu">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                <line x1="1" y1="1" x2="17" y2="17" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                <line x1="17" y1="1" x2="1" y2="17" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </button>
    </div>

    <!-- User profile strip -->
    <?php if ($_navUser): ?>
    <div class="sidebar-user-strip">
        <div class="sidebar-user-avatar"><?= strtoupper(substr($_navUser['name'], 0, 1)) ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= htmlspecialchars($_navUser['name']) ?></div>
            <div class="sidebar-user-role"><?= ucfirst($_navUser['role']) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Nav links -->
    <ul class="sidebar-nav">

        <?php foreach ($regularLinks as $link):
            $href        = BASE_URL . $link['url'];
            $isActive    = (strpos($currentPath, ltrim($link['url'], '/')) !== false);
            $activeClass = $isActive ? ' active' : '';
        ?>
            <li class="sidebar-nav-item<?= $activeClass ?>">
                <a href="<?= htmlspecialchars($href) ?>" class="sidebar-nav-link">
                    <span class="nav-icon <?= htmlspecialchars($link['icon_class']) ?>"></span>
                    <span class="nav-label"><?= htmlspecialchars($link['label']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>

        <?php foreach ($groupedLinks as $groupName => $links):
            // Admin group is handled by the dedicated Admin panel below — skip it here
            if (strtolower(trim($groupName)) === 'admin') continue;

            // Check if any link in the group is active
            $groupActive = false;
            foreach ($links as $link) {
                if (strpos($currentPath, ltrim($link['url'], '/')) !== false) {
                    $groupActive = true;
                    break;
                }
            }
            $groupId = 'nav-group-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($groupName));
        ?>
            <li class="sidebar-nav-item">
                <div class="sidebar-group-divider"></div>
                <button type="button"
                        class="sidebar-group-btn <?= $groupActive ? 'open' : '' ?>"
                        aria-expanded="<?= $groupActive ? 'true' : 'false' ?>"
                        onclick="toggleNavGroup('<?= $groupId ?>', this)">
                    <span class="nav-icon icon-admin"></span>
                    <span class="nav-label"><?= htmlspecialchars($groupName) ?></span>
                    <svg class="group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <ul class="sidebar-group-items" id="<?= $groupId ?>"
                    style="display:<?= $groupActive ? 'block' : 'none' ?>;">
                    <?php foreach ($links as $link):
                        $href        = BASE_URL . $link['url'];
                        $isActive    = (strpos($currentPath, ltrim($link['url'], '/')) !== false);
                        $activeClass = $isActive ? ' active' : '';
                    ?>
                        <li class="sidebar-nav-item<?= $activeClass ?>">
                            <a href="<?= htmlspecialchars($href) ?>" class="sidebar-nav-link">
                                <span class="nav-icon <?= htmlspecialchars($link['icon_class']) ?>"></span>
                                <span class="nav-label"><?= htmlspecialchars($link['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>

    </ul>

    <!-- Footer: admin panel + sign out + credits -->
    <div class="sidebar-footer">

        <?php if (isAdmin()): ?>
        <?php
        $_adminPanelOpen = (strpos($currentPath, '/admin/') !== false);
        $_adminLinks = [
            ['label' => 'Company Settings', 'url' => BASE_URL . '/admin/settings.php#company',  'match' => '/admin/settings.php'],
            ['label' => 'SMTP Settings',    'url' => BASE_URL . '/admin/settings.php#smtp',     'match' => '/admin/settings.php'],
            ['label' => 'Security',         'url' => BASE_URL . '/admin/settings.php#security',         'match' => '/admin/settings.php'],
            ['label' => 'Payment Gateways', 'url' => BASE_URL . '/admin/settings.php#payment-gateways', 'match' => '/admin/settings.php'],
            ['label' => 'Users',            'url' => BASE_URL . '/admin/users.php',            'match' => '/admin/users.php'],
            ['label' => 'Email Templates',  'url' => BASE_URL . '/admin/email_templates.php',  'match' => '/admin/email_templates.php'],
            ['label' => 'Payment Methods',  'url' => BASE_URL . '/admin/payment_methods.php',  'match' => '/admin/payment_methods.php'],
            ['label' => 'Nav Links',        'url' => BASE_URL . '/admin/nav_links.php',        'match' => '/admin/nav_links.php'],
            ['label' => 'Company Docs',     'url' => BASE_URL . '/admin/company_docs.php',    'match' => '/admin/company_docs.php'],
            ['label' => 'Audit Log',        'url' => BASE_URL . '/admin/audit_log.php',        'match' => '/admin/audit_log.php'],
        ];
        ?>
        <div class="sidebar-admin-divider"></div>
        <div>
            <button type="button"
                    class="sidebar-admin-btn <?= $_adminPanelOpen ? 'open' : '' ?>"
                    id="adminPanelToggle"
                    onclick="toggleAdminPanel(this)"
                    aria-expanded="<?= $_adminPanelOpen ? 'true' : 'false' ?>">
                <span class="sidebar-admin-btn-inner">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.8;">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    <span>Admin</span>
                </span>
                <svg class="admin-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>

            <ul class="sidebar-admin-panel" id="adminPanel"
                style="display:<?= $_adminPanelOpen ? 'block' : 'none' ?>;">
                <?php foreach ($_adminLinks as $_al):
                    $_alActive = (strpos($currentPath, ltrim($_al['match'], '/')) !== false);
                ?>
                <li>
                    <a href="<?= htmlspecialchars($_al['url']) ?>"
                       class="sidebar-admin-link<?= $_alActive ? ' active' : '' ?>">
                        <?= htmlspecialchars($_al['label']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?logout=1" class="sidebar-signout-btn">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Sign Out
        </a>
        <div class="sidebar-credits">
            <span class="sidebar-version">v<?= APP_VERSION ?></span>
            <span class="sidebar-designed">Designed by
                <a href="http://ohsmart.co.za" target="_blank" rel="noopener">OhSmart (Pty) Ltd</a>
            </span>
        </div>
    </div>

</nav>

<script>
function toggleAdminPanel(btn) {
    var panel = document.getElementById('adminPanel');
    if (!panel) return;
    var isOpen = panel.style.display !== 'none';
    panel.style.display = isOpen ? 'none' : 'block';
    btn.classList.toggle('open', !isOpen);
    btn.setAttribute('aria-expanded', String(!isOpen));
}

function toggleNavGroup(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    btn.classList.toggle('open', !isOpen);
    btn.setAttribute('aria-expanded', String(!isOpen));
    try { localStorage.setItem('navGroup_' + id, isOpen ? '0' : '1'); } catch(e) {}
}
// Restore group state from localStorage on load
(function(){
    document.querySelectorAll('.sidebar-group-items').forEach(function(el){
        var key = 'navGroup_' + el.id;
        try {
            var val = localStorage.getItem(key);
            if (val === '1') {
                el.style.display = 'block';
                var btn = el.previousElementSibling;
                if (btn && btn.classList.contains('sidebar-group-btn')) {
                    btn.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                }
            }
        } catch(e) {}
    });
}());
</script>
