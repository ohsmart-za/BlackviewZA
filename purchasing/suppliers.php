<?php
// ============================================================
// Blackview SA Portal — Purchasing: Supplier Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Suppliers';
$errors    = [];
$editSup   = null;

// --- Toggle active ---
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tid  = (int)$_GET['toggle'];
    $stmt = $pdo->prepare('UPDATE suppliers SET is_active = NOT is_active WHERE id = :id');
    $stmt->execute([':id' => $tid]);
    logAudit($pdo, 'toggle_supplier', 'suppliers', $tid, 'Toggled supplier active status');
    setFlash('success', 'Supplier status updated.');
    header('Location: ' . BASE_URL . '/purchasing/suppliers.php');
    exit;
}

// --- Load for edit ---
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt  = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editSup = $stmt->fetch();
}

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']       ?? '';
    $supId       = (int)($_POST['sup_id'] ?? 0);
    $name        = trim($_POST['name']         ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $email       = trim($_POST['email']        ?? '');
    $phone       = trim($_POST['phone']        ?? '');
    $address     = trim($_POST['address']      ?? '');

    if ($name === '') $errors[] = 'Supplier name is required.';

    if (empty($errors)) {
        if ($action === 'add') {
            $ins = $pdo->prepare(
                'INSERT INTO suppliers (name, contact_name, email, phone, address, is_active, created_at)
                 VALUES (:name, :contact, :email, :phone, :addr, 1, NOW())'
            );
            $ins->execute([
                ':name'    => $name,
                ':contact' => $contactName,
                ':email'   => $email,
                ':phone'   => $phone,
                ':addr'    => $address,
            ]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'create_supplier', 'suppliers', $newId, "Created supplier \"$name\"");
            setFlash('success', "Supplier \"$name\" created.");
            header('Location: ' . BASE_URL . '/purchasing/suppliers.php');
            exit;

        } elseif ($action === 'edit' && $supId > 0) {
            $upd = $pdo->prepare(
                'UPDATE suppliers SET name=:name, contact_name=:contact, email=:email, phone=:phone, address=:addr WHERE id=:id'
            );
            $upd->execute([
                ':name'    => $name,
                ':contact' => $contactName,
                ':email'   => $email,
                ':phone'   => $phone,
                ':addr'    => $address,
                ':id'      => $supId,
            ]);
            logAudit($pdo, 'edit_supplier', 'suppliers', $supId, "Updated supplier \"$name\"");
            setFlash('success', "Supplier \"$name\" updated.");
            header('Location: ' . BASE_URL . '/purchasing/suppliers.php');
            exit;
        }
    }

    // Re-populate form on error
    $editSup = [
        'id'           => $supId,
        'name'         => $name,
        'contact_name' => $contactName,
        'email'        => $email,
        'phone'        => $phone,
        'address'      => $address,
    ];
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Suppliers</h2>
    <p class="page-subtitle">Manage your product suppliers and their contact information.</p>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="two-col-layout">

    <!-- Suppliers list -->
    <div class="col-main">
        <div class="card">
            <div class="card-header"><h3 class="card-title">All Suppliers</h3></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $sup): ?>
                        <tr class="<?= !$sup['is_active'] ? 'row-inactive' : '' ?>">
                            <td><?= htmlspecialchars($sup['name']) ?></td>
                            <td><?= htmlspecialchars($sup['contact_name']) ?></td>
                            <td><?= htmlspecialchars($sup['email']) ?></td>
                            <td><?= htmlspecialchars($sup['phone']) ?></td>
                            <td>
                                <span class="status-dot <?= $sup['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                                <?= $sup['is_active'] ? 'Active' : 'Inactive' ?>
                            </td>
                            <td class="action-btns">
                                <a href="?edit=<?= $sup['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                <a href="?toggle=<?= $sup['id'] ?>"
                                   class="btn btn-sm <?= $sup['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                   onclick="return confirm('Toggle supplier status?')">
                                    <?= $sup['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($suppliers)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No suppliers found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add / Edit form -->
    <div class="col-side">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $editSup ? 'Edit Supplier' : 'Add Supplier' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="<?= $editSup ? 'edit' : 'add' ?>">
                    <?php if ($editSup): ?>
                        <input type="hidden" name="sup_id" value="<?= $editSup['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Supplier Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= htmlspecialchars($editSup['name'] ?? '') ?>"
                               placeholder="e.g. Blackview Global" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contact Name</label>
                        <input type="text" name="contact_name" class="form-control"
                               value="<?= htmlspecialchars($editSup['contact_name'] ?? '') ?>"
                               placeholder="e.g. John Smith">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($editSup['email'] ?? '') ?>"
                               placeholder="e.g. sales@supplier.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= htmlspecialchars($editSup['phone'] ?? '') ?>"
                               placeholder="e.g. +27 21 000 0000">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"
                                  placeholder="Full postal or street address..."><?= htmlspecialchars($editSup['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?= $editSup ? 'Update Supplier' : 'Add Supplier' ?>
                        </button>
                        <?php if ($editSup): ?>
                            <a href="<?= BASE_URL ?>/purchasing/suppliers.php" class="btn btn-outline">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
