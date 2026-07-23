<?php
// ============================================================
// Blackview SA Portal — CRM: Customer List
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'CRM — Customers';

// ============================================================
// AJAX: get single customer for modal
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_customer') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['id'] ?? 0);
    if (!$cid) { echo json_encode(['error' => 'Invalid ID']); exit; }
    try {
        $s = $pdo->prepare(
            "SELECT c.*,
                    COUNT(DISTINCT inv.id)      AS invoice_count,
                    COALESCE(SUM(inv.total), 0) AS total_spend,
                    MAX(inv.created_at)         AS last_invoice
             FROM customers c
             LEFT JOIN invoices inv ON inv.customer_id = c.id
             WHERE c.id = :id GROUP BY c.id LIMIT 1"
        );
        $s->execute([':id' => $cid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        echo $row ? json_encode($row) : json_encode(['error' => 'Not found']);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// POST: Add Customer
// ============================================================
$addErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_customer') {
    $name        = trim($_POST['name']         ?? '');
    $contactType = trim($_POST['contact_type'] ?? 'individual');
    $company     = trim($_POST['company_name'] ?? '');
    $vatNo       = trim($_POST['vat_no']       ?? '');
    $email       = trim($_POST['email']        ?? '');
    $phone       = trim($_POST['phone']        ?? '');
    $address     = trim($_POST['address']      ?? '');
    $idNumber    = trim($_POST['id_number']    ?? '');
    $notes       = trim($_POST['notes']        ?? '');

    if ($name === '') $addErrors[] = 'Contact name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $addErrors[] = 'Invalid email address.';
    }
    if (!in_array($contactType, ['individual','business'], true)) $contactType = 'individual';

    if (empty($addErrors) && $email !== '') {
        $dup = $pdo->prepare('SELECT id FROM customers WHERE email=:e LIMIT 1');
        $dup->execute([':e' => $email]);
        if ($dup->fetch()) $addErrors[] = 'A customer with this email already exists.';
    }

    if (empty($addErrors)) {
        try {
            $pdo->prepare(
                "INSERT INTO customers (name, email, phone, address, id_number,
                                        contact_type, company_name, vat_no, notes, created_by, created_at)
                 VALUES (:n,:e,:p,:a,:i,:ct,:cn,:vn,:notes,:uid,NOW())"
            )->execute([
                ':n'     => $name,   ':e'     => $email,   ':p'  => $phone,
                ':a'     => $address,':i'     => $idNumber,':ct' => $contactType,
                ':cn'    => $company,':vn'    => $vatNo,   ':notes' => $notes,
                ':uid'   => $_SESSION['user_id'],
            ]);
            $newCustId = (int)$pdo->lastInsertId();
            logAudit($pdo, 'crm_add_customer', 'customers', $newCustId, "Added customer: $name");
            setFlash('success', "Customer \"$name\" added.");
            header('Location: ' . BASE_URL . '/crm/customer.php?id=' . $newCustId);
            exit;
        } catch (Throwable $e) {
            $addErrors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// ============================================================
// POST: Update Customer (from modal)
// ============================================================
$editErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_customer') {
    $custId      = (int)($_POST['customer_id'] ?? 0);
    $name        = trim($_POST['name']         ?? '');
    $contactType = trim($_POST['contact_type'] ?? 'individual');
    $company     = trim($_POST['company_name'] ?? '');
    $vatNo       = trim($_POST['vat_no']       ?? '');
    $email       = trim($_POST['email']        ?? '');
    $phone       = trim($_POST['phone']        ?? '');
    $address     = trim($_POST['address']      ?? '');
    $idNumber    = trim($_POST['id_number']    ?? '');
    $notes       = trim($_POST['notes']        ?? '');

    if ($name === '') $editErrors[] = 'Contact name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $editErrors[] = 'Invalid email address.';
    if (!in_array($contactType, ['individual','business'], true)) $contactType = 'individual';

    if (empty($editErrors) && $custId > 0) {
        try {
            $pdo->prepare(
                "UPDATE customers SET name=:n,email=:e,phone=:p,address=:a,id_number=:i,
                 contact_type=:ct,company_name=:cn,vat_no=:vn,notes=:notes WHERE id=:id"
            )->execute([
                ':n'=>$name,':e'=>$email,':p'=>$phone,':a'=>$address,':i'=>$idNumber,
                ':ct'=>$contactType,':cn'=>$company,':vn'=>$vatNo,':notes'=>$notes,':id'=>$custId,
            ]);
            logAudit($pdo, 'crm_edit_customer', 'customers', $custId, "Updated customer: $name");
            setFlash('success', "Customer \"$name\" updated.");
            header('Location: ' . BASE_URL . '/crm/index.php');
            exit;
        } catch (Throwable $e) {
            $editErrors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

// ============================================================
// Filters
// ============================================================
$search     = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type']   ?? 'all');
if (!in_array($typeFilter, ['all','individual','business'], true)) $typeFilter = 'all';

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]       = "(c.name LIKE :s OR c.email LIKE :s2 OR c.company_name LIKE :s3 OR c.phone LIKE :s4)";
    $params[':s']  = '%'.$search.'%'; $params[':s2'] = '%'.$search.'%';
    $params[':s3'] = '%'.$search.'%'; $params[':s4'] = '%'.$search.'%';
}
if ($typeFilter !== 'all') {
    $where[]     = "c.contact_type = :ct";
    $params[':ct'] = $typeFilter;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$customers  = [];
$hasColumns = true;
try {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.email, c.phone, c.contact_type,
                COALESCE(c.company_name,'') AS company_name,
                c.created_at, c.xero_id,
                COUNT(DISTINCT inv.id)      AS invoice_count,
                COALESCE(SUM(inv.total), 0) AS total_spend,
                MAX(inv.created_at)         AS last_invoice
         FROM customers c
         LEFT JOIN invoices inv ON inv.customer_id = c.id
         $whereSQL
         GROUP BY c.id ORDER BY c.name ASC LIMIT 250"
    );
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} catch (Throwable $e) {
    $hasColumns = false;
    try {
        // Fallback without xero_id (migration_023 not run) and without CRM columns
        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.email, c.phone,
                    'individual' AS contact_type, '' AS company_name, c.created_at,
                    NULL AS xero_id,
                    COUNT(DISTINCT inv.id) AS invoice_count,
                    COALESCE(SUM(inv.total),0) AS total_spend, MAX(inv.created_at) AS last_invoice
             FROM customers c LEFT JOIN invoices inv ON inv.customer_id = c.id
             WHERE (c.name LIKE :s OR c.email LIKE :s2 OR c.phone LIKE :s4)
             GROUP BY c.id ORDER BY c.name ASC LIMIT 250"
        );
        $stmt->execute([':s'=>'%'.$search.'%',':s2'=>'%'.$search.'%',':s4'=>'%'.$search.'%']);
        $customers = $stmt->fetchAll();
    } catch (Throwable $e2) {}
}

// Is Xero connected? (controls whether we show the Sync column meaningfully)
$xeroConnected = false;
try {
    $xeroConnected = (bool)$pdo->query("SELECT 1 FROM xero_oauth_tokens WHERE id=1 AND refresh_token IS NOT NULL")->fetchColumn();
} catch (Throwable $e) { /* table not migrated */ }

// Re-open edit modal on error
$reopenEditId = 0;
if (!empty($editErrors) && isset($_POST['customer_id'])) {
    $reopenEditId = (int)$_POST['customer_id'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 class="page-title">CRM — Customers</h2>
        <p class="page-subtitle">Click any customer to view and edit details.</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Add Customer</button>
</div>

<?php if (!empty($addErrors)): ?>
<div class="alert alert-error"><?php foreach ($addErrors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if (!empty($editErrors)): ?>
<div class="alert alert-error"><?php foreach ($editErrors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$hasColumns): ?>
<div class="alert alert-warning">⚠️ CRM columns not yet applied. Run <strong>migration_009.sql</strong> in phpMyAdmin.</div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:1rem;">
    <div class="card-body" style="padding:.75rem 1rem;">
        <form method="GET" action="" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                <label class="form-label" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Name, email, company, phone…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:.8rem;">Type</label>
                <select name="type" class="form-control form-select" style="min-width:140px;">
                    <option value="all"        <?= $typeFilter==='all'        ?'selected':'' ?>>All Types</option>
                    <option value="individual" <?= $typeFilter==='individual' ?'selected':'' ?>>Individual</option>
                    <option value="business"   <?= $typeFilter==='business'   ?'selected':'' ?>>Business</option>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?= BASE_URL ?>/crm/index.php" class="btn btn-outline btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Customer Table -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <h3 class="card-title" style="margin:0;">
            Customers <span style="font-size:.85rem;font-weight:400;color:#6B7280;">(<?= count($customers) ?>)</span>
        </h3>
        <input type="text" id="crm-filter" class="form-control" style="max-width:260px;"
               placeholder="Quick filter…" autocomplete="off">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="crm-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Company</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Last Invoice</th>
                    <th style="text-align:right;">Total Spend</th>
                    <th style="text-align:center;">#&nbsp;Inv</th>
                    <th style="text-align:center;">Xero</th>
                    <th style="width:110px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr><td colspan="10" style="text-align:center;color:#9CA3AF;padding:2rem;">
                    No customers found. <?= $search ? 'Try a different search.' : 'Click <strong>+ Add Customer</strong> to get started.' ?>
                </td></tr>
                <?php else: foreach ($customers as $c): ?>
                <tr class="crm-row" style="cursor:pointer;" data-id="<?= $c['id'] ?>"
                    onclick="openEditModal(<?= $c['id'] ?>)">
                    <td style="font-weight:500;"><?= htmlspecialchars($c['name']) ?></td>
                    <td>
                        <?php if (($c['contact_type'] ?? 'individual') === 'business'): ?>
                        <span style="background:#DBEAFE;color:#1D4ED8;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;font-weight:600;">Business</span>
                        <?php else: ?>
                        <span style="background:#F3F4F6;color:#374151;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;">Individual</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#6B7280;"><?= htmlspecialchars($c['company_name'] ?: '—') ?></td>
                    <td style="color:#6B7280;"><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                    <td style="color:#6B7280;"><?= htmlspecialchars($c['email'] ?: '—') ?></td>
                    <td style="font-size:.9rem;color:#6B7280;"><?= $c['last_invoice'] ? date('d M Y', strtotime($c['last_invoice'])) : '—' ?></td>
                    <td style="text-align:right;font-weight:600;">R <?= number_format((float)$c['total_spend'], 2) ?></td>
                    <td style="text-align:center;color:#6B7280;"><?= (int)$c['invoice_count'] ?></td>
                    <td style="text-align:center;">
                        <?php if (!empty($c['xero_id'])): ?>
                            <span title="Synced to Xero" style="background:#DCFCE7;color:#16A34A;padding:.15rem .5rem;border-radius:12px;font-size:.75rem;font-weight:600;white-space:nowrap;">✓ Synced</span>
                        <?php else: ?>
                            <span title="Not yet on Xero — will sync on next run" style="background:#FEF3C7;color:#92400E;padding:.15rem .5rem;border-radius:12px;font-size:.75rem;font-weight:600;white-space:nowrap;">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="action-btns" onclick="event.stopPropagation()">
                        <a href="<?= BASE_URL ?>/pos/index.php?crm_customer_id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-outline">New Invoice</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ============================================================
     MODAL: Add Customer
     ============================================================ -->
<div id="add-cust-modal" style="display:none;position:fixed;inset:0;z-index:10000;overflow-y:auto;padding:1.5rem 1rem;">
    <div style="position:fixed;inset:0;background:rgba(15,23,42,.45);" onclick="closeAddModal()"></div>
    <div style="position:relative;margin:0 auto;max-width:600px;background:#fff;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;">

        <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.5rem;border-bottom:1px solid #E5E7EB;background:#F8FAFC;">
            <h3 style="margin:0;font-size:1.05rem;font-weight:700;">Add Customer</h3>
            <button type="button" onclick="closeAddModal()"
                    style="background:none;border:none;width:32px;height:32px;border-radius:6px;cursor:pointer;color:#9CA3AF;font-size:1.3rem;line-height:1;display:flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">&times;</button>
        </div>

        <div style="padding:1.5rem;max-height:calc(100vh - 8rem);overflow-y:auto;">
            <form method="POST" action="" novalidate>
                <input type="hidden" name="action" value="add_customer">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Contact Type</label>
                        <select name="contact_type" class="form-control form-select" id="add-ct"
                                onchange="toggleAddBiz(this.value)">
                            <option value="individual">Individual</option>
                            <option value="business">Business</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;" id="add-company-wrap" style="display:none;">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" id="add-company" class="form-control" placeholder="Company / Trading As">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" name="name" id="add-name" class="form-control" placeholder="Contact person" required>
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;" id="add-vat-wrap" style="display:none;">
                        <label class="form-label">VAT Number</label>
                        <input type="text" name="vat_no" class="form-control" placeholder="e.g. 4123456789">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                            Account No <span style="font-size:.75rem;color:#9CA3AF;font-weight:400;">auto-generated</span>
                        </label>
                        <input type="text" name="id_number" id="crm-add-accno" class="form-control"
                               placeholder="e.g. JOHN-202605-001" style="font-family:monospace;letter-spacing:.03em;">
                    </div>
                </div>

                <div class="form-group" style="margin:0 0 1.25rem;">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>

                <div style="display:flex;gap:.75rem;padding-top:1rem;border-top:1px solid #F1F5F9;">
                    <button type="submit" class="btn btn-primary">Add Customer</button>
                    <button type="button" class="btn btn-outline" onclick="closeAddModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ============================================================
     MODAL: Edit / View Customer
     ============================================================ -->
<div id="edit-cust-modal" style="display:none;position:fixed;inset:0;z-index:10000;overflow-y:auto;padding:1.5rem 1rem;">
    <div style="position:fixed;inset:0;background:rgba(15,23,42,.45);" onclick="closeEditModal()"></div>
    <div style="position:relative;margin:0 auto;max-width:660px;background:#fff;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.5rem;border-bottom:1px solid #E5E7EB;background:#F8FAFC;">
            <div>
                <h3 id="ecm-title" style="margin:0;font-size:1.05rem;font-weight:700;">Customer</h3>
                <div id="ecm-subtitle" style="font-size:.8rem;color:#9CA3AF;margin-top:.15rem;"></div>
            </div>
            <button type="button" onclick="closeEditModal()"
                    style="background:none;border:none;width:32px;height:32px;border-radius:6px;cursor:pointer;color:#9CA3AF;font-size:1.3rem;line-height:1;display:flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">&times;</button>
        </div>

        <!-- Loading state -->
        <div id="ecm-loading" style="padding:3rem;text-align:center;color:#9CA3AF;">Loading…</div>

        <!-- Content (hidden until loaded) -->
        <div id="ecm-content" style="display:none;">

            <!-- Quick stats bar -->
            <div id="ecm-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:0;border-bottom:1px solid #E5E7EB;">
                <div style="padding:.85rem 1rem;text-align:center;border-right:1px solid #E5E7EB;">
                    <div id="ecm-stat-invoices" style="font-size:1.4rem;font-weight:700;color:#2563EB;">—</div>
                    <div style="font-size:.75rem;color:#9CA3AF;margin-top:.1rem;">Invoices</div>
                </div>
                <div style="padding:.85rem 1rem;text-align:center;border-right:1px solid #E5E7EB;">
                    <div id="ecm-stat-spend" style="font-size:1.4rem;font-weight:700;color:#059669;">—</div>
                    <div style="font-size:.75rem;color:#9CA3AF;margin-top:.1rem;">Total Spend</div>
                </div>
                <div style="padding:.85rem 1rem;text-align:center;">
                    <div id="ecm-stat-last" style="font-size:1.1rem;font-weight:600;color:#374151;">—</div>
                    <div style="font-size:.75rem;color:#9CA3AF;margin-top:.1rem;">Last Invoice</div>
                </div>
            </div>

            <!-- Edit form -->
            <div style="padding:1.5rem;max-height:calc(100vh - 16rem);overflow-y:auto;">
                <form id="ecm-form" method="POST" action="" novalidate>
                    <input type="hidden" name="action"      value="update_customer">
                    <input type="hidden" name="customer_id" id="ecm-id" value="">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group" style="margin:0 0 1rem;">
                            <label class="form-label">Contact Type</label>
                            <select name="contact_type" id="ecm-ct" class="form-control form-select"
                                    onchange="toggleEditBiz(this.value)">
                                <option value="individual">Individual</option>
                                <option value="business">Business</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0 0 1rem;" id="ecm-company-wrap">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" id="ecm-company" class="form-control" placeholder="Company / Trading As">
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group" style="margin:0 0 1rem;">
                            <label class="form-label">Full Name <span class="required">*</span></label>
                            <input type="text" name="name" id="ecm-name" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin:0 0 1rem;" id="ecm-vat-wrap">
                            <label class="form-label">VAT Number</label>
                            <input type="text" name="vat_no" id="ecm-vat" class="form-control" placeholder="e.g. 4123456789">
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group" style="margin:0 0 1rem;">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="ecm-email" class="form-control">
                        </div>
                        <div class="form-group" style="margin:0 0 1rem;">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="ecm-phone" class="form-control">
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group" style="margin:0 0 1rem;">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="ecm-address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group" style="margin:0 0 1rem;">
                            <label class="form-label">Account No</label>
                            <input type="text" name="id_number" id="ecm-idnum" class="form-control"
                                   style="font-family:monospace;letter-spacing:.03em;">
                        </div>
                    </div>

                    <div class="form-group" style="margin:0 0 1.25rem;">
                        <label class="form-label">Internal Notes</label>
                        <textarea name="notes" id="ecm-notes" class="form-control" rows="2"></textarea>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.6rem;padding-top:1rem;border-top:1px solid #F1F5F9;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                        <div style="flex:1;"></div>
                        <a id="ecm-profile-link" href="#" class="btn btn-outline btn-sm">Full Profile &amp; History →</a>
                        <a id="ecm-invoice-link" href="#" class="btn btn-sm btn-primary">+ New Invoice</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


<script>
var BASE_URL = '<?= BASE_URL ?>';

// ---- Quick filter ----
document.getElementById('crm-filter').addEventListener('input', function(){
    var q = this.value.toLowerCase();
    document.querySelectorAll('#crm-table tbody .crm-row').forEach(function(row){
        row.style.display = row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
});

// ---- Add modal ----
var addModal  = document.getElementById('add-cust-modal');
var editModal = document.getElementById('edit-cust-modal');

function openAddModal() {
    addModal.style.display = '';
    document.body.style.overflow = 'hidden';
    setTimeout(function(){ document.getElementById('add-name').focus(); }, 50);
}
function closeAddModal() {
    addModal.style.display = 'none';
    document.body.style.overflow = '';
}
function toggleAddBiz(type) {
    var show = type === 'business';
    document.getElementById('add-company-wrap').style.display = show ? '' : 'none';
    document.getElementById('add-vat-wrap').style.display     = show ? '' : 'none';
}

// ---- Edit modal ----
function openEditModal(custId) {
    // Show modal with loading state
    document.getElementById('ecm-loading').style.display = '';
    document.getElementById('ecm-content').style.display = 'none';
    editModal.style.display = '';
    document.body.style.overflow = 'hidden';

    fetch(BASE_URL + '/crm/index.php?ajax=get_customer&id=' + custId)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) {
                document.getElementById('ecm-loading').textContent = 'Error: ' + d.error;
                return;
            }
            populateEditModal(d);
        })
        .catch(function(){
            document.getElementById('ecm-loading').textContent = 'Network error. Please try again.';
        });
}

function populateEditModal(d) {
    document.getElementById('ecm-id').value      = d.id;
    document.getElementById('ecm-name').value    = d.name        || '';
    document.getElementById('ecm-email').value   = d.email       || '';
    document.getElementById('ecm-phone').value   = d.phone       || '';
    document.getElementById('ecm-address').value = d.address     || '';
    document.getElementById('ecm-idnum').value   = d.id_number   || '';
    document.getElementById('ecm-notes').value   = d.notes       || '';
    document.getElementById('ecm-company').value = d.company_name|| '';
    document.getElementById('ecm-vat').value     = d.vat_no      || '';

    // Contact type
    var ct = d.contact_type || 'individual';
    document.getElementById('ecm-ct').value = ct;
    toggleEditBiz(ct);

    // Header
    document.getElementById('ecm-title').textContent = d.name || 'Customer';
    var sub = ct === 'business' && d.company_name ? d.company_name + ' · Business' : (ct === 'business' ? 'Business' : 'Individual');
    document.getElementById('ecm-subtitle').textContent = sub;

    // Stats
    document.getElementById('ecm-stat-invoices').textContent = d.invoice_count || '0';
    document.getElementById('ecm-stat-spend').textContent    = 'R ' + parseFloat(d.total_spend || 0).toLocaleString('en-ZA', {minimumFractionDigits:0,maximumFractionDigits:0});
    document.getElementById('ecm-stat-last').textContent     = d.last_invoice ? formatDate(d.last_invoice) : '—';

    // Action links
    document.getElementById('ecm-profile-link').href  = BASE_URL + '/crm/customer.php?id=' + d.id;
    document.getElementById('ecm-invoice-link').href  = BASE_URL + '/pos/index.php?crm_customer_id=' + d.id;

    document.getElementById('ecm-loading').style.display  = 'none';
    document.getElementById('ecm-content').style.display  = '';
}

function toggleEditBiz(type) {
    var show = type === 'business';
    document.getElementById('ecm-company-wrap').style.display = show ? '' : 'none';
    document.getElementById('ecm-vat-wrap').style.display     = show ? '' : 'none';
}

function closeEditModal() {
    editModal.style.display = 'none';
    document.body.style.overflow = '';
}

function formatDate(dt) {
    var d = new Date(dt.replace(' ', 'T'));
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
}

// Escape key
document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    closeEditModal();
    closeAddModal();
});

// Account No auto-generation
(function(){
    var nameIn = document.getElementById('add-name');
    var accIn  = document.getElementById('crm-add-accno');
    if (!nameIn || !accIn) return;
    var _t = null;
    nameIn.addEventListener('input', function(){
        clearTimeout(_t);
        var n = this.value.trim();
        if (n.length < 2) return;
        _t = setTimeout(function(){
            fetch(BASE_URL + '/pos/index.php?ajax=gen_account_no&name=' + encodeURIComponent(n))
                .then(function(r){ return r.json(); })
                .then(function(d){ if (d.account_no) accIn.value = d.account_no; })
                .catch(function(){});
        }, 450);
    });
}());

<?php if (!empty($addErrors)): ?>
window.addEventListener('load', function(){ openAddModal(); });
<?php endif; ?>

<?php if ($reopenEditId > 0): ?>
window.addEventListener('load', function(){ openEditModal(<?= $reopenEditId ?>); });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
