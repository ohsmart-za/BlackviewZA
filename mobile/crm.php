<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo = getDB();
$pageTitle = 'Customers';
$activeNav = 'crm';
$showBack  = false;

$search  = trim($_GET['q']  ?? '');
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 40;

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = "(c.name LIKE :q OR c.email LIKE :q2 OR c.phone LIKE :q3)";
    $params[':q']  = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
}
// company_name may not exist if migration_009 wasn't run — wrap in try
$hasCompany = false;
try {
    $pdo->query("SELECT company_name FROM customers LIMIT 1");
    $hasCompany = true;
    if ($search !== '') {
        $where[count($where)-1] = str_replace('OR c.phone LIKE :q3','OR c.phone LIKE :q3 OR c.company_name LIKE :q4', $where[count($where)-1]);
        $params[':q4'] = '%' . $search . '%';
    }
} catch (Throwable $e) {}

$whereSQL = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$coField = $hasCompany ? ', c.company_name' : '';
$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.email, c.phone $coField,
            COUNT(inv.id) AS invoice_count,
            MAX(inv.created_at) AS last_invoice,
            COALESCE(SUM(inv.total),0) AS total_spend
     FROM customers c
     LEFT JOIN invoices inv ON inv.customer_id = c.id AND COALESCE(inv.status,'active') = 'active'
     WHERE $whereSQL
     GROUP BY c.id
     ORDER BY last_invoice DESC, c.name ASC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$customers = $stmt->fetchAll();

require_once __DIR__ . '/_shell.php';
?>

<form method="GET" action="">
    <div class="search-wrap">
        <input type="text" name="q" class="search-input"
               placeholder="Search name, email or phone…"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        <button type="submit" class="search-btn">Search</button>
    </div>
</form>

<div style="padding:8px 16px;font-size:12px;color:var(--text-muted);">
    <?= $total ?> customer<?= $total !== 1 ? 's' : '' ?>
    <?= $search ? ' matching "' . htmlspecialchars($search) . '"' : '' ?>
</div>

<div class="card" style="border-radius:0;margin-bottom:0;">
    <?php if (empty($customers)): ?>
    <div class="list-empty">
        <div class="list-empty-icon">👥</div>
        <p>No customers found</p>
    </div>
    <?php else: ?>
    <?php foreach ($customers as $cust):
        $initial = strtoupper(substr($cust['name'], 0, 1));
        $company = $hasCompany ? ($cust['company_name'] ?? '') : '';
    ?>
    <a href="<?= BASE_URL ?>/mobile/customer.php?id=<?= $cust['id'] ?>" class="list-row">
        <div class="avatar-circle avatar-circle-muted" style="width:40px;height:40px;font-size:16px;">
            <?= htmlspecialchars($initial) ?>
        </div>
        <div class="list-row-body">
            <div class="list-row-title"><?= htmlspecialchars($cust['name']) ?></div>
            <div class="list-row-sub">
                <?php if ($company): ?>
                    <?= htmlspecialchars($company) ?>
                <?php elseif ($cust['email']): ?>
                    <?= htmlspecialchars($cust['email']) ?>
                <?php elseif ($cust['phone']): ?>
                    <?= htmlspecialchars($cust['phone']) ?>
                <?php else: ?>
                    No contact info
                <?php endif; ?>
            </div>
        </div>
        <div class="list-row-right">
            <?php if ($cust['invoice_count'] > 0): ?>
            <div class="list-row-date" style="text-align:right;">
                <?= $cust['invoice_count'] ?> invoice<?= $cust['invoice_count'] !== '1' ? 's' : '' ?>
            </div>
            <?php endif; ?>
        </div>
        <svg class="list-row-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none">
            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:space-between;padding:12px 16px;font-size:14px;color:var(--text-muted);">
    <span>Page <?= $page ?> of <?= $totalPages ?></span>
    <div style="display:flex;gap:10px;">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(['q'=>$search,'p'=>$page-1]) ?>" style="color:var(--accent);font-weight:600;">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(['q'=>$search,'p'=>$page+1]) ?>" style="color:var(--accent);font-weight:600;">Next →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
