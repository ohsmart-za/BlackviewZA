<?php
// ============================================================
// Blackview SA Portal — Admin: Xero Sync
// Connect to Xero, configure defaults, run sync, view log.
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/xero_client.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Xero Sync';
$summary   = null;

// ============================================================
// POST actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        saveSettings($pdo, [
            'xero_client_id'            => trim($_POST['xero_client_id']     ?? ''),
            'xero_client_secret'        => trim($_POST['xero_client_secret'] ?? '') !== ''
                                            ? trim($_POST['xero_client_secret'])
                                            : xeroSetting('xero_client_secret'), // keep old if left blank
            'xero_redirect_uri'         => trim($_POST['xero_redirect_uri']  ?? '') ?: BASE_URL . '/auth/xero_callback.php',
            'xero_scopes'               => trim($_POST['xero_scopes'] ?? '') ?: XeroClient::DEFAULT_SCOPES,
            'xero_account_code'         => trim($_POST['xero_account_code']  ?? '200'),
            'xero_tax_type'             => trim($_POST['xero_tax_type']      ?? 'OUTPUT2'),
            'xero_payment_account_code' => trim($_POST['xero_payment_account_code'] ?? ''),
        ]);
        setFlash('success', 'Xero settings saved.');
        header('Location: ' . BASE_URL . '/admin/xero.php');
        exit;
    }

    if ($action === 'connect') {
        header('Location: ' . XeroClient::authorizeUrl());
        exit;
    }

    if ($action === 'disconnect') {
        XeroClient::disconnect();
        logAudit($pdo, 'xero_disconnect', 'settings', null, 'Disconnected from Xero');
        setFlash('info', 'Disconnected from Xero.');
        header('Location: ' . BASE_URL . '/admin/xero.php');
        exit;
    }

    if ($action === 'pick_tenant' && !empty($_POST['tenant_id'])) {
        saveSettings($pdo, [
            'xero_tenant_id'   => $_POST['tenant_id'],
            'xero_tenant_name' => $_POST['tenant_name'] ?? $_POST['tenant_id'],
        ]);
        setFlash('success', 'Xero organisation selected.');
        header('Location: ' . BASE_URL . '/admin/xero.php');
        exit;
    }

    if ($action === 'sync_now') {
        require_once __DIR__ . '/../config/xero_sync.php';
        try {
            set_time_limit(280);
            $summary = XeroSync::run();
            logAudit($pdo, 'xero_sync', 'settings', null, 'Manual sync: ' . json_encode($summary));
        } catch (Throwable $e) {
            setFlash('error', 'Sync failed: ' . $e->getMessage());
        }
    }
}

// ============================================================
// Load state
// ============================================================
$status  = XeroClient::status();
$tenants = [];
if (!empty($status['connected']) && empty($status['tenant_id'])) {
    try { $tenants = XeroClient::listTenants(); } catch (Throwable $e) { /* token issue — shown below */ }
}

$lastSync = xeroSetting('xero_last_sync_at');

// Quick counts
$counts = ['cust_linked' => 0, 'cust_total' => 0, 'inv_pending' => 0, 'inv_linked' => 0, 'mirror' => 0];
try {
    $counts['cust_total']  = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $counts['cust_linked'] = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE xero_id IS NOT NULL")->fetchColumn();
    $counts['inv_linked']  = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE xero_id IS NOT NULL")->fetchColumn();
    $counts['inv_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'active' AND xero_id IS NULL")->fetchColumn();
    $counts['mirror']      = (int)$pdo->query("SELECT COUNT(*) FROM xero_invoices_mirror")->fetchColumn();
} catch (Throwable $e) { /* migration not run yet */ }

// Recent log
$logRows = [];
try {
    $logRows = $pdo->query("SELECT * FROM xero_sync_log ORDER BY id DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) { /* ignore */ }

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 class="page-title">Xero Sync</h2>
        <p class="page-subtitle">Two-way sync of customers, invoices and quotes with your Xero organisation.</p>
    </div>
    <?php if (!empty($status['connected']) && !empty($status['tenant_id'])): ?>
    <form method="POST" action="">
        <input type="hidden" name="action" value="sync_now">
        <button type="submit" class="btn btn-primary">🔄 Sync Now</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($summary): ?>
<div class="alert alert-success">
    ✅ Sync complete —
    Customers: <strong><?= $summary['pushed_customers'] ?> pushed / <?= $summary['pulled_customers'] ?> pulled</strong> ·
    Invoices: <strong><?= $summary['pushed_invoices'] ?> pushed / <?= $summary['pulled_invoices'] ?> updated / <?= $summary['mirrored_invoices'] ?> mirrored</strong> ·
    Quotes: <strong><?= $summary['pushed_quotes'] ?> pushed</strong>
    <?php if ($summary['errors'] > 0): ?>
        · <span style="color:#DC2626;font-weight:700;"><?= $summary['errors'] ?> error(s) — see log below</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="two-col-layout">

<!-- LEFT: Connection + Settings -->
<div class="col-side">

    <!-- Connection status -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Connection</h3></div>
        <div class="card-body">
            <?php if (!empty($status['connected'])): ?>
                <p style="margin-bottom:.5rem;">
                    <span style="background:#DCFCE7;color:#16A34A;padding:.25rem .7rem;border-radius:6px;font-weight:600;font-size:.85rem;">● Connected</span>
                </p>
                <?php if (!empty($status['tenant_name'])): ?>
                    <p style="font-size:.9rem;color:#374151;">Organisation: <strong><?= htmlspecialchars($status['tenant_name']) ?></strong></p>
                <?php endif; ?>
                <?php if ($lastSync): ?>
                    <p style="font-size:.85rem;color:#6B7280;">Last sync: <?= htmlspecialchars($lastSync) ?></p>
                <?php endif; ?>

                <?php if (empty($status['tenant_id']) && !empty($tenants)): ?>
                    <div class="alert alert-warning" style="margin-top:.75rem;">Pick which Xero organisation to sync with:</div>
                    <?php foreach ($tenants as $t): ?>
                    <form method="POST" action="" style="margin-bottom:.4rem;">
                        <input type="hidden" name="action" value="pick_tenant">
                        <input type="hidden" name="tenant_id" value="<?= htmlspecialchars($t['tenantId']) ?>">
                        <input type="hidden" name="tenant_name" value="<?= htmlspecialchars($t['tenantName'] ?? '') ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%;text-align:left;">
                            🏢 <?= htmlspecialchars($t['tenantName'] ?? $t['tenantId']) ?>
                        </button>
                    </form>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="POST" action="" style="margin-top:1rem;"
                      onsubmit="return confirm('Disconnect from Xero? Links stay in place; you can reconnect anytime.');">
                    <input type="hidden" name="action" value="disconnect">
                    <button type="submit" class="btn btn-outline btn-sm" style="color:#DC2626;border-color:#FCA5A5;">Disconnect</button>
                </form>
            <?php else: ?>
                <p style="margin-bottom:.75rem;">
                    <span style="background:#FEF2F2;color:#DC2626;padding:.25rem .7rem;border-radius:6px;font-weight:600;font-size:.85rem;">● Not connected</span>
                </p>
                <?php if (xeroSetting('xero_client_id') === ''): ?>
                    <p style="font-size:.85rem;color:#6B7280;">Enter your Xero app credentials below first, then connect.</p>
                <?php else: ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="connect">
                        <button type="submit" class="btn btn-primary" style="width:100%;">🔗 Connect to Xero</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sync stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#2563EB;"><?= $counts['cust_linked'] ?>/<?= $counts['cust_total'] ?></div>
            <div style="font-size:.78rem;color:#6B7280;">Customers linked</div>
        </div>
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.4rem;font-weight:700;color:<?= $counts['inv_pending'] > 0 ? '#D97706' : '#16A34A' ?>;"><?= $counts['inv_pending'] ?></div>
            <div style="font-size:.78rem;color:#6B7280;">Invoices awaiting push</div>
        </div>
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#16A34A;"><?= $counts['inv_linked'] ?></div>
            <div style="font-size:.78rem;color:#6B7280;">Invoices on Xero</div>
        </div>
        <div class="card" style="text-align:center;padding:1rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#7C3AED;"><?= $counts['mirror'] ?></div>
            <div style="font-size:.78rem;color:#6B7280;">Xero-only invoices</div>
        </div>
    </div>

    <!-- Settings -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Xero App Settings</h3></div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_settings">

                <div class="form-group">
                    <label class="form-label">Client ID</label>
                    <input type="text" name="xero_client_id" class="form-control" style="font-family:monospace;font-size:.8rem;"
                           value="<?= htmlspecialchars(xeroSetting('xero_client_id')) ?>"
                           placeholder="From developer.xero.com → My Apps">
                </div>
                <div class="form-group">
                    <label class="form-label">Client Secret</label>
                    <input type="password" name="xero_client_secret" class="form-control" style="font-family:monospace;font-size:.8rem;"
                           value="" placeholder="<?= xeroSetting('xero_client_secret') !== '' ? '••••••••  (saved — leave blank to keep)' : 'Paste client secret' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Redirect URI <span style="color:#9CA3AF;font-weight:400;">(register this in your Xero app)</span></label>
                    <input type="text" name="xero_redirect_uri" class="form-control" style="font-family:monospace;font-size:.8rem;"
                           value="<?= htmlspecialchars(xeroSetting('xero_redirect_uri', BASE_URL . '/auth/xero_callback.php')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Scopes</label>
                    <input type="text" name="xero_scopes" class="form-control" style="font-family:monospace;font-size:.75rem;"
                           value="<?= htmlspecialchars(xeroSetting('xero_scopes', XeroClient::DEFAULT_SCOPES)) ?>">
                </div>

                <hr style="border:none;border-top:1px solid #E5E7EB;margin:1rem 0;">

                <div class="form-group">
                    <label class="form-label">Sales Account Code</label>
                    <input type="text" name="xero_account_code" class="form-control" style="max-width:120px;"
                           value="<?= htmlspecialchars(xeroSetting('xero_account_code', '200')) ?>">
                    <small class="form-hint">Xero chart-of-accounts code for sales lines (default 200 = Sales).</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Tax Type</label>
                    <input type="text" name="xero_tax_type" class="form-control" style="max-width:160px;"
                           value="<?= htmlspecialchars(xeroSetting('xero_tax_type', 'OUTPUT2')) ?>">
                    <small class="form-hint">SA orgs: <code>OUTPUT2</code> = 15% VAT on income. Demo Company (Global) uses <code>OUTPUT</code>.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Account Code <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                    <input type="text" name="xero_payment_account_code" class="form-control" style="max-width:120px;"
                           value="<?= htmlspecialchars(xeroSetting('xero_payment_account_code')) ?>">
                    <small class="form-hint">If set (e.g. a bank/undeposited-funds account code with "Enable payments" ticked in Xero),
                        POS payments are pushed with each invoice so cash sales show as paid in Xero.
                        Leave blank to reconcile payments from the bank feed instead.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RIGHT: How it works + Log -->
<div class="col-main">

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">How the sync works</h3></div>
        <div class="card-body" style="font-size:.875rem;color:#374151;line-height:1.6;">
            <ul style="margin:0;padding-left:1.2rem;">
                <li><strong>Customers</strong> sync both ways and merge by Xero link, then email, then name. Local edits win conflicts.</li>
                <li><strong>Finalised invoices</strong> push to Xero as approved (AUTHORISED) sales invoices — drafts stay local until finalised.</li>
                <li><strong>Voided</strong> portal invoices are voided in Xero too (unless they already have payments there).</li>
                <li><strong>Payments &amp; status</strong> recorded in Xero flow back — the CRM shows the live Xero balance per invoice.</li>
                <li><strong>Invoices created directly in Xero</strong> appear in the customer's CRM history and count toward total spend.</li>
                <li><strong>Sent/accepted quotes</strong> push to Xero Quotes.</li>
            </ul>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Sync Log</h3></div>
        <div class="table-responsive">
            <table class="table table-striped" style="font-size:.82rem;">
                <thead>
                    <tr>
                        <th>Time</th><th>Dir</th><th>Entity</th><th>Action</th><th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logRows)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:1.5rem;">No sync activity yet.</td></tr>
                    <?php else: foreach ($logRows as $lg): ?>
                    <tr>
                        <td style="white-space:nowrap;color:#6B7280;"><?= date('d M H:i', strtotime($lg['ts'])) ?></td>
                        <td><?= $lg['direction'] === 'push' ? '⬆' : '⬇' ?> <?= htmlspecialchars($lg['direction']) ?></td>
                        <td><?= htmlspecialchars($lg['entity']) ?><?= $lg['entity_id'] ? ' #' . (int)$lg['entity_id'] : '' ?></td>
                        <td>
                            <?php if ($lg['status'] === 'error'): ?>
                                <span style="background:#FEF2F2;color:#DC2626;padding:.1rem .4rem;border-radius:4px;font-weight:600;">error</span>
                            <?php else: ?>
                                <span style="color:#16A34A;"><?= htmlspecialchars($lg['action']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#374151;word-break:break-word;"><?= htmlspecialchars($lg['message']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- .two-col-layout -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
