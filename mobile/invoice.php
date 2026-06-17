<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$activeNav = 'invoices';
$showBack  = true;
$backUrl   = 'mobile/invoices.php';

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id === 0) {
    header('Location: ' . BASE_URL . '/mobile/invoices.php'); exit;
}

// Load invoice
$inv = $pdo->prepare(
    "SELECT inv.*, c.name AS customer_name, c.email AS customer_email,
            c.phone AS customer_phone, c.address AS customer_address,
            u.name AS created_by_name
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     LEFT JOIN users u ON u.id = inv.created_by
     WHERE inv.id = :id LIMIT 1"
);
$inv->execute([':id' => $id]);
$inv = $inv->fetch();
if (!$inv) { header('Location: ' . BASE_URL . '/mobile/invoices.php'); exit; }

$pageTitle = $inv['invoice_no'];

// Line items
$items = $pdo->prepare(
    "SELECT ii.*, p.name AS product_name, p.sku FROM invoice_items ii
     JOIN products p ON p.id = ii.product_id WHERE ii.invoice_id = :id ORDER BY ii.id"
);
$items->execute([':id' => $id]);
$items = $items->fetchAll();

// Payments
$paymentsStmt = $pdo->prepare(
    "SELECT ip.amount, ip.payment_method, ip.reference, ip.created_at, u.name AS by_name
     FROM invoice_payments ip
     LEFT JOIN users u ON u.id = ip.created_by
     WHERE ip.invoice_id = :id ORDER BY ip.created_at"
);
$paymentsStmt->execute([':id' => $id]);
$payments   = $paymentsStmt->fetchAll();
$totalPaid  = array_sum(array_column($payments, 'amount'));
$balance    = round((float)$inv['total'] - $totalPaid, 2);
$isPaid     = $balance <= 0;
$isVoided   = ($inv['status'] ?? 'active') === 'voided';

// Active payment link
$payLink = null;
try {
    $plStmt = $pdo->prepare(
        "SELECT payment_url, provider, amount, expires_at
         FROM payment_links WHERE invoice_id=:id AND status='pending' ORDER BY created_at DESC LIMIT 1"
    );
    $plStmt->execute([':id' => $id]);
    $payLink = $plStmt->fetch() ?: null;
} catch (Throwable $e) {}

// Status badge
if ($isVoided)       { $badge = 'badge-voided'; $bLabel = 'Voided'; }
elseif ($isPaid)     { $badge = 'badge-paid';   $bLabel = 'Paid'; }
elseif ($totalPaid > 0) { $badge = 'badge-partial'; $bLabel = 'Partial'; }
else                 { $badge = 'badge-unpaid'; $bLabel = 'Unpaid'; }

require_once __DIR__ . '/_shell.php';
?>

<!-- Header -->
<div class="detail-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div class="detail-doc-no"><?= htmlspecialchars($inv['invoice_no']) ?></div>
        <span class="badge <?= $badge ?>"><?= $bLabel ?></span>
    </div>
    <div class="detail-meta">
        <span><?= date('d M Y', strtotime($inv['created_at'])) ?></span>
        <?php if ($inv['created_by_name']): ?>
        <span>· by <?= htmlspecialchars($inv['created_by_name']) ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Balance bar -->
<?php if (!$isPaid && !$isVoided): ?>
<div style="background:<?= $totalPaid > 0 ? '#DBEAFE' : '#FEF3C7' ?>;padding:10px 16px;
            display:flex;justify-content:space-between;align-items:center;font-size:14px;
            border-bottom:1px solid var(--border);">
    <span style="color:<?= $totalPaid > 0 ? '#1E40AF' : '#92400E' ?>;font-weight:600;">
        Outstanding: R <?= number_format($balance, 2) ?>
    </span>
    <?php if ($payLink): ?>
    <button onclick="copyLink()" class="scan-btn" style="font-size:12px;padding:7px 12px;border-radius:8px;">
        🔗 Copy Link
    </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Customer info -->
<div class="section-head">Customer</div>
<div class="info-card">
    <div class="info-row">
        <span class="info-label">Name</span>
        <span class="info-value"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></span>
    </div>
    <?php if ($inv['customer_email']): ?>
    <div class="info-row">
        <span class="info-label">Email</span>
        <a href="mailto:<?= htmlspecialchars($inv['customer_email']) ?>" class="info-value" style="color:var(--accent);">
            <?= htmlspecialchars($inv['customer_email']) ?>
        </a>
    </div>
    <?php endif; ?>
    <?php if ($inv['customer_phone']): ?>
    <div class="info-row">
        <span class="info-label">Phone</span>
        <a href="tel:<?= htmlspecialchars($inv['customer_phone']) ?>" class="info-value" style="color:var(--accent);">
            <?= htmlspecialchars($inv['customer_phone']) ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Line items -->
<div class="section-head">Items (<?= count($items) ?>)</div>
<div style="background:var(--surface);">
    <?php foreach ($items as $item): ?>
    <div class="line-item">
        <div class="line-item-body">
            <div class="line-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="line-item-serial">
                <?= htmlspecialchars($item['sku']) ?>
                <?php if ($item['serial_no']): ?>· <?= htmlspecialchars($item['serial_no']) ?><?php endif; ?>
            </div>
        </div>
        <div class="line-item-price">R <?= number_format((float)$item['unit_price'], 2) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Totals -->
<div class="section-head">Totals</div>
<div class="totals-block">
    <div class="totals-row">
        <span style="color:var(--text-muted);">Subtotal</span>
        <span>R <?= number_format((float)$inv['subtotal'], 2) ?></span>
    </div>
    <?php if ((float)($inv['discount_amount'] ?? 0) > 0): ?>
    <div class="totals-row">
        <span style="color:var(--text-muted);">Discount</span>
        <span style="color:var(--danger);">- R <?= number_format((float)$inv['discount_amount'], 2) ?></span>
    </div>
    <?php endif; ?>
    <div class="totals-row">
        <span style="color:var(--text-muted);">VAT</span>
        <span>R <?= number_format((float)($inv['vat_amount'] ?? 0), 2) ?></span>
    </div>
    <div class="totals-row total-final">
        <span>Total</span>
        <span>R <?= number_format((float)$inv['total'], 2) ?></span>
    </div>
    <?php if ($totalPaid > 0): ?>
    <div class="totals-row">
        <span style="color:var(--success);">Paid</span>
        <span style="color:var(--success);">R <?= number_format($totalPaid, 2) ?></span>
    </div>
    <?php if (!$isPaid): ?>
    <div class="totals-row" style="font-weight:700;color:var(--danger);">
        <span>Balance Due</span>
        <span>R <?= number_format($balance, 2) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Payments -->
<?php if (!empty($payments)): ?>
<div class="section-head">Payments Recorded</div>
<div style="background:var(--surface);">
    <?php foreach ($payments as $p): ?>
    <div class="info-row">
        <span class="info-label" style="min-width:0;flex:1;">
            <?= date('d M Y', strtotime($p['created_at'])) ?>
            &nbsp;<span style="font-size:11px;text-transform:uppercase;font-weight:600;color:var(--text-muted);"><?= htmlspecialchars($p['payment_method']) ?></span>
        </span>
        <span class="info-value" style="color:var(--success);font-weight:700;">R <?= number_format((float)$p['amount'], 2) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Notes -->
<?php if (!empty($inv['notes'])): ?>
<div class="section-head">Notes</div>
<div style="background:var(--surface);padding:12px 16px;font-size:14px;color:var(--text-muted);">
    <?= nl2br(htmlspecialchars($inv['notes'])) ?>
</div>
<?php endif; ?>

<!-- Action bar -->
<div class="action-bar">
    <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $id ?>" class="btn-secondary" style="text-align:center;">
        Full View ↗
    </a>
    <?php if ($payLink): ?>
    <button type="button" class="btn-primary" onclick="copyLink()">🔗 Copy Payment Link</button>
    <?php elseif (!$isPaid && !$isVoided): ?>
    <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $id ?>" class="btn-primary" style="text-align:center;">
        Record Payment
    </a>
    <?php endif; ?>
</div>

<?php if ($payLink): ?>
<input type="hidden" id="payLinkUrl" value="<?= htmlspecialchars($payLink['payment_url']) ?>">
<script>
function copyLink() {
    var url = document.getElementById('payLinkUrl').value;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() { showToast('Link copied!'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = url; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        showToast('Link copied!');
    }
}
function showToast(msg) {
    var t = document.createElement('div');
    t.className = 'toast'; t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function() { t.classList.add('show'); });
    setTimeout(function() {
        t.classList.remove('show');
        setTimeout(function() { t.remove(); }, 250);
    }, 2200);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
