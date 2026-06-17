<?php
// ============================================================
// Blackview SA Portal — Admin: User Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'User Management';
$errors    = [];
$editUser  = null;

// --- Toggle active/inactive ---
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tid = (int)$_GET['toggle'];
    if ($tid === (int)$_SESSION['user_id']) {
        setFlash('error', 'You cannot deactivate your own account.');
    } else {
        $stmt = $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = :id');
        $stmt->execute([':id' => $tid]);
        logAudit($pdo, 'toggle_user', 'users', $tid, 'Toggled active status');
        setFlash('success', 'User status updated.');
    }
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit;
}

// --- Load user for editing ---
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, auth_method, can_edit_invoices, can_use_pos, is_active FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

// --- Handle add / edit form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action          = $_POST['action']      ?? '';
    $userId          = (int)($_POST['user_id'] ?? 0);
    $name            = trim($_POST['name']     ?? '');
    $email           = trim($_POST['email']    ?? '');
    $role            = trim($_POST['role']     ?? 'user');
    $password        = trim($_POST['password'] ?? '');
    $authMethod      = in_array($_POST['auth_method'] ?? '', ['local','google'], true) ? $_POST['auth_method'] : 'local';
    $canEditInvoices = isset($_POST['can_edit_invoices']) ? 1 : 0;
    $canUsePOS       = isset($_POST['can_use_pos'])       ? 1 : 0;
    $sendWelcome     = isset($_POST['send_welcome']) ? true : false;
    $validRoles      = ['user', 'admin', 'superuser'];

    if ($name  === '') $errors[] = 'Name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (!in_array($role, $validRoles, true)) $errors[] = 'Invalid role selected.';

    if ($role === 'superuser' && !isSuperuser()) {
        $errors[] = 'Only a superuser can assign the superuser role.';
    }

    // Google SSO users don't need a password
    $googleSsoEnabled = !empty(getSetting($pdo, 'google_sso_enabled'));

    if ($action === 'add') {
        if ($authMethod === 'local' && $password === '') $errors[] = 'Password is required for local-auth users.';
        if ($authMethod === 'local' && $password !== '' && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($authMethod === 'google' && !$googleSsoEnabled) $errors[] = 'Google SSO must be enabled in Settings before assigning it to users.';

        if (empty($errors)) {
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $chk->execute([':email' => $email]);
            if ($chk->fetch()) $errors[] = 'A user with this email already exists.';
        }

        if (empty($errors)) {
            $hash = $authMethod === 'local' ? password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]) : '';
            $ins  = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, auth_method, can_edit_invoices, can_use_pos, is_active, created_at)
                 VALUES (:n, :e, :pw, :r, :am, :cei, :cup, 1, NOW())'
            );
            $ins->execute([':n'=>$name,':e'=>$email,':pw'=>$hash,':r'=>$role,':am'=>$authMethod,':cei'=>$canEditInvoices,':cup'=>$canUsePOS]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'create_user', 'users', $newId, "Created user $email (role=$role, auth=$authMethod)");

            // Send welcome email if requested and SMTP is set up
            if ($sendWelcome && $authMethod === 'local' && $password !== '') {
                $emailSent = sendWelcomeEmail($pdo, $email, $name, $password);
                if ($emailSent) {
                    setFlash('success', "User \"$name\" created and welcome email sent.");
                } else {
                    setFlash('success', "User \"$name\" created. (Welcome email could not be sent — check SMTP settings.)");
                }
            } else {
                setFlash('success', "User \"$name\" created successfully.");
            }
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }

    } elseif ($action === 'edit' && $userId > 0) {
        if ($authMethod === 'google' && !$googleSsoEnabled) {
            $errors[] = 'Google SSO must be enabled in Settings before assigning it to users.';
        }

        if (empty($errors)) {
            if ($authMethod === 'local' && $password !== '') {
                if (strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $upd  = $pdo->prepare('UPDATE users SET name=:n, email=:e, password_hash=:pw, role=:r, auth_method=:am, can_edit_invoices=:cei, can_use_pos=:cup WHERE id=:id');
                    $upd->execute([':n'=>$name,':e'=>$email,':pw'=>$hash,':r'=>$role,':am'=>$authMethod,':cei'=>$canEditInvoices,':cup'=>$canUsePOS,':id'=>$userId]);
                }
            } else {
                $upd = $pdo->prepare('UPDATE users SET name=:n, email=:e, role=:r, auth_method=:am, can_edit_invoices=:cei, can_use_pos=:cup WHERE id=:id');
                $upd->execute([':n'=>$name,':e'=>$email,':r'=>$role,':am'=>$authMethod,':cei'=>$canEditInvoices,':cup'=>$canUsePOS,':id'=>$userId]);
                // If switching to Google SSO, clear password hash and google_id (will be linked on first login)
                if ($authMethod === 'google') {
                    $pdo->prepare('UPDATE users SET google_id = NULL WHERE id = :id AND google_id IS NULL')
                        ->execute([':id' => $userId]);
                }
            }

            if (empty($errors)) {
                logAudit($pdo, 'edit_user', 'users', $userId, "Updated user $email (role=$role, auth=$authMethod)");
                setFlash('success', "User \"$name\" updated successfully.");
                header('Location: ' . BASE_URL . '/admin/users.php');
                exit;
            }
        }
    }
}

// --- List all users ---
$users = $pdo->query(
    'SELECT id, name, email, role, auth_method, is_active, created_at, last_login FROM users ORDER BY name ASC'
)->fetchAll();

$smtpEnabled   = !empty(getSetting($pdo, 'smtp_enabled'));
$googleEnabled = !empty(getSetting($pdo, 'google_sso_enabled'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">User Management</h2>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="two-col-layout">
    <!-- LEFT: Users list -->
    <div class="col-main">
        <div class="card">
            <div class="card-header"><h3 class="card-title">All Users</h3></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Login</th>
                            <th>Edit Invoices</th>
                            <th>POS Access</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr class="<?= !$u['is_active'] ? 'row-inactive' : '' ?>">
                            <td><?= htmlspecialchars($u['name']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge badge-role badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                            <td>
                                <?php if (($u['auth_method'] ?? 'local') === 'google'): ?>
                                    <span title="Google SSO" style="display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;color:#1d4ed8;">
                                        <svg viewBox="0 0 48 48" width="14" height="14"><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.6H24v8.7h12.7c-.5 2.8-2.1 5.2-4.5 6.8v5.6h7.3c4.3-3.9 6.7-9.7 6.7-16.5z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.3-5.6c-2.1 1.4-4.8 2.2-8.6 2.2-6.6 0-12.2-4.4-14.2-10.4H2.3v5.8C6.3 42.6 14.6 48 24 48z"/><path fill="#FBBC05" d="M9.8 28.4c-.5-1.4-.8-2.9-.8-4.4s.3-3 .8-4.4v-5.8H2.3C.8 16.9 0 20.3 0 24s.8 7.1 2.3 10.2l7.5-5.8z"/><path fill="#EA4335" d="M24 9.6c3.7 0 7 1.3 9.6 3.8l7.2-7.2C36.9 2.1 31.5 0 24 0 14.6 0 6.3 5.4 2.3 13.8l7.5 5.8C11.8 14 17.4 9.6 24 9.6z"/></svg>
                                        Google
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:.8rem;color:#6B7280;">Local</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if (!empty($u['can_edit_invoices'])): ?>
                                    <span style="color:#16A34A;font-size:1.1rem;" title="Can edit invoices">✓</span>
                                <?php else: ?>
                                    <span style="color:#D1D5DB;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php
                                $hasPOS = in_array($u['role'], ['admin','superuser']) || !empty($u['can_use_pos']);
                                ?>
                                <?php if ($hasPOS): ?>
                                    <span style="color:#16A34A;font-size:1.1rem;" title="POS access enabled">✓</span>
                                <?php else: ?>
                                    <span style="color:#D1D5DB;" title="Sales invoice only">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-dot <?= $u['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                            </td>
                            <td><?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : '—' ?></td>
                            <td class="action-btns">
                                <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                <a href="<?= BASE_URL ?>/admin/user_permissions.php?user_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline">Permissions</a>
                                <a href="?toggle=<?= $u['id'] ?>"
                                   class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                   onclick="return confirm('Are you sure you want to <?= $u['is_active'] ? 'deactivate' : 'activate' ?> this user?')">
                                    <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Add / Edit form -->
    <div class="col-side">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $editUser ? 'Edit User' : 'Add New User' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate id="user-form">
                    <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'add' ?>">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= htmlspecialchars($editUser['name'] ?? ($_POST['name'] ?? '')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($editUser['email'] ?? ($_POST['email'] ?? '')) ?>" required>
                    </div>

                    <!-- Login Method -->
                    <div class="form-group">
                        <label class="form-label">Login Method</label>
                        <select name="auth_method" class="form-control form-select" id="auth-method-sel">
                            <option value="local" <?= (($editUser['auth_method'] ?? ($_POST['auth_method'] ?? 'local')) === 'local') ? 'selected' : '' ?>>
                                Local Password
                            </option>
                            <option value="google" <?= (($editUser['auth_method'] ?? ($_POST['auth_method'] ?? '')) === 'google') ? 'selected' : '' ?>
                                    <?= !$googleEnabled ? 'disabled' : '' ?>>
                                Google SSO<?= !$googleEnabled ? ' (not enabled in Settings)' : '' ?>
                            </option>
                        </select>
                    </div>

                    <!-- Password field — hidden for Google SSO users -->
                    <div id="password-group" class="form-group">
                        <label class="form-label">Password <?= $editUser ? '(leave blank to keep current)' : '<span class="required">*</span>' ?></label>
                        <input type="password" name="password" class="form-control" id="password-input"
                               placeholder="<?= $editUser ? 'Leave blank to keep current password' : 'Min. 8 characters' ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Role <span class="required">*</span></label>
                        <select name="role" class="form-control form-select">
                            <?php foreach (['user','admin','superuser'] as $r): ?>
                                <option value="<?= $r ?>"
                                    <?= (($editUser['role'] ?? ($_POST['role'] ?? 'user')) === $r) ? 'selected' : '' ?>
                                    <?= ($r === 'superuser' && !isSuperuser()) ? 'disabled' : '' ?>>
                                    <?= ucfirst($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-top:.25rem;">
                        <label class="form-label" style="display:flex;align-items:center;gap:.6rem;cursor:pointer;">
                            <input type="checkbox" name="can_edit_invoices" value="1"
                                   <?= ($editUser['can_edit_invoices'] ?? ($_POST['can_edit_invoices'] ?? 0)) ? 'checked' : '' ?>
                                   style="width:16px;height:16px;cursor:pointer;">
                            Can Edit Posted Invoices
                        </label>
                        <small class="form-hint" style="display:block;margin-left:22px;">
                            Allows this user to edit any invoice.
                        </small>
                    </div>

                    <div class="form-group" style="margin-top:.25rem;">
                        <label class="form-label" style="display:flex;align-items:center;gap:.6rem;cursor:pointer;">
                            <input type="checkbox" name="can_use_pos" value="1"
                                   <?= ($editUser['can_use_pos'] ?? ($_POST['can_use_pos'] ?? 1)) ? 'checked' : '' ?>
                                   style="width:16px;height:16px;cursor:pointer;"
                                   id="can-use-pos-chk">
                            POS Access
                        </label>
                        <small class="form-hint" style="display:block;margin-left:22px;">
                            Unchecked = user is redirected to the Sales Invoice page instead of POS.
                            Admins always have POS access regardless.
                        </small>
                    </div>

                    <?php if (!$editUser && $smtpEnabled): ?>
                    <div class="form-group" style="margin-top:.25rem;">
                        <label class="form-label" style="display:flex;align-items:center;gap:.6rem;cursor:pointer;">
                            <input type="checkbox" name="send_welcome" value="1" checked
                                   id="send-welcome-chk" style="width:16px;height:16px;cursor:pointer;">
                            Send welcome email with login details
                        </label>
                        <small class="form-hint" style="display:block;margin-left:22px;">
                            Emails the user their temporary password. Only works for local-auth users.
                        </small>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?= $editUser ? 'Update User' : 'Create User' ?>
                        </button>
                        <?php if ($editUser): ?>
                            <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const sel       = document.getElementById('auth-method-sel');
    const pwdGroup  = document.getElementById('password-group');
    const pwdInput  = document.getElementById('password-input');
    const welcomeChk = document.getElementById('send-welcome-chk');

    function togglePwd(){
        const isGoogle = sel.value === 'google';
        pwdGroup.style.display = isGoogle ? 'none' : '';
        if (isGoogle && pwdInput) pwdInput.value = '';
        if (welcomeChk) welcomeChk.closest('.form-group').style.display = isGoogle ? 'none' : '';
    }

    if (sel) { sel.addEventListener('change', togglePwd); togglePwd(); }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
