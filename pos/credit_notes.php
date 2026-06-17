<?php
// ============================================================
// Blackview SA Portal — Credit Notes List
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Credit Notes';

// Filters
$statusFilter = trim($_GET['status'] ?? '');
$search       = trim($_GET['q'] ?? '');

$validStatuses = ['open', 'applied', 'voided'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

// Build query
$sql    = "SELECT cn.*, inv.invoice_no, inv.id AS invoice_id,
                  c.name AS customer_name,
                  u.name AS created_by_name
           FROM credit_notes cn
           JOIN invoices inv ON inv.id = cn.invoice_id
           LEFT JOIN customers c ON c.id = inv.customer_id
           LEFT JOIN users u ON u.id = cn.created_by
           WHERE 1=1";
$params = [];

if ($statusFilter !== '') {
    $sql .= " AND cn.status = :status";
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $sql .= " AND (cn.credit_note_no LIKE :q OR inv.invoice_no LIKE :q2 OR c.name LIKE :q3)";
    $params[':q']  = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
}

$sql .= " ORDER BY cn.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$creditNotes = $stmt->fetchAll();

// Summary counts
$counts = $pdo->query(
    "SELECT status, COUNT(*) AS n, COALESCE(SUM(total),0) AS total_amt
     FROM credit_notes GROUP BY status"
)->fetchAll();
$countMap = [];
foreach ($counts as $row) {
    $countMap[$row['status']] = ['n' => (int)$row['n'], 'amt' => (float)$row['total_amt']];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Credit Notes</h2>
    <p class="page-subtitle">All credit notes issued against invoices.</p>
</div>

<!-- Summary pills -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <?php
    $pillDefs = [
        'open'    => ['label' => 'Open',    'color' => '#D97706', 'bg' => '#FFFBEB'],
        'applied' => ['label' => 'Applied', 'color' => '#16A34A', 'bg' => '#F0FDF4'],
        'voided'  => ['label' => 'Voided',  'color' => '#DC2626', 'bg' => '#FEF2F2'],
    ];
    foreach ($pillDefs as $s => $def):
        $n   = $countMap[$s]['n']   ?? 0;
        $amt = $countMap[$s]['amt'] ?? 0;
    ?>
    <a href="?status=<?= $s ?><?= $search ? '&q='.urlencode($search) : '' ?>"
       style="display:flex;flex-direction:column;padding:.6rem 1rem;border-radius:8px;
              background:<?= $statusFilter === $s ? $def['color'] : $def['bg'] ?>;
              color:<?= $statusFilter === $s ? '#fff' : $def['color'] ?>;
              border:1.5px solid <?= $def['color'] ?>;text-decoration:none;min-width:110px;">
        <strong style="font-size:1.3rem;"><?= $n ?></strong>
        <span style="font-size:.78rem;"><?= $def['label'] ?> — R <?= number_format($amt, 2) ?></span>
    </a>
    <?php endforeach; ?>
    <?php if ($statusFilter): ?>
    <a href="?<?= $search ? 'q='.urlencode($search) : '' ?>"
       style="align-self:center;font-size:.82rem;color:var(--color-muted);text-decoration:underline;">
        Clear filter
    </a>
    <?php endif; ?>
</div>

<!-- Search + filters bar -->
<div class="card" style="margin-bottom:1rem;">
    <div class="card-body" style="padding:.75rem 1rem;">
        <form method="GET" action="" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <?php if ($statusFilter): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <?php endif; ?>
            <input type="text" name="q" class="form-control" style="max-width:280px;"
                   placeholder="Search CN no., invoice, customer…"
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($search): ?>
                <a href="?<?= $statusFilter ? 'status='.urlencode($statusFilter) : '' ?>" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Credit Note No</th>
                    <th>Against Invoice</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th>Issued By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($creditNotes as $cn): ?>
                <tr style="cursor:pointer;"
                    onclick="window.location='<?= BASE_URL ?>/pos/credit_note_view.php?id=<?= $cn['id'] ?>'"
                    title="View credit note <?= htmlspecialchars($cn['credit_note_no']) ?>">
                    <td style="font-weight:600;color:#DC2626;">
                        <?= htmlspecialchars($cn['credit_note_no']) ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $cn['invoice_id'] ?>"
                           onclick="event.stopPropagation()">
                            <?= htmlspecialchars($cn['invoice_no']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($cn['customer_name'] ?? 'Walk-in') ?></td>
                    <td style="white-space:nowrap;"><?= date('d M Y', strtotime($cn['created_at'])) ?></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($cn['reason'] ?? '') ?>">
                        <?= htmlspecialchars(mb_substr($cn['reason'] ?? '—', 0, 50)) ?>
                    </td>
                    <td>
                        <span style="text-transform:capitalize;font-weight:600;font-size:.82rem;
                                     color:<?= $cn['status']==='voided' ? '#DC2626' : ($cn['status']==='applied' ? '#16A34A' : '#D97706') ?>">
                            <?= htmlspecialchars($cn['status']) ?>
                        </span>
                    </td>
                    <td class="text-right" style="font-weight:700;color:#DC2626;">
                        R <?= number_format((float)$cn['total'], 2) ?>
                    </td>
                    <td style="font-size:.82rem;"><?= htmlspecialchars($cn['created_by_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($creditNotes)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted" style="padding:2rem;">
                        No credit notes found<?= $statusFilter || $search ? ' matching your filters' : '' ?>.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
tbody tr[onclick]:hover { background: #EFF6FF !important; }
.text-right { text-align: right; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
