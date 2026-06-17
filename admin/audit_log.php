<?php
// ============================================================
// Blackview SA Portal — Admin: Audit Log
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Audit Log';

// Filters
$filterUser   = (int)($_GET['user_id']   ?? 0);
$filterAction = trim($_GET['action']     ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to']   ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = AUDIT_PER_PAGE;
$offset  = ($page - 1) * $perPage;

// Build WHERE
$where  = [];
$params = [];

if ($filterUser > 0) {
    $where[]          = 'al.user_id = :uid';
    $params[':uid']   = $filterUser;
}
if ($filterAction !== '') {
    $where[]          = 'al.action LIKE :action';
    $params[':action'] = '%' . $filterAction . '%';
}
if ($filterDateFrom !== '') {
    $where[]             = 'al.created_at >= :dfrom';
    $params[':dfrom']    = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[]             = 'al.created_at <= :dto';
    $params[':dto']      = $filterDateTo . ' 23:59:59';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log al $whereSQL");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch rows
$sql = "
    SELECT al.*, u.name AS user_name, u.email AS user_email
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    $whereSQL
    ORDER BY al.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Users for filter dropdown
$users = $pdo->query('SELECT id, name, email FROM users ORDER BY name ASC')->fetchAll();

// Distinct action types
$actionTypes = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Audit Log</h2>
    <p class="page-subtitle">All login events and data changes — <?= number_format($totalRows) ?> total entries.</p>
</div>

<!-- Filters -->
<div class="card filter-card">
    <form method="GET" action="" class="filter-form">
        <div class="form-row">
            <div class="form-group form-group--quarter">
                <label class="form-label">User</label>
                <select name="user_id" class="form-control form-select">
                    <option value="">All Users</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group form-group--quarter">
                <label class="form-label">Action</label>
                <input type="text" name="action" class="form-control"
                       value="<?= htmlspecialchars($filterAction) ?>"
                       placeholder="e.g. login, scan_in" list="action-types">
                <datalist id="action-types">
                    <?php foreach ($actionTypes as $at): ?>
                        <option value="<?= htmlspecialchars($at) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="form-group form-group--quarter">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control"
                       value="<?= htmlspecialchars($filterDateFrom) ?>">
            </div>

            <div class="form-group form-group--quarter">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control"
                       value="<?= htmlspecialchars($filterDateTo) ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-outline">Reset</a>
        </div>
    </form>
</div>

<?php if (empty($logs)): ?>
    <div class="alert alert-info">No audit log entries found matching your filters.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover table-sm">
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Entity ID</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td class="text-nowrap"><?= date('d M Y H:i:s', strtotime($log['created_at'])) ?></td>
                <td>
                    <?php if ($log['user_name']): ?>
                        <span title="<?= htmlspecialchars($log['user_email'] ?? '') ?>">
                            <?= htmlspecialchars($log['user_name']) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">System</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-action"><?= htmlspecialchars($log['action']) ?></span></td>
                <td><?= htmlspecialchars($log['entity']) ?></td>
                <td><?= $log['entity_id'] ?? '—' ?></td>
                <td class="log-details"><?= nl2br(htmlspecialchars($log['details'] ?? '')) ?></td>
                <td><code><?= htmlspecialchars($log['ip_address']) ?></code></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination-bar">
    <?php
    $qs = http_build_query(array_filter([
        'user_id'   => $filterUser   ?: null,
        'action'    => $filterAction  ?: null,
        'date_from' => $filterDateFrom ?: null,
        'date_to'   => $filterDateTo   ?: null,
    ]));
    ?>
    <?php if ($page > 1): ?>
        <a href="?<?= $qs ?>&page=<?= $page - 1 ?>" class="btn btn-sm btn-outline">&laquo; Previous</a>
    <?php endif; ?>

    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalRows) ?> entries)</span>

    <?php if ($page < $totalPages): ?>
        <a href="?<?= $qs ?>&page=<?= $page + 1 ?>" class="btn btn-sm btn-outline">Next &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
