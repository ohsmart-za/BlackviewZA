<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$activeNav = 'invoices';
$showBack  = true;
$backUrl   = 'mobile/quotes.php';

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id === 0) { header('Location: ' . BASE_URL . '/mobile/quotes.php'); exit; }

$q = $pdo->prepare(
    "SELECT q.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
            u.name AS created_by_name
     FROM quotes q
     LEFT JOIN customers c ON c.id = q.customer_id
     LEFT JOIN users u ON u.id = q.created_by
     WHERE q.id = :id LIMIT 1"
);
$q->execute([':id' => $id]);
$q = $q->fetch();
if (!$q) { header('Location: ' . BASE_URL . '/mobile/quotes.php'); exit; }

$pageTitle = $q['quote_no'];

$items = $pdo->prepare(
    "SELECT qi.*, p.name AS product_name, p.sku FROM quote_items qi
     JOIN products p ON p.id = qi.product_id WHERE qi.quote_id = :id ORDER BY qi.id"
);
$items->execute([':id' => $id]);
$items = $items->fetchAll();

$badgeMap = [
    'open'     => ['badge-open',     'Open'],
    'accepted' => ['badge-accepted', 'Accepted'],
    'draft'    => ['badge-draft',    'Draft'],
    'expired'  => ['badge-expired',  'Expired'],
];
[$badge, $bLabel] = $badgeMap[$q['status']] ?? ['badge-draft', ucfirst($q['status'])];

require_once __DIR__ . '/_shell.php';
?>

<div class="detail-header">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div class="detail-doc-no"><?= htmlspecialchars($q['quote_no']) ?></div>
        <span class="badge <?= $badge ?>"><?= $bLabel ?></span>
    </div>
    <div class="detail-meta">
        <span><?= date('d M Y', strtotime($q['created_at'])) ?></span>
        <?php if ($q['valid_until']): ?>
        <span>· Valid until <?= date('d M Y', strtotime($q['valid_until'])) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="section-head">Customer</div>
<div class="info-card">
    <div class="info-row">
        <span class="info-label">Name</span>
        <span class="info-value"><?= htmlspecialchars($q['customer_name'] ?? '—') ?></span>
    </div>
    <?php if ($q['customer_email']): ?>
    <div class="info-row">
        <span class="info-label">Email</span>
        <a href="mailto:<?= htmlspecialchars($q['customer_email']) ?>" class="info-value" style="color:var(--accent);">
            <?= htmlspecialchars($q['customer_email']) ?>
        </a>
    </div>
    <?php endif; ?>
    <?php if ($q['customer_phone']): ?>
    <div class="info-row">
        <span class="info-label">Phone</span>
        <a href="tel:<?= htmlspecialchars($q['customer_phone']) ?>" class="info-value" style="color:var(--accent);">
            <?= htmlspecialchars($q['customer_phone']) ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<div class="section-head">Items (<?= count($items) ?>)</div>
<div style="background:var(--surface);">
    <?php foreach ($items as $item): ?>
    <div class="line-item">
        <div class="line-item-body">
            <div class="line-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="line-item-serial"><?= htmlspecialchars($item['sku']) ?></div>
        </div>
        <div class="line-item-price">R <?= number_format((float)$item['unit_price'], 2) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="section-head">Totals</div>
<div class="totals-block">
    <div class="totals-row">
        <span style="color:var(--text-muted);">Subtotal</span>
        <span>R <?= number_format((float)($q['subtotal'] ?? $q['total']), 2) ?></span>
    </div>
    <?php if ((float)($q['discount_amount'] ?? 0) > 0): ?>
    <div class="totals-row">
        <span style="color:var(--text-muted);">Discount</span>
        <span style="color:var(--danger);">- R <?= number_format((float)$q['discount_amount'], 2) ?></span>
    </div>
    <?php endif; ?>
    <div class="totals-row total-final">
        <span>Total</span>
        <span>R <?= number_format((float)$q['total'], 2) ?></span>
    </div>
</div>

<?php if (!empty($q['notes'])): ?>
<div class="section-head">Notes</div>
<div style="background:var(--surface);padding:12px 16px;font-size:14px;color:var(--text-muted);">
    <?= nl2br(htmlspecialchars($q['notes'])) ?>
</div>
<?php endif; ?>

<div class="action-bar">
    <a href="<?= BASE_URL ?>/pos/quote_view.php?id=<?= $id ?>" class="btn-secondary" style="text-align:center;">
        Full View ↗
    </a>
    <?php if ($q['status'] === 'open'): ?>
    <a href="<?= BASE_URL ?>/pos/invoice.php?from_quote=<?= $id ?>" class="btn-primary" style="text-align:center;">
        Convert to Invoice
    </a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
