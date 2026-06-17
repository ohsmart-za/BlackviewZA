<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Invoices';
$activeNav = 'invoices';
$showBack  = false;

// Filter params
$search    = trim($_GET['q']      ?? '');
$statusF   = trim($_GET['status'] ?? 'all');
$page      = max(1, (int)($_GET['p'] ?? 1));
$perPage   = 30;
$offset    = ($page - 1) * $perPage;

// Build query
$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = "(inv.invoice_no LIKE :q OR c.name LIKE :q2)";
    $params[':q']  = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
}

// Compute balance via subquery and filter on it
$balanceExpr = "(inv.total - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = inv.id),0))";

if ($statusF === 'unpaid') {
    $where[] = "$balanceExpr > 0.01 AND COALESCE(inv.status,'active') = 'active'";
} elseif ($statusF === 'paid') {
    $where[] = "$balanceExpr <= 0.01 AND COALESCE(inv.status,'active') = 'active'";
} elseif ($statusF === 'voided') {
    $where[] = "COALESCE(inv.status,'active') = 'voided'";
} else {
    // All — exclude nothing
}

$whereSQL = implode(' AND ', $where);

$total = (int)$pdo->query(
    "SELECT COUNT(*) FROM invoices inv LEFT JOIN customers c ON c.id = inv.customer_id WHERE $whereSQL"
)->fetchColumn(); // Note: params not bound here — use exec for count

// Recount with bound params for accuracy
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices inv LEFT JOIN customers c ON c.id = inv.customer_id WHERE $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT inv.id, inv.invoice_no, inv.total, inv.created_at,
            COALESCE(inv.status,'active') AS status,
            c.name AS customer_name,
            $balanceExpr AS balance
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     WHERE $whereSQL
     ORDER BY inv.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$invoices = $stmt->fetchAll();

require_once __DIR__ . '/_shell.php';
?>

<!-- Internal tab bar: Invoices / Quotes -->
<div style="display:flex;background:var(--surface);border-bottom:2px solid var(--border);">
    <a href="<?= BASE_URL ?>/mobile/invoices.php"
       style="flex:1;text-align:center;padding:13px;font-size:13px;font-weight:600;
              color:var(--accent);text-decoration:none;border-bottom:2px solid var(--accent);margin-bottom:-2px;">
        Invoices
    </a>
    <a href="<?= BASE_URL ?>/mobile/quotes.php"
       style="flex:1;text-align:center;padding:13px;font-size:13px;font-weight:600;
              color:var(--text-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;">
        Quotes
    </a>
</div>

<!-- Search -->
<form method="GET" action="">
    <div class="search-wrap">
        <input type="text" name="q" class="search-input"
               placeholder="Search invoice # or customer…"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        <?php if ($statusF !== 'all'): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusF) ?>">
        <?php endif; ?>
        <button type="submit" class="search-btn">Search</button>
    </div>
</form>

<!-- Status filter chips -->
<div class="chip-row">
    <?php
    $chips = ['all'=>'All', 'unpaid'=>'Unpaid', 'paid'=>'Paid', 'voided'=>'Voided'];
    foreach ($chips as $val => $lbl):
        $active = $statusF === $val ? ' active' : '';
        $href = '?' . http_build_query(array_merge(['status' => $val], $search ? ['q' => $search] : []));
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="chip<?= $active ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<!-- List -->
<div class="card" style="border-radius:0;margin-bottom:0;">
    <?php if (empty($invoices)): ?>
    <div class="list-empty">
        <div class="list-empty-icon">📄</div>
        <p>No invoices found</p>
    </div>
    <?php else: ?>
    <?php foreach ($invoices as $inv):
        $balance = (float)$inv['balance'];
        $status  = $inv['status'];
        if ($status === 'voided') {
            $badge = 'badge-voided'; $bLabel = 'Voided';
        } elseif ($balance <= 0.01) {
            $badge = 'badge-paid'; $bLabel = 'Paid';
        } else {
            $badge = 'badge-unpaid'; $bLabel = 'R ' . number_format($balance, 2) . ' due';
        }
    ?>
    <a href="<?= BASE_URL ?>/mobile/invoice.php?id=<?= $inv['id'] ?>" class="list-row">
        <div class="list-row-body">
            <div class="list-row-title"><?= htmlspecialchars($inv['customer_name'] ?? 'Unknown') ?></div>
            <div class="list-row-sub">
                <?= htmlspecialchars($inv['invoice_no']) ?>
                &nbsp;·&nbsp;
                <span class="badge <?= $badge ?>"><?= $bLabel ?></span>
            </div>
        </div>
        <div class="list-row-right">
            <div class="list-row-amount">R <?= number_format((float)$inv['total'], 2) ?></div>
            <div class="list-row-date"><?= date('d M Y', strtotime($inv['created_at'])) ?></div>
        </div>
        <svg class="list-row-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:space-between;padding:12px 16px;font-size:14px;color:var(--text-muted);">
    <span>Page <?= $page ?> of <?= $totalPages ?> (<?= $total ?> total)</span>
    <div style="display:flex;gap:10px;">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(['q'=>$search,'status'=>$statusF,'p'=>$page-1]) ?>"
           style="color:var(--accent);font-weight:600;">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(['q'=>$search,'status'=>$statusF,'p'=>$page+1]) ?>"
           style="color:var(--accent);font-weight:600;">Next →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
