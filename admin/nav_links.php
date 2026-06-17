<?php
// ============================================================
// Blackview SA Portal — Admin: Navigation Links Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Nav Link Management';
$errors    = [];
$editLink  = null;

// --- Toggle active ---
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $stmt = $pdo->prepare('UPDATE nav_links SET is_active = NOT is_active WHERE id = :id');
    $stmt->execute([':id' => (int)$_GET['toggle']]);
    logAudit($pdo, 'toggle_nav_link', 'nav_links', (int)$_GET['toggle'], 'Toggled visibility');
    setFlash('success', 'Link visibility updated.');
    header('Location: ' . BASE_URL . '/admin/nav_links.php');
    exit;
}

// --- Delete ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM nav_links WHERE id = :id');
    $stmt->execute([':id' => (int)$_GET['delete']]);
    logAudit($pdo, 'delete_nav_link', 'nav_links', (int)$_GET['delete'], 'Deleted nav link');
    setFlash('success', 'Nav link deleted.');
    header('Location: ' . BASE_URL . '/admin/nav_links.php');
    exit;
}

// --- Load for edit ---
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt    = $pdo->prepare('SELECT * FROM nav_links WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editLink = $stmt->fetch();
}

// --- POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action']        ?? '';
    $linkId       = (int)($_POST['link_id']       ?? 0);
    $label        = trim($_POST['label']          ?? '');
    $url          = trim($_POST['url']            ?? '');
    $iconClass    = trim($_POST['icon_class']     ?? '');
    $role         = trim($_POST['role_required']  ?? 'user');
    $order        = (int)($_POST['display_order'] ?? 0);
    $validRoles   = ['user','admin','superuser'];

    if ($label === '') $errors[] = 'Label is required.';
    if ($url   === '') $errors[] = 'URL is required.';
    if (!in_array($role, $validRoles, true)) $errors[] = 'Invalid role.';

    if (empty($errors)) {
        if ($action === 'add') {
            $ins = $pdo->prepare(
                'INSERT INTO nav_links (label, url, icon_class, role_required, display_order, is_active, created_at)
                 VALUES (:label, :url, :icon, :role, :ord, 1, NOW())'
            );
            $ins->execute([':label'=>$label,':url'=>$url,':icon'=>$iconClass,':role'=>$role,':ord'=>$order]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'create_nav_link', 'nav_links', $newId, "Created nav link \"$label\"");
            setFlash('success', "Nav link \"$label\" created.");
            header('Location: ' . BASE_URL . '/admin/nav_links.php');
            exit;

        } elseif ($action === 'edit' && $linkId > 0) {
            $upd = $pdo->prepare(
                'UPDATE nav_links SET label=:label, url=:url, icon_class=:icon, role_required=:role, display_order=:ord WHERE id=:id'
            );
            $upd->execute([':label'=>$label,':url'=>$url,':icon'=>$iconClass,':role'=>$role,':ord'=>$order,':id'=>$linkId]);
            logAudit($pdo, 'edit_nav_link', 'nav_links', $linkId, "Updated nav link \"$label\"");
            setFlash('success', "Nav link \"$label\" updated.");
            header('Location: ' . BASE_URL . '/admin/nav_links.php');
            exit;
        }
    }
}

$links = $pdo->query('SELECT * FROM nav_links ORDER BY display_order ASC, label ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Navigation Link Management</h2>
    <p class="page-subtitle">Control which links appear in the sidebar and who can see them.</p>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="two-col-layout">
    <!-- Links list -->
    <div class="col-main">
        <div class="card">
            <div class="card-header"><h3 class="card-title">All Nav Links</h3></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Label</th>
                            <th>URL</th>
                            <th>Icon Class</th>
                            <th>Role</th>
                            <th>Order</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($links as $lk): ?>
                        <tr class="<?= !$lk['is_active'] ? 'row-inactive' : '' ?>">
                            <td><?= $lk['id'] ?></td>
                            <td><?= htmlspecialchars($lk['label']) ?></td>
                            <td><code><?= htmlspecialchars($lk['url']) ?></code></td>
                            <td><code><?= htmlspecialchars($lk['icon_class']) ?></code></td>
                            <td><span class="badge badge-role badge-<?= $lk['role_required'] ?>"><?= ucfirst($lk['role_required']) ?></span></td>
                            <td><?= (int)$lk['display_order'] ?></td>
                            <td>
                                <span class="status-dot <?= $lk['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                            </td>
                            <td class="action-btns">
                                <a href="?edit=<?= $lk['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                <a href="?toggle=<?= $lk['id'] ?>"
                                   class="btn btn-sm <?= $lk['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                                    <?= $lk['is_active'] ? 'Hide' : 'Show' ?>
                                </a>
                                <a href="?delete=<?= $lk['id'] ?>" class="btn btn-sm btn-danger"
                                   onclick="return confirm('Delete this nav link permanently?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit form -->
    <div class="col-side">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $editLink ? 'Edit Link' : 'Add Nav Link' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="<?= $editLink ? 'edit' : 'add' ?>">
                    <?php if ($editLink): ?>
                        <input type="hidden" name="link_id" value="<?= $editLink['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Label <span class="required">*</span></label>
                        <input type="text" name="label" class="form-control"
                               value="<?= htmlspecialchars($editLink['label'] ?? '') ?>"
                               placeholder="e.g. Dashboard" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">URL <span class="required">*</span></label>
                        <input type="text" name="url" class="form-control"
                               value="<?= htmlspecialchars($editLink['url'] ?? '') ?>"
                               placeholder="/dashboard.php" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Icon Class</label>
                        <input type="text" name="icon_class" class="form-control"
                               value="<?= htmlspecialchars($editLink['icon_class'] ?? '') ?>"
                               placeholder="icon-home">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Minimum Role Required</label>
                        <select name="role_required" class="form-control form-select">
                            <?php foreach (['user','admin','superuser'] as $r): ?>
                                <option value="<?= $r ?>"
                                    <?= (($editLink['role_required'] ?? 'user') === $r) ? 'selected' : '' ?>>
                                    <?= ucfirst($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control"
                               value="<?= (int)($editLink['display_order'] ?? 10) ?>" min="0" max="999">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?= $editLink ? 'Update Link' : 'Add Link' ?>
                        </button>
                        <?php if ($editLink): ?>
                            <a href="<?= BASE_URL ?>/admin/nav_links.php" class="btn btn-outline">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
