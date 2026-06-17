<?php
// ============================================================
// Blackview SA Portal — Session / Auth Helpers
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// -------------------------------------------------------
// requireLogin()
// Redirects to index.php if the user is not logged in.
// Also enforces session timeout.
// -------------------------------------------------------
function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    // Session timeout check — use per-session configurable timeout if set, else fall back to constant
    $timeout = isset($_SESSION['session_timeout']) ? (int)$_SESSION['session_timeout'] : SESSION_TIMEOUT;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/index.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// -------------------------------------------------------
// requireAdmin()
// Requires role = admin OR superuser
// -------------------------------------------------------
function requireAdmin() {
    requireLogin();
    $role = $_SESSION['user_role'] ?? 'user';
    if (!in_array($role, ['admin', 'superuser'], true)) {
        header('Location: ' . BASE_URL . '/dashboard.php?denied=1');
        exit;
    }
}

// -------------------------------------------------------
// requireSuperuser()
// Requires role = superuser only
// -------------------------------------------------------
function requireSuperuser() {
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== 'superuser') {
        header('Location: ' . BASE_URL . '/dashboard.php?denied=1');
        exit;
    }
}

// -------------------------------------------------------
// currentUser()
// Returns array with id, name, email, role or null
// -------------------------------------------------------
function currentUser() {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name']  ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role']  ?? 'user',
    ];
}

// -------------------------------------------------------
// isAdmin() / isSuperuser() convenience helpers
// -------------------------------------------------------
function isAdmin() {
    return in_array($_SESSION['user_role'] ?? '', ['admin', 'superuser'], true);
}

function isSuperuser() {
    return ($_SESSION['user_role'] ?? '') === 'superuser';
}

// -------------------------------------------------------
// setFlash($type, $message)
// Flash messages stored in session, shown once on next page.
// $type: 'success' | 'error' | 'warning' | 'info'
// -------------------------------------------------------
function setFlash($type, $message) {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

// -------------------------------------------------------
// logAudit($pdo, $action, $entity, $entity_id, $details)
// Writes a row to audit_log.
// -------------------------------------------------------
function logAudit(PDO $pdo, $action, $entity = '', $entity_id = null, $details = '') {
    $userId = $_SESSION['user_id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address, created_at)
         VALUES (:uid, :action, :entity, :eid, :details, :ip, NOW())'
    );
    $stmt->execute([
        ':uid'     => $userId,
        ':action'  => $action,
        ':entity'  => $entity,
        ':eid'     => $entity_id,
        ':details' => $details,
        ':ip'      => $ip,
    ]);
}

// -------------------------------------------------------
// getNavLinks($pdo)
// Returns active nav links visible to the current user's role.
// Role hierarchy: superuser > admin > user
// -------------------------------------------------------
function getNavLinks(PDO $pdo): array {
    $userId = $_SESSION['user_id'] ?? null;
    $role   = $_SESSION['user_role'] ?? 'user';

    // Check if this user has custom nav permissions
    if ($userId) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM user_nav_permissions WHERE user_id = :uid');
        $chk->execute([':uid' => $userId]);
        if ((int)$chk->fetchColumn() > 0) {
            // Return only the explicitly allowed links (still must be active)
            $stmt = $pdo->prepare(
                "SELECT nl.* FROM nav_links nl
                 INNER JOIN user_nav_permissions unp ON unp.nav_link_id = nl.id
                 WHERE nl.is_active = 1 AND unp.user_id = :uid
                 ORDER BY nl.display_order ASC"
            );
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        }
    }

    // Fall back to role-based defaults
    $roles = ['user'];
    if (in_array($role, ['admin', 'superuser'], true)) $roles[] = 'admin';
    if ($role === 'superuser') $roles[] = 'superuser';

    $in = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM nav_links
         WHERE is_active = 1 AND role_required IN ($in)
         ORDER BY display_order ASC"
    );
    $stmt->execute($roles);
    $rows = $stmt->fetchAll();

    // Deduplicate by URL (handles schema imported more than once)
    $seen = [];
    $out  = [];
    foreach ($rows as $row) {
        if (!isset($seen[$row['url']])) {
            $seen[$row['url']] = true;
            $out[] = $row;
        }
    }
    return $out;
}
