<?php
// ============================================================
// Blackview SA Portal — CRM: Customer Detail
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Customer';

$customerId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($customerId === 0) {
    setFlash('error', 'Invalid customer ID.');
    header('Location: ' . BASE_URL . '/crm/index.php');
    exit;
}

// Load customer
$custStmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
$custStmt->execute([':id' => $customerId]);
$customer = $custStmt->fetch();
if (!$customer) {
    setFlash('error', 'Customer not found.');
    header('Location: ' . BASE_URL . '/crm/index.php');
    exit;
}

$editErrors = [];

// ============================================================
// POST: Update customer details
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_customer') {
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
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $editErrors[] = 'Invalid email address.';
    }
    if (!in_array($contactType, ['individual','business'], true)) $contactType = 'individual';

    if (empty($editErrors)) {
        try {
            $pdo->prepare(
                "UPDATE customers SET
                    name=:n, email=:e, phone=:p, address=:a, id_number=:i,
                    contact_type=:ct, company_name=:cn, vat_no=:vn, notes=:notes
                 WHERE id=:id"
            )->execute([
                ':n'     => $name,    ':e'  => $email,   ':p'  => $phone,
                ':a'     => $address, ':i'  => $idNumber, ':ct' => $contactType,
                ':cn'    => $company, ':vn' => $vatNo,
                ':notes' => $notes,   ':id' => $customerId,
            ]);
            logAudit($pdo, 'crm_edit_customer', 'customers', $customerId, "Updated customer: $name");
            setFlash('success', 'Customer updated.');
            header('Location: ' . BASE_URL . '/crm/customer.php?id=' . $customerId);
            exit;
        } catch (Throwable $e) {
            $editErrors[] = 'Save failed: ' . $e->getMessage();
        }
    }
    // Reload customer with POST values on error
    $customer = array_merge($customer, [
        'name'         => $name,
        'contact_type' => $contactType,
        'company_name' => $company,
        'vat_no'       => $vatNo,
        'email'        => $email,
        'phone'        => $phone,
        'address'      => $address,
        'id_number'    => $idNumber,
        'notes'        => $notes,
    ]);
}

// ============================================================
// Load invoices for this customer
// ============================================================
$invoices = [];
try {
    $invStmt = $pdo->prepare(
        "SELECT id, invoice_no, total, payment_method, channel, created_at, notes
         FROM invoices WHERE customer_id = :cid ORDER BY created_at DESC LIMIT 100"
    );
    $invStmt->execute([':cid' => $customerId]);
    $invoices = $invStmt->fetchAll();
} catch (Throwable $e) { /* ignore */ }

// Load payments for balance calculation
$invoiceIds = array_column($invoices, 'id');
$paidMap = [];
if (!empty($invoiceIds)) {
    try {
        $paidStmt = $pdo->query(
            "SELECT invoice_id, SUM(amount) AS paid
             FROM invoice_payments
             WHERE invoice_id IN (" . implode(',', array_map('intval', $invoiceIds)) . ")
             GROUP BY invoice_id"
        );
        foreach ($paidStmt->fetchAll() as $row) {
            $paidMap[$row['invoice_id']] = (float)$row['paid'];
        }
    } catch (Throwable $e) { /* ignore */ }
}

// ============================================================
// Load quotes for this customer (matched by email or name)
// ============================================================
$quotes = [];
try {
    $qWhere = [];
    $qParams = [];
    if (!empty($customer['email'])) {
        $qWhere[]  = "customer_email = :ce";
        $qParams[':ce'] = $customer['email'];
    } else {
        $qWhere[]  = "customer_name = :cn";
        $qParams[':cn'] = $customer['name'];
    }
    $qStmt = $pdo->prepare(
        "SELECT id, quote_no, total, status, channel, created_at
         FROM quotes WHERE " . implode(' OR ', $qWhere) . "
         ORDER BY created_at DESC LIMIT 50"
    );
    $qStmt->execute($qParams);
    $quotes = $qStmt->fetchAll();
} catch (Throwable $e) { /* ignore */ }

// Summary stats
$totalInvoices  = count($invoices);
$totalSpend     = array_sum(array_column($invoices, 'total'));
$totalQuotes    = count($quotes);
$firstActivity  = !empty($invoices) ? min(array_column($invoices, 'created_at')) : ($customer['created_at'] ?? null);
$lastActivity   = !empty($invoices) ? max(array_column($invoices, 'created_at')) : null;

$pageTitle = 'CRM — ' . htmlspecialchars($customer['name']);
$activeTab = $_GET['tab'] ?? 'invoices';
if (!in_array($activeTab, ['invoices','quotes','summary'], true)) $activeTab = 'invoices';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div>
        <h2 class="page-title"><?= htmlspecialchars($customer['name']) ?></h2>
        <p class="page-subtitle">
            <?php if (!empty($customer['company_name'])): ?>
                <?= htmlspecialchars($customer['company_name']) ?> &nbsp;·&nbsp;
            <?php endif; ?>
            <?= ($customer['contact_type'] ?? 'individual') === 'business' ? 'Business' : 'Individual' ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/pos/index.php?crm_customer_id=<?= $customerId ?>" class="btn btn-primary btn-sm">+ New Invoice</a>
        <a href="<?= BASE_URL ?>/pos/quotes.php?new=1&crm_customer_id=<?= $customerId ?>" class="btn btn-outline btn-sm">+ New Quote</a>
        <a href="<?= BASE_URL ?>/crm/index.php" class="btn btn-outline btn-sm">← All Customers</a>
    </div>
</div>

<?php foreach ($editErrors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="two-col-layout">

<!-- LEFT: Contact card -->
<div class="col-side">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Contact Details</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_customer">

                <div class="form-group">
                    <label class="form-label">Contact Type</label>
                    <select name="contact_type" class="form-control form-select" id="cust-contact-type"
                            onchange="toggleBizFields(this.value)">
                        <option value="individual" <?= ($customer['contact_type'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Individual</option>
                        <option value="business"   <?= ($customer['contact_type'] ?? 'individual') === 'business'   ? 'selected' : '' ?>>Business</option>
                    </select>
                </div>

                <div id="biz-fields" style="display:<?= ($customer['contact_type'] ?? 'individual') === 'business' ? 'block' : 'none' ?>">
                    <div class="form-group">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control"
                               value="<?= htmlspecialchars($customer['company_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">VAT Number</label>
                        <input type="text" name="vat_no" class="form-control"
                               value="<?= htmlspecialchars($customer['vat_no'] ?? '') ?>" placeholder="e.g. 4123456789">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($customer['name']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Account No</label>
                    <input type="text" name="id_number" class="form-control"
                           value="<?= htmlspecialchars($customer['id_number'] ?? '') ?>"
                           style="font-family:monospace;letter-spacing:.03em;">
                </div>

                <div class="form-group">
                    <label class="form-label">Internal Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($customer['notes'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1rem;">
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.5rem;font-weight:700;color:#2563EB;"><?= $totalInvoices ?></div>
            <div style="font-size:.8rem;color:#6B7280;margin-top:.2rem;">Invoices</div>
        </div>
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.5rem;font-weight:700;color:#059669;">R <?= number_format($totalSpend, 0) ?></div>
            <div style="font-size:.8rem;color:#6B7280;margin-top:.2rem;">Total Spend</div>
        </div>
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.5rem;font-weight:700;color:#7C3AED;"><?= $totalQuotes ?></div>
            <div style="font-size:.8rem;color:#6B7280;margin-top:.2rem;">Quotes</div>
        </div>
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.1rem;font-weight:600;color:#374151;"><?= $lastActivity ? date('d M', strtotime($lastActivity)) : '—' ?></div>
            <div style="font-size:.8rem;color:#6B7280;margin-top:.2rem;">Last Activity</div>
        </div>
    </div>
</div>

<!-- RIGHT: Tabs -->
<div class="col-main">
    <!-- Tab nav -->
    <div style="display:flex;gap:0;border-bottom:2px solid #E5E7EB;margin-bottom:1rem;">
        <?php foreach (['invoices'=>'Invoices','quotes'=>'Quotes','summary'=>'Summary'] as $tab => $label): ?>
        <a href="?id=<?= $customerId ?>&tab=<?= $tab ?>"
           style="padding:.6rem 1.2rem;font-weight:600;font-size:.95rem;text-decoration:none;
                  color:<?= $activeTab === $tab ? '#2563EB' : '#6B7280' ?>;
                  border-bottom:<?= $activeTab === $tab ? '2px solid #2563EB' : '2px solid transparent' ?>;
                  margin-bottom:-2px;">
            <?= $label ?>
            <?php if ($tab === 'invoices' && $totalInvoices > 0): ?>
                <span style="background:#DBEAFE;color:#1D4ED8;border-radius:10px;padding:.1rem .4rem;font-size:.78rem;margin-left:.3rem;"><?= $totalInvoices ?></span>
            <?php elseif ($tab === 'quotes' && $totalQuotes > 0): ?>
                <span style="background:#EDE9FE;color:#7C3AED;border-radius:10px;padding:.1rem .4rem;font-size:.78rem;margin-left:.3rem;"><?= $totalQuotes ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($activeTab === 'invoices'): ?>
    <!-- INVOICES TAB -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Invoice History</h3>
            <a href="<?= BASE_URL ?>/pos/index.php?crm_customer_id=<?= $customerId ?>" class="btn btn-sm btn-primary">+ New Invoice</a>
        </div>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th style="text-align:right;">Total</th>
                        <th style="text-align:right;">Paid</th>
                        <th>Status</th>
                        <th>Channel</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#9CA3AF;padding:1.5rem;">No invoices yet.</td></tr>
                    <?php else: foreach ($invoices as $inv):
                        $paid    = $paidMap[$inv['id']] ?? 0;
                        $balance = round((float)$inv['total'] - $paid, 2);
                        $isPaid  = $balance <= 0.001;
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($inv['invoice_no']) ?></td>
                        <td style="color:#6B7280;font-size:.9rem;"><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
                        <td style="text-align:right;">R <?= number_format((float)$inv['total'], 2) ?></td>
                        <td style="text-align:right;color:<?= $isPaid ? '#16A34A' : '#DC2626' ?>;">R <?= number_format($paid, 2) ?></td>
                        <td>
                            <?php if ($isPaid): ?>
                            <span style="background:#DCFCE7;color:#16A34A;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;">Paid</span>
                            <?php else: ?>
                            <span style="background:#FEF2F2;color:#DC2626;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;">R <?= number_format($balance,2) ?> owed</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem;color:#6B7280;"><?= ucfirst($inv['channel'] ?? '—') ?></td>
                        <td><a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($activeTab === 'quotes'): ?>
    <!-- QUOTES TAB -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Quote History</h3>
            <a href="<?= BASE_URL ?>/pos/quotes.php?new=1&crm_customer_id=<?= $customerId ?>" class="btn btn-sm btn-outline">+ New Quote</a>
        </div>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Quote #</th>
                        <th>Date</th>
                        <th style="text-align:right;">Total</th>
                        <th>Status</th>
                        <th>Channel</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quotes)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#9CA3AF;padding:1.5rem;">No quotes yet.</td></tr>
                    <?php else: foreach ($quotes as $q):
                        $statusColors = ['draft'=>'#6B7280','sent'=>'#2563EB','accepted'=>'#16A34A','declined'=>'#DC2626','expired'=>'#EA580C'];
                        $statusBg     = ['draft'=>'#F3F4F6','sent'=>'#DBEAFE','accepted'=>'#DCFCE7','declined'=>'#FEF2F2','expired'=>'#FFF7ED'];
                        $sc = $statusColors[$q['status']] ?? '#6B7280';
                        $sb = $statusBg[$q['status']]     ?? '#F3F4F6';
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($q['quote_no']) ?></td>
                        <td style="color:#6B7280;font-size:.9rem;"><?= date('d M Y', strtotime($q['created_at'])) ?></td>
                        <td style="text-align:right;">R <?= number_format((float)$q['total'], 2) ?></td>
                        <td><span style="background:<?= $sb ?>;color:<?= $sc ?>;padding:.15rem .5rem;border-radius:4px;font-size:.8rem;"><?= ucfirst($q['status']) ?></span></td>
                        <td style="font-size:.85rem;color:#6B7280;"><?= ucfirst($q['channel'] ?? '—') ?></td>
                        <td><a href="<?= BASE_URL ?>/pos/quote_view.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- SUMMARY TAB -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Account Summary</h3></div>
        <div class="card-body">
            <table style="width:100%;font-size:.95rem;border-collapse:collapse;">
                <?php
                $summaryRows = [
                    ['Total Invoices',    $totalInvoices],
                    ['Total Spend',       'R ' . number_format($totalSpend, 2)],
                    ['Average Invoice',   $totalInvoices > 0 ? 'R ' . number_format($totalSpend / $totalInvoices, 2) : '—'],
                    ['Total Quotes',      $totalQuotes],
                    ['Accepted Quotes',   count(array_filter($quotes, fn($q) => $q['status'] === 'accepted'))],
                    ['First Activity',    $firstActivity ? date('d M Y', strtotime($firstActivity)) : '—'],
                    ['Last Activity',     $lastActivity  ? date('d M Y', strtotime($lastActivity))  : '—'],
                    ['Customer Since',    $customer['created_at'] ? date('d M Y', strtotime($customer['created_at'])) : '—'],
                ];
                foreach ($summaryRows as $row): ?>
                <tr style="border-bottom:1px solid #F3F4F6;">
                    <td style="padding:.6rem .5rem;color:#6B7280;width:50%;"><?= $row[0] ?></td>
                    <td style="padding:.6rem .5rem;font-weight:600;"><?= $row[1] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- .col-main -->

</div><!-- .two-col-layout -->

<script>
function toggleBizFields(type) {
    var biz = document.getElementById('biz-fields');
    if (biz) biz.style.display = (type === 'business') ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
