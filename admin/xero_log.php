<?php
// ============================================================
// Blackview SA Portal — Xero Sync Log (full page)
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Xero Sync Log';

// ---- Clear log (soft — the app DB user has no DELETE grant) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_log') {
    saveSettings($pdo, ['xero_log_cleared_at' => date('Y-m-d H:i:s')]);
    logAudit($pdo, 'xero_log_clear', 'settings', null, 'Cleared Xero sync log view');
    setFlash('success', 'Sync log cleared.');
    header('Location: ' . BASE_URL . '/admin/xero_log.php');
    exit;
}

$clearedAt = getSetting($pdo, 'xero_log_cleared_at', '');

// ---- Filters ----
$fEntity = $_GET['entity'] ?? 'all';
$fStatus = $_GET['status'] ?? 'all';
if (!in_array($fEntity, ['all','customer','invoice','quote','item','payment'], true)) $fEntity = 'all';
if (!in_array($fStatus, ['all','ok','error'], true)) $fStatus = 'all';

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 100;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];
if ($clearedAt !== '') { $where[] = 'ts > :cleared'; $params[':cleared'] = $clearedAt; }
if ($fEntity !== 'all') { $where[] = 'entity = :ent'; $params[':ent'] = $fEntity; }
if ($fStatus !== 'all') { $where[] = 'status = :st';  $params[':st']  = $fStatus; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$total = 0;
$errorCount = 0;
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM xero_sync_log $whereSQL");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $ec = $pdo->prepare("SELECT COUNT(*) FROM xero_sync_log " . ($clearedAt !== '' ? "WHERE ts > :cleared AND status='error'" : "WHERE status='error'"));
    $ec->execute($clearedAt !== '' ? [':cleared' => $clearedAt] : []);
    $errorCount = (int)$ec->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM xero_sync_log $whereSQL ORDER BY id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) { /* table may not exist */ }

$totalPages = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 class="page-title">Xero Sync Log</h2>
        <p class="page-subtitle">
            <?= number_format($total) ?> entries
            <?php if ($errorCount > 0): ?>
                · <span style="color:#DC2626;font-weight:600;"><?= number_format($errorCount) ?> error(s)</span>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.6rem;">
        <a href="<?= BASE_URL ?>/admin/xero.php" class="btn btn-outline btn-sm">← Xero Sync</a>
        <?php if ($total > 0): ?>
        <form method="POST" action="" onsubmit="return confirm('Clear the sync log view? Older entries are hidden (not permanently deleted).');">
            <input type="hidden" name="action" value="clear_log">
            <button type="submit" class="btn btn-sm" style="background:#FEE2E2;color:#DC2626;border:1px solid #FCA5A5;">🗑 Clear Log</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:1rem;">
    <div class="card-body" style="padding:.75rem 1rem;">
        <form method="GET" action="" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:.8rem;">Entity</label>
                <select name="entity" class="form-control form-select" style="min-width:130px;">
                    <?php foreach (['all'=>'All','customer'=>'Customers','invoice'=>'Invoices','quote'=>'Quotes','item'=>'Items','payment'=>'Payments'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $fEntity===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-control form-select" style="min-width:120px;">
                    <option value="all"   <?= $fStatus==='all'?'selected':'' ?>>All</option>
                    <option value="error" <?= $fStatus==='error'?'selected':'' ?>>Errors only</option>
                    <option value="ok"    <?= $fStatus==='ok'?'selected':'' ?>>Success only</option>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?= BASE_URL ?>/admin/xero_log.php" class="btn btn-outline btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Log table -->
<div class="card">
    <div class="table-responsive">
        <table class="table" style="width:100%;font-size:.85rem;">
            <thead>
                <tr>
                    <th style="white-space:nowrap;">Time</th>
                    <th>Dir</th>
                    <th>Entity</th>
                    <th>Action</th>
                    <th style="width:55%;">Message</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:2rem;">
                    <?= $clearedAt !== '' ? 'No new sync activity since the log was cleared.' : 'No sync activity yet.' ?>
                </td></tr>
                <?php else: foreach ($rows as $lg): ?>
                <tr style="<?= $lg['status']==='error' ? 'background:#FEF2F2;' : '' ?>">
                    <td style="white-space:nowrap;color:#6B7280;"><?= date('d M H:i:s', strtotime($lg['ts'])) ?></td>
                    <td style="white-space:nowrap;"><?= $lg['direction']==='push' ? '⬆' : '⬇' ?> <?= htmlspecialchars($lg['direction']) ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($lg['entity']) ?><?= $lg['entity_id'] ? ' #' . (int)$lg['entity_id'] : '' ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($lg['status']==='error'): ?>
                            <span style="background:#FEE2E2;color:#DC2626;padding:.1rem .45rem;border-radius:4px;font-weight:600;">error</span>
                        <?php else: ?>
                            <span style="color:#16A34A;"><?= htmlspecialchars($lg['action']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#374151;word-break:break-word;line-height:1.45;">
                        <?php if (!empty($lg['xero_id'])): ?><span style="color:#9CA3AF;font-family:monospace;font-size:.78rem;">[<?= htmlspecialchars($lg['xero_id']) ?>]</span> <?php endif; ?>
                        <?= htmlspecialchars($lg['message']) ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-body" style="padding:.75rem 1rem;border-top:1px solid var(--color-border);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <?php $base = '?entity=' . $fEntity . '&status=' . $fStatus; ?>
        <span style="font-size:.85rem;color:#6B7280;">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page > 1): ?><a href="<?= $base ?>&p=<?= $page-1 ?>" class="btn btn-sm btn-outline">← Prev</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="<?= $base ?>&p=<?= $page+1 ?>" class="btn btn-sm btn-outline">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
