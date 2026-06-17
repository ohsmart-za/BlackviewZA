<?php
// ============================================================
// Blackview SA Portal — Admin: User Nav Permissions
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'User Permissions';
$errors    = [];

// Require a user_id
$targetUserId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
    setFlash('error', 'No user selected.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit;
}

// Load target user
$stmtU = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
$stmtU->execute([':id' => $targetUserId]);
$targetUser = $stmtU->fetch();
if (!$targetUser) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit;
}

// Only superusers can manage other superusers
if ($targetUser['role'] === 'superuser' && !isSuperuser()) {
    setFlash('error', 'You cannot manage permissions for a superuser.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit;
}

// --- Handle POST: save permissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = $_POST['nav_links'] ?? [];
    $selected = array_map('intval', $selected);

    $pdo->beginTransaction();
    try {
        // Delete existing permissions for this user
        $pdo->prepare('DELETE FROM user_nav_permissions WHERE user_id = :uid')
            ->execute([':uid' => $targetUserId]);

        // Insert selected ones
        if (!empty($selected)) {
            $ins = $pdo->prepare(
                'INSERT INTO user_nav_permissions (user_id, nav_link_id) VALUES (:uid, :nlid)'
            );
            foreach ($selected as $nlid) {
                if ($nlid > 0) {
                    $ins->execute([':uid' => $targetUserId, ':nlid' => $nlid]);
                }
            }
        }

        logAudit($pdo, 'set_nav_permissions', 'users', $targetUserId,
            "Set nav permissions for {$targetUser['email']}: " . (empty($selected) ? 'reset to role defaults' : implode(',', $selected))
        );

        $pdo->commit();
        setFlash('success', empty($selected)
            ? "Permissions for {$targetUser['name']} reset to role defaults."
            : "Permissions for {$targetUser['name']} saved."
        );
        header('Location: ' . BASE_URL . '/admin/user_permissions.php?user_id=' . $targetUserId);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[] = 'Failed to save permissions: ' . $e->getMessage();
    }
}

// --- Load all nav links accessible to this user's role ---
$roleAccess = ['user'];
if (in_array($targetUser['role'], ['admin', 'superuser'], true)) $roleAccess[] = 'admin';
if ($targetUser['role'] === 'superuser') $roleAccess[] = 'superuser';

$inPlaceholders = implode(',', array_fill(0, count($roleAccess), '?'));
$stmtNL = $pdo->prepare(
    "SELECT * FROM nav_links WHERE is_active = 1 AND role_required IN ($inPlaceholders) ORDER BY display_order ASC"
);
$stmtNL->execute($roleAccess);
$allLinks = $stmtNL->fetchAll();

// --- Load current custom permissions for this user ---
$stmtP = $pdo->prepare('SELECT nav_link_id FROM user_nav_permissions WHERE user_id = :uid');
$stmtP->execute([':uid' => $targetUserId]);
$customPermIds = $stmtP->fetchAll(PDO::FETCH_COLUMN);
$hasCustomPerms = !empty($customPermIds);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-title">Nav Permissions</h2>
        <p class="page-subtitle">
            Controlling access for:
            <strong><?= htmlspecialchars($targetUser['name']) ?></strong>
            (<?= htmlspecialchars($targetUser['email']) ?>)
            &mdash; <span class="badge badge-role badge-<?= $targetUser['role'] ?>"><?= ucfirst($targetUser['role']) ?></span>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline">← Back to Users</a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if ($hasCustomPerms): ?>
<div class="alert alert-info">
    This user has <strong>custom permissions</strong> set — the navigation below overrides their role defaults.
    To revert to role-based defaults, uncheck all items and save.
</div>
<?php else: ?>
<div class="alert alert-warning">
    This user is using <strong>role-based defaults</strong> — all nav items for their role are visible.
    Check specific items below to create a custom permission set.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Navigation Visibility</h3>
        <div class="card-header-actions">
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(true)">Check All</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(false)">Uncheck All</button>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="" id="permissionsForm">
            <input type="hidden" name="user_id" value="<?= $targetUserId ?>">

            <?php
            // Group links by role_required for visual grouping
            $groups = ['user' => [], 'admin' => [], 'superuser' => []];
            foreach ($allLinks as $link) {
                $groups[$link['role_required']][] = $link;
            }
            $groupLabels = ['user' => 'General', 'admin' => 'Admin', 'superuser' => 'Superuser'];
            ?>

            <?php foreach ($groups as $groupRole => $links): ?>
                <?php if (empty($links)) continue; ?>
                <div class="permission-group">
                    <div class="permission-group-label"><?= $groupLabels[$groupRole] ?></div>
                    <div class="permission-grid">
                        <?php foreach ($links as $link):
                            $checked = $hasCustomPerms
                                ? in_array($link['id'], $customPermIds)
                                : true; // default: all checked when no custom set
                        ?>
                        <label class="permission-item <?= $checked ? 'permission-item--active' : '' ?>">
                            <input
                                type="checkbox"
                                name="nav_links[]"
                                value="<?= $link['id'] ?>"
                                class="permission-checkbox"
                                <?= $checked ? 'checked' : '' ?>
                            >
                            <span class="permission-label"><?= htmlspecialchars($link['label']) ?></span>
                            <span class="permission-url"><?= htmlspecialchars($link['url']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="form-actions" style="margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary">Save Permissions</button>
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.permission-group { margin-bottom: 1.5rem; }
.permission-group-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-blue-accent);
    margin-bottom: 0.6rem;
    padding-bottom: 0.3rem;
    border-bottom: 1px solid var(--color-border);
}
.permission-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.6rem;
}
.permission-item {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.75rem 1rem;
    border: 1.5px solid var(--color-border);
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
    background: #fff;
}
.permission-item:hover { border-color: var(--color-blue); background: #f0f4ff; }
.permission-item--active { border-color: var(--color-blue); background: #eef3ff; }
.permission-item input[type="checkbox"] { margin-bottom: 0.25rem; }
.permission-label { font-weight: 600; font-size: 0.9rem; color: var(--color-text); }
.permission-url { font-size: 0.75rem; color: var(--color-muted); font-family: monospace; }
.card-header-actions { display: flex; gap: 0.5rem; }
.page-header { display: flex; justify-content: space-between; align-items: flex-start; }
</style>

<script>
function toggleAll(state) {
    document.querySelectorAll('.permission-checkbox').forEach(function(cb) {
        cb.checked = state;
        cb.closest('.permission-item').classList.toggle('permission-item--active', state);
    });
}
document.querySelectorAll('.permission-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
        this.closest('.permission-item').classList.toggle('permission-item--active', this.checked);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
