<?php
// ============================================================
// Blackview SA Portal — Admin: Payment Methods
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Payment Methods';
$errors    = [];
$success   = '';

// ============================================================
// POST handlers
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Add new method ----
    if ($action === 'add') {
        $code  = trim(preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['code'] ?? '')));
        $name  = trim($_POST['name'] ?? '');
        $icon  = trim($_POST['icon'] ?? '💳');
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($code === '') $errors[] = 'Code is required (letters, numbers, underscore only).';
        if ($name === '') $errors[] = 'Name is required.';

        // reserved codes
        if (in_array($code, ['credit_note'], true)) {
            $errors[] = '"credit_note" is a reserved system code and cannot be used.';
        }

        if (empty($errors)) {
            try {
                $pdo->prepare(
                    "INSERT INTO payment_methods (code, name, icon, sort_order) VALUES (:c, :n, :i, :s)"
                )->execute([':c' => $code, ':n' => $name, ':i' => $icon ?: '💳', ':s' => $sort]);
                $success = "Payment method \"{$name}\" added.";
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = "A payment method with code \"{$code}\" already exists.";
                } else {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }

    // ---- Update existing method ----
    if ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '💳');
        $sort = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') $errors[] = 'Name is required.';

        if (empty($errors) && $id > 0) {
            $pdo->prepare(
                "UPDATE payment_methods SET name = :n, icon = :i, sort_order = :s WHERE id = :id"
            )->execute([':n' => $name, ':i' => $icon ?: '💳', ':s' => $sort, ':id' => $id]);
            $success = "Payment method updated.";
        }
    }

    // ---- Toggle active ----
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare(
                "UPDATE payment_methods SET is_active = 1 - is_active WHERE id = :id"
            )->execute([':id' => $id]);
            $success = "Status updated.";
        }
    }

    // ---- Delete (only if never used on an invoice) ----
    if ($action === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');

        // Built-in methods cannot be deleted
        if (in_array($code, ['cash', 'eft', 'card'], true)) {
            $errors[] = 'Built-in payment methods (cash, eft, card) cannot be deleted. You can deactivate them instead.';
        } else {
            $used = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE payment_method = :c");
            $used->execute([':c' => $code]);
            if ((int)$used->fetchColumn() > 0) {
                $errors[] = "Cannot delete \"{$code}\" — it is used on existing invoices. Deactivate it instead.";
            } else {
                $pdo->prepare("DELETE FROM payment_methods WHERE id = :id")->execute([':id' => $id]);
                $success = "Payment method deleted.";
            }
        }
    }

    if ($success) {
        setFlash('success', $success);
        header('Location: ' . BASE_URL . '/admin/payment_methods.php');
        exit;
    }
}

// ---- Load all methods ----
$methods = $pdo->query(
    "SELECT * FROM payment_methods ORDER BY sort_order ASC, name ASC"
)->fetchAll();

// Check edit mode
$editId = isset($_GET['edit']) && is_numeric($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    foreach ($methods as $m) {
        if ((int)$m['id'] === $editId) { $editRow = $m; break; }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Payment Methods</h2>
    <p class="page-subtitle">Manage the payment methods available at point of sale.</p>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start;">

    <!-- ---- Method List ---- -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">All Payment Methods</h3></div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead>
                    <tr>
                        <th style="width:40px;">Icon</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th class="text-right" style="width:70px;">Order</th>
                        <th class="text-center" style="width:80px;">Status</th>
                        <th style="width:130px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($methods as $m): ?>
                    <tr style="<?= !$m['is_active'] ? 'opacity:.5;' : '' ?>">
                        <td style="font-size:1.3rem;text-align:center;"><?= htmlspecialchars($m['icon']) ?></td>
                        <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                        <td><code style="font-size:.82rem;"><?= htmlspecialchars($m['code']) ?></code>
                            <?php if (in_array($m['code'], ['cash','eft','card'], true)): ?>
                                <span style="font-size:.72rem;background:#EFF6FF;color:#1D4ED8;padding:1px 5px;border-radius:4px;margin-left:.3rem;">built-in</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right" style="color:var(--color-muted);"><?= (int)$m['sort_order'] ?></td>
                        <td class="text-center">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $m['is_active'] ? 'btn-outline' : 'btn-warning' ?>"
                                        style="font-size:.72rem;padding:.2rem .55rem;">
                                    <?= $m['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </form>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <a href="?edit=<?= $m['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                            <?php if (!in_array($m['code'], ['cash','eft','card'], true)): ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Delete payment method \'<?= htmlspecialchars(addslashes($m['name'])) ?>\'?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id"   value="<?= $m['id'] ?>">
                                <input type="hidden" name="code" value="<?= htmlspecialchars($m['code']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($methods)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:1.5rem;">No payment methods found — run migration_007.sql first.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ---- Add / Edit Form ---- -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= $editRow ? 'Edit Method' : 'Add Method' ?></h3>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
                <?php endif; ?>

                <?php if (!$editRow): ?>
                <div class="form-group">
                    <label class="form-label">Code <span class="required">*</span></label>
                    <input type="text" name="code" class="form-control"
                           placeholder="e.g. crypto, layby, voucher"
                           pattern="[a-z0-9_]+" title="Lowercase letters, numbers and underscore only"
                           value="<?= htmlspecialchars($_POST['code'] ?? '') ?>" required>
                    <small class="form-hint">Lowercase letters, numbers, underscore. Cannot be changed after saving.</small>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label class="form-label">Code</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($editRow['code']) ?>" disabled>
                    <small class="form-hint">Code cannot be changed after creation.</small>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Display Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control"
                           placeholder="e.g. Cryptocurrency"
                           value="<?= htmlspecialchars($editRow ? $editRow['name'] : ($_POST['name'] ?? '')) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Icon (emoji)</label>
                    <input type="text" name="icon" class="form-control"
                           placeholder="💳" maxlength="10"
                           value="<?= htmlspecialchars($editRow ? $editRow['icon'] : ($_POST['icon'] ?? '')) ?>">
                    <small class="form-hint">Paste a single emoji. Leave blank for default 💳</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0" style="max-width:100px;"
                           value="<?= (int)($editRow ? $editRow['sort_order'] : ($_POST['sort_order'] ?? 0)) ?>">
                    <small class="form-hint">Lower numbers appear first on the POS.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= $editRow ? 'Save Changes' : 'Add Method' ?>
                    </button>
                    <?php if ($editRow): ?>
                    <a href="?" class="btn btn-outline">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
