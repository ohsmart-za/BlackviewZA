<?php
// ============================================================
// Blackview SA Portal — Admin: Warehouse Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Warehouse Management';
$errors    = [];
$editWH    = null;

// --- Toggle active ---
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tid  = (int)$_GET['toggle'];
    $stmt = $pdo->prepare('UPDATE warehouses SET is_active = NOT is_active WHERE id = :id');
    $stmt->execute([':id' => $tid]);
    logAudit($pdo, 'toggle_warehouse', 'warehouses', $tid, 'Toggled active status');
    setFlash('success', 'Warehouse status updated.');
    header('Location: ' . BASE_URL . '/admin/warehouses.php');
    exit;
}

// --- Load for edit ---
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt  = $pdo->prepare('SELECT * FROM warehouses WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editWH = $stmt->fetch();
}

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']  ?? '';
    $whId     = (int)($_POST['wh_id']    ?? 0);
    $name     = trim($_POST['name']      ?? '');
    $location = trim($_POST['location']  ?? '');

    if ($name === '') $errors[] = 'Warehouse name is required.';

    if (empty($errors)) {
        if ($action === 'add') {
            $ins = $pdo->prepare('INSERT INTO warehouses (name, location, is_active, created_at) VALUES (:n, :l, 1, NOW())');
            $ins->execute([':n' => $name, ':l' => $location]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'create_warehouse', 'warehouses', $newId, "Created warehouse \"$name\"");
            setFlash('success', "Warehouse \"$name\" created.");
            header('Location: ' . BASE_URL . '/admin/warehouses.php');
            exit;

        } elseif ($action === 'edit' && $whId > 0) {
            $upd = $pdo->prepare('UPDATE warehouses SET name=:n, location=:l WHERE id=:id');
            $upd->execute([':n' => $name, ':l' => $location, ':id' => $whId]);
            logAudit($pdo, 'edit_warehouse', 'warehouses', $whId, "Updated warehouse \"$name\"");
            setFlash('success', "Warehouse \"$name\" updated.");
            header('Location: ' . BASE_URL . '/admin/warehouses.php');
            exit;
        }
    }
}

$warehouses = $pdo->query('SELECT * FROM warehouses ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Warehouse Management</h2>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="two-col-layout">
    <!-- Warehouses list -->
    <div class="col-main">
        <div class="card">
            <div class="card-header"><h3 class="card-title">All Warehouses</h3></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($warehouses as $wh): ?>
                        <tr class="<?= !$wh['is_active'] ? 'row-inactive' : '' ?>">
                            <td><?= htmlspecialchars($wh['name']) ?></td>
                            <td><?= htmlspecialchars($wh['location']) ?></td>
                            <td>
                                <span class="status-dot <?= $wh['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                                <?= $wh['is_active'] ? 'Active' : 'Inactive' ?>
                            </td>
                            <td><?= date('d M Y', strtotime($wh['created_at'])) ?></td>
                            <td class="action-btns">
                                <a href="?edit=<?= $wh['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                <a href="?toggle=<?= $wh['id'] ?>"
                                   class="btn btn-sm <?= $wh['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                   onclick="return confirm('Toggle warehouse status?')">
                                    <?= $wh['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </a>
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
                <h3 class="card-title"><?= $editWH ? 'Edit Warehouse' : 'Add Warehouse' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="<?= $editWH ? 'edit' : 'add' ?>">
                    <?php if ($editWH): ?>
                        <input type="hidden" name="wh_id" value="<?= $editWH['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Warehouse Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= htmlspecialchars($editWH['name'] ?? '') ?>"
                               placeholder="e.g. Cape Town Store" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control"
                               value="<?= htmlspecialchars($editWH['location'] ?? '') ?>"
                               placeholder="e.g. 123 Main Road, Cape Town">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?= $editWH ? 'Update Warehouse' : 'Add Warehouse' ?>
                        </button>
                        <?php if ($editWH): ?>
                            <a href="<?= BASE_URL ?>/admin/warehouses.php" class="btn btn-outline">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
