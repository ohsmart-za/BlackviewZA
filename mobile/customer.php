<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$activeNav = 'crm';
$showBack  = true;
$backUrl   = 'mobile/crm.php';

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id === 0) { header('Location: ' . BASE_URL . '/mobile/crm.php'); exit; }

$cust = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
$cust->execute([':id' => $id]);
$cust = $cust->fetch();
if (!$cust) { header('Location: ' . BASE_URL . '/mobile/crm.php'); exit; }

$pageTitle = $cust['name'];

// Invoices
$invStmt = $pdo->prepare(
    "SELECT inv.id, inv.invoice_no, inv.total, inv.created_at,
            COALESCE(inv.status,'active') AS status,
            (inv.total - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id=inv.id),0)) AS balance
     FROM invoices inv
     WHERE inv.customer_id = :id
     ORDER BY inv.created_at DESC LIMIT 20"
);
$invStmt->execute([':id' => $id]);
$invoices = $invStmt->fetchAll();

// Quotes
$qStmt = $pdo->prepare(
    "SELECT id, quote_no, total, status, created_at FROM quotes WHERE customer_id = :id ORDER BY created_at DESC LIMIT 20"
);
$qStmt->execute([':id' => $id]);
$quotes = $qStmt->fetchAll();

// Stats
$totalSpend  = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] !== 'voided'), 'total'));
$activeTab   = $_GET['tab'] ?? 'invoices';

require_once __DIR__ . '/_shell.php';
?>

<!-- Contact card -->
<div style="background:var(--surface);padding:20px 16px;border-bottom:1px solid var(--border);
            display:flex;align-items:center;gap:14px;">
    <div class="avatar-circle" style="width:52px;height:52px;font-size:22px;font-weight:700;">
        <?= strtoupper(substr($cust['name'], 0, 1)) ?>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-size:18px;font-weight:700;"><?= htmlspecialchars($cust['name']) ?></div>
        <?php
        $company = $cust['company_name'] ?? '';
        if ($company): ?>
        <div style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($company) ?></div>
        <?php endif; ?>
        <div style="display:flex;gap:12px;margin-top:8px;flex-wrap:wrap;">
            <?php if ($cust['email']): ?>
            <a href="mailto:<?= htmlspecialchars($cust['email']) ?>"
               style="display:inline-flex;align-items:center;gap:4px;background:#EFF6FF;
                      color:#2563EB;border-radius:8px;padding:5px 10px;font-size:12px;
                      font-weight:600;text-decoration:none;">
                ✉ Email
            </a>
            <?php endif; ?>
            <?php if ($cust['phone']): ?>
            <a href="tel:<?= htmlspecialchars($cust['phone']) ?>"
               style="display:inline-flex;align-items:center;gap:4px;background:#F0FDF4;
                      color:#16A34A;border-radius:8px;padding:5px 10px;font-size:12px;
                      font-weight:600;text-decoration:none;">
                📞 Call
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/crm/customer.php?id=<?= $id ?>"
               style="display:inline-flex;align-items:center;gap:4px;background:#F8FAFC;
                      color:#374151;border-radius:8px;padding:5px 10px;font-size:12px;
                      font-weight:600;text-decoration:none;">
                ↗ Full Profile
            </a>
        </div>
    </div>
</div>

<!-- Summary row -->
<div style="display:flex;background:var(--surface);border-bottom:1px solid var(--border);">
    <div style="flex:1;padding:12px 16px;text-align:center;border-right:1px solid var(--border);">
        <div style="font-size:18px;font-weight:700;"><?= count($invoices) ?></div>
        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;">Invoices</div>
    </div>
    <div style="flex:1;padding:12px 16px;text-align:center;border-right:1px solid var(--border);">
        <div style="font-size:18px;font-weight:700;"><?= count($quotes) ?></div>
        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;">Quotes</div>
    </div>
    <div style="flex:1;padding:12px 16px;text-align:center;">
        <div style="font-size:16px;font-weight:700;">R <?= number_format($totalSpend, 0) ?></div>
        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;">Spend</div>
    </div>
</div>

<!-- Tabs -->
<div class="m-tabs">
    <button class="m-tab <?= $activeTab === 'invoices' ? 'active' : '' ?>"
            onclick="switchTab('invoices')">Invoices</button>
    <button class="m-tab <?= $activeTab === 'quotes' ? 'active' : '' ?>"
            onclick="switchTab('quotes')">Quotes</button>
    <button class="m-tab <?= $activeTab === 'info' ? 'active' : '' ?>"
            onclick="switchTab('info')">Info</button>
</div>

<!-- Invoices panel -->
<div id="panel-invoices" class="m-panel <?= $activeTab === 'invoices' ? 'active' : '' ?>">
    <?php if (empty($invoices)): ?>
    <div class="list-empty"><div class="list-empty-icon">📄</div><p>No invoices yet</p></div>
    <?php else: ?>
    <div style="background:var(--surface);">
        <?php foreach ($invoices as $inv):
            $bal = (float)$inv['balance'];
            if ($inv['status'] === 'voided')   { $badge = 'badge-voided'; $bl = 'Voided'; }
            elseif ($bal <= 0.01)               { $badge = 'badge-paid';   $bl = 'Paid'; }
            else                                { $badge = 'badge-unpaid'; $bl = 'R '.number_format($bal,2).' due'; }
        ?>
        <a href="<?= BASE_URL ?>/mobile/invoice.php?id=<?= $inv['id'] ?>" class="list-row">
            <div class="list-row-body">
                <div class="list-row-title"><?= htmlspecialchars($inv['invoice_no']) ?></div>
                <div class="list-row-sub"><span class="badge <?= $badge ?>"><?= $bl ?></span></div>
            </div>
            <div class="list-row-right">
                <div class="list-row-amount">R <?= number_format((float)$inv['total'], 2) ?></div>
                <div class="list-row-date"><?= date('d M Y', strtotime($inv['created_at'])) ?></div>
            </div>
            <svg class="list-row-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Quotes panel -->
<div id="panel-quotes" class="m-panel <?= $activeTab === 'quotes' ? 'active' : '' ?>">
    <?php if (empty($quotes)): ?>
    <div class="list-empty"><div class="list-empty-icon">📋</div><p>No quotes yet</p></div>
    <?php else: ?>
    <div style="background:var(--surface);">
        <?php
        $qBadgeMap = ['open'=>'badge-open','accepted'=>'badge-accepted','draft'=>'badge-draft','expired'=>'badge-expired'];
        foreach ($quotes as $qr):
            $qb = $qBadgeMap[$qr['status']] ?? 'badge-draft';
        ?>
        <a href="<?= BASE_URL ?>/mobile/quote.php?id=<?= $qr['id'] ?>" class="list-row">
            <div class="list-row-body">
                <div class="list-row-title"><?= htmlspecialchars($qr['quote_no']) ?></div>
                <div class="list-row-sub"><span class="badge <?= $qb ?>"><?= ucfirst($qr['status']) ?></span></div>
            </div>
            <div class="list-row-right">
                <div class="list-row-amount">R <?= number_format((float)$qr['total'], 2) ?></div>
                <div class="list-row-date"><?= date('d M Y', strtotime($qr['created_at'])) ?></div>
            </div>
            <svg class="list-row-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Info panel -->
<div id="panel-info" class="m-panel <?= $activeTab === 'info' ? 'active' : '' ?>">
    <div class="section-head">Contact Details</div>
    <div style="background:var(--surface);">
        <?php $fields = [
            'Name'       => $cust['name'],
            'Email'      => $cust['email'] ?? '',
            'Phone'      => $cust['phone'] ?? '',
            'ID Number'  => $cust['id_number'] ?? '',
            'Address'    => $cust['address'] ?? '',
            'Company'    => $cust['company_name'] ?? '',
            'VAT No'     => $cust['vat_no'] ?? '',
        ];
        foreach ($fields as $label => $value):
            if ($value === '' || $value === null) continue;
        ?>
        <div class="info-row">
            <span class="info-label"><?= $label ?></span>
            <span class="info-value"><?= htmlspecialchars($value) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($cust['notes'])): ?>
    <div class="section-head">Notes</div>
    <div style="background:var(--surface);padding:12px 16px;font-size:14px;color:var(--text-muted);">
        <?= nl2br(htmlspecialchars($cust['notes'])) ?>
    </div>
    <?php endif; ?>
</div>

<script>
function switchTab(name) {
    ['invoices','quotes','info'].forEach(function(t) {
        document.getElementById('panel-' + t).classList.toggle('active', t === name);
        document.querySelectorAll('.m-tab').forEach(function(btn, i) {
            btn.classList.toggle('active', ['invoices','quotes','info'][i] === name);
        });
    });
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
