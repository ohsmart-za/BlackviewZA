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

// Auto-heal the retired 'accounting.transactions' scope (retired by Xero for apps
// registered after 2 Mar 2026 — causes "invalid_scope"). Upgrade to granular scopes.
$savedScopes = xeroSetting('xero_scopes', '');
if ($savedScopes !== '' && strpos($savedScopes, 'accounting.transactions') !== false) {
    saveSettings($pdo, ['xero_scopes' => XeroClient::DEFAULT_SCOPES]);
    setFlash('info', 'Updated Xero scopes — the old "accounting.transactions" scope was retired by Xero. Please reconnect.');
    header('Location: ' . BASE_URL . '/admin/xero.php');
    exit;
}

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

    if ($action === 'save_sync_mode') {
        $enabled   = isset($_POST['sync_enabled']) ? '1' : '0';
        $direction = in_array($_POST['sync_direction'] ?? '', ['both','push','pull'], true)
                     ? $_POST['sync_direction'] : 'both';
        $fromDate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['sync_from_date'] ?? '')
                     ? $_POST['sync_from_date'] : '';
        saveSettings($pdo, [
            'xero_sync_enabled'   => $enabled,
            'xero_sync_direction' => $direction,
            'xero_sync_from_date' => $fromDate,
            'xero_sync_customers' => isset($_POST['sync_customers']) ? '1' : '0',
            'xero_sync_invoices'  => isset($_POST['sync_invoices'])  ? '1' : '0',
            'xero_sync_quotes'    => isset($_POST['sync_quotes'])    ? '1' : '0',
        ]);
        logAudit($pdo, 'xero_sync_mode', 'settings', null,
            "Sync " . ($enabled === '1' ? 'ON' : 'OFF') . ", dir: $direction, from: " . ($fromDate ?: 'all'));
        setFlash('success', 'Sync mode updated.');
        header('Location: ' . BASE_URL . '/admin/xero.php');
        exit;
    }

    if (($action === 'push_invoice' || $action === 'repush_invoice') && !empty($_POST['invoice_id'])) {
        require_once __DIR__ . '/../config/xero_sync.php';
        $force = ($action === 'repush_invoice');
        $res = XeroSync::pushSingleInvoice((int)$_POST['invoice_id'], $force);
        logAudit($pdo, $force ? 'xero_repush_invoice' : 'xero_push_invoice', 'invoices', (int)$_POST['invoice_id'], $res['message'] ?? '');
        setFlash($res['ok'] ? 'success' : 'error', 'Xero: ' . ($res['message'] ?? ''));
        $back = $_POST['return'] ?? (BASE_URL . '/admin/xero.php');
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'save_payment_map') {
        $map = [];
        foreach (($_POST['pm'] ?? []) as $code => $acct) {
            $acct = trim($acct);
            if ($acct !== '') $map[$code] = $acct;
        }
        saveSettings($pdo, ['xero_payment_map' => json_encode($map)]);
        logAudit($pdo, 'xero_payment_map', 'settings', null, 'Payment→Xero account map: ' . json_encode($map));
        setFlash('success', 'Payment method mapping saved.');
        header('Location: ' . BASE_URL . '/admin/xero.php');
        exit;
    }

    if ($action === 'refresh_meta') {
        try {
            $accts = XeroClient::get('Accounts')['Accounts'] ?? [];
            $rates = XeroClient::get('TaxRates')['TaxRates'] ?? [];
            saveSettings($pdo, [
                'xero_accounts_json'  => json_encode($accts),
                'xero_taxrates_json'  => json_encode($rates),
                'xero_meta_fetched_at'=> date('Y-m-d H:i:s'),
            ]);
            setFlash('success', 'Loaded ' . count($accts) . ' accounts and ' . count($rates) . ' tax rates from Xero. Now pick the correct ones below.');
        } catch (Throwable $e) {
            setFlash('error', 'Could not load from Xero: ' . $e->getMessage());
        }
        header('Location: ' . BASE_URL . '/admin/xero.php');
        exit;
    }

    if ($action === 'sync_now') {
        require_once __DIR__ . '/../config/xero_sync.php';
        try {
            if (xeroSetting('xero_sync_enabled', '1') !== '1') {
                throw new RuntimeException('Sync is currently switched OFF. Turn it on in the Sync Control panel first.');
            }
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

$lastSync      = xeroSetting('xero_last_sync_at');
$syncEnabled   = xeroSetting('xero_sync_enabled', '1') === '1';
$syncDirection = xeroSetting('xero_sync_direction', 'both');
$syncFromDate  = xeroSetting('xero_sync_from_date', '');
$syncCustomers = xeroSetting('xero_sync_customers', '1') === '1';
$syncInvoices  = xeroSetting('xero_sync_invoices',  '0') === '1';
$syncQuotes    = xeroSetting('xero_sync_quotes',    '0') === '1';

// Cached Xero accounts + tax rates (fetched via "Load from Xero")
$xeroAccounts  = json_decode(xeroSetting('xero_accounts_json', '[]'), true) ?: [];
$xeroTaxRates  = json_decode(xeroSetting('xero_taxrates_json', '[]'), true) ?: [];
$metaFetchedAt = xeroSetting('xero_meta_fetched_at', '');
$metaLoadError = '';

// Auto-load accounts + tax rates the first time (so the dropdowns just appear).
// If it fails on scope, the token predates the accounting.settings grant → reconnect.
if (empty($xeroTaxRates) && !empty($status['connected']) && !empty($status['tenant_id'])) {
    try {
        $accts = XeroClient::get('Accounts')['Accounts'] ?? [];
        $rates = XeroClient::get('TaxRates')['TaxRates'] ?? [];
        if (!empty($rates)) {
            saveSettings($pdo, [
                'xero_accounts_json'   => json_encode($accts),
                'xero_taxrates_json'   => json_encode($rates),
                'xero_meta_fetched_at' => date('Y-m-d H:i:s'),
            ]);
            $xeroAccounts  = $accts;
            $xeroTaxRates  = $rates;
            $metaFetchedAt = date('Y-m-d H:i:s');
        }
    } catch (Throwable $e) {
        $metaLoadError = $e->getMessage();
    }
}

// Split accounts into sales (revenue) and payment-enabled (bank/asset)
$salesAccounts   = [];
$paymentAccounts = [];
foreach ($xeroAccounts as $a) {
    $cls  = strtoupper($a['Class'] ?? '');
    $type = strtoupper($a['Type'] ?? '');
    if ($cls === 'REVENUE' || in_array($type, ['REVENUE','SALES','OTHERINCOME'], true)) {
        $salesAccounts[] = $a;
    }
    if (!empty($a['EnablePaymentsToAccount']) || $cls === 'ASSET' || $type === 'BANK') {
        $paymentAccounts[] = $a;
    }
}
$curAccount    = xeroSetting('xero_account_code', '200');
$curTaxType    = xeroSetting('xero_tax_type', 'OUTPUT2');
$curPayAccount = xeroSetting('xero_payment_account_code', '');

// Payment methods + their current Xero-account mapping
$paymentMethods = [];
try {
    $paymentMethods = $pdo->query("SELECT code, name, icon FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (Throwable $e) { /* table may not exist */ }
$paymentMap = json_decode(xeroSetting('xero_payment_map', '{}'), true) ?: [];

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

<style>
/* iOS-style toggle switch */
.xero-switch { position: relative; display: inline-block; width: 52px; height: 30px; flex-shrink: 0; }
.xero-switch input { opacity: 0; width: 0; height: 0; }
.xero-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #CBD5E1; border-radius: 30px; transition: background .2s;
}
.xero-slider::before {
    content: ""; position: absolute; height: 24px; width: 24px; left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.3);
}
.xero-switch input:checked + .xero-slider { background: #16A34A; }
.xero-switch input:checked + .xero-slider::before { transform: translateX(22px); }

/* Direction selector */
.xero-dir-group { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; }
.xero-dir-option {
    display: block; text-align: center; padding: .85rem .6rem; cursor: pointer;
    border: 2px solid #E5E7EB; border-radius: 10px; transition: border-color .15s, background .15s;
    position: relative;
}
.xero-dir-option:hover { border-color: #93C5FD; background: #F8FAFC; }
.xero-dir-option.selected { border-color: #2563EB; background: #EFF6FF; }
@media (max-width: 640px) { .xero-dir-group { grid-template-columns: 1fr; } }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 class="page-title">Xero Sync</h2>
        <p class="page-subtitle">Two-way sync of customers, invoices and quotes with your Xero organisation.</p>
    </div>
    <?php if (!empty($status['connected']) && !empty($status['tenant_id'])): ?>
    <form method="POST" action="">
        <input type="hidden" name="action" value="sync_now">
        <button type="submit" class="btn btn-primary" <?= $syncEnabled ? '' : 'disabled title="Sync is switched off"' ?>>
            🔄 Sync Now
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if ($summary && !empty($summary['skipped'])): ?>
<div class="alert alert-warning">⏸ <?= htmlspecialchars($summary['skipped']) ?> Turn it on in Sync Control to run.</div>
<?php elseif ($summary): ?>
<div class="alert alert-success">
    ✅ Sync complete (<?= htmlspecialchars($summary['direction'] === 'push' ? 'push only' : ($summary['direction'] === 'pull' ? 'pull only' : 'both ways')) ?>) —
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

                <?php if ($metaLoadError !== ''): ?>
                <div class="alert alert-error" style="margin-bottom:1rem;">
                    ⚠ Couldn't load accounts/tax rates from Xero:<br>
                    <span style="font-size:.82rem;"><?= htmlspecialchars($metaLoadError) ?></span>
                    <?php if (stripos($metaLoadError, 'scope') !== false || stripos($metaLoadError, '403') !== false): ?>
                    <br><br><strong>This means your Xero connection is missing the "settings" permission.</strong>
                    Click <strong>Disconnect</strong> then <strong>Connect to Xero</strong> again to re-grant it.
                    <?php endif; ?>
                </div>
                <?php elseif (empty($xeroTaxRates) && !empty($status['connected'])): ?>
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    ⚠ Click <strong>Load from Xero</strong> below to fetch your real accounts and tax rates,
                    then pick the correct 15% sales tax rate — otherwise invoices push with the wrong tax code.
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Sales Account</label>
                    <?php if (!empty($salesAccounts)): ?>
                    <select name="xero_account_code" class="form-control">
                        <?php foreach ($salesAccounts as $a): ?>
                        <option value="<?= htmlspecialchars($a['Code']) ?>" <?= $curAccount === ($a['Code'] ?? '') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['Code'] . ' — ' . ($a['Name'] ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" name="xero_account_code" class="form-control" style="max-width:120px;"
                           value="<?= htmlspecialchars($curAccount) ?>">
                    <small class="form-hint">Chart-of-accounts code for sales lines (default 200 = Sales).</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Tax Rate</label>
                    <?php if (!empty($xeroTaxRates)): ?>
                    <select name="xero_tax_type" class="form-control">
                        <?php foreach ($xeroTaxRates as $t):
                            if (($t['Status'] ?? 'ACTIVE') !== 'ACTIVE') continue;
                            $tt = $t['TaxType'] ?? '';
                            $rate = isset($t['EffectiveRate']) ? ' (' . rtrim(rtrim(number_format((float)$t['EffectiveRate'],2),'0'),'.') . '%)' : '';
                        ?>
                        <option value="<?= htmlspecialchars($tt) ?>" <?= $curTaxType === $tt ? 'selected' : '' ?>>
                            <?= htmlspecialchars(($t['Name'] ?? $tt) . $rate) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Pick <strong>Standard Rate Sales (15%)</strong> — not the old 14% rate.</small>
                    <?php else: ?>
                    <input type="text" name="xero_tax_type" class="form-control" style="max-width:160px;"
                           value="<?= htmlspecialchars($curTaxType) ?>">
                    <small class="form-hint">Load from Xero to pick the right one. <code>OUTPUT2</code> is NOT always 15% — in some orgs it's the old 14% rate.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Account <span style="color:#9CA3AF;font-weight:400;">(for marking invoices paid)</span></label>
                    <?php if (!empty($paymentAccounts)): ?>
                    <select name="xero_payment_account_code" class="form-control">
                        <option value="">— Don't push payments (reconcile via bank feed) —</option>
                        <?php foreach ($paymentAccounts as $a): ?>
                        <option value="<?= htmlspecialchars($a['Code']) ?>" <?= $curPayAccount === ($a['Code'] ?? '') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['Code'] . ' — ' . ($a['Name'] ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Pick the bank/cash account POS payments land in. Set this so paid invoices show <strong>Amount due 0.00</strong> in Xero.</small>
                    <?php else: ?>
                    <input type="text" name="xero_payment_account_code" class="form-control" style="max-width:120px;"
                           value="<?= htmlspecialchars($curPayAccount) ?>">
                    <small class="form-hint">A bank/cash account code with "Enable payments" ticked in Xero. Load from Xero to pick it. Blank = don't push payments.</small>
                    <?php endif; ?>
                </div>

                <div class="form-actions" style="display:flex;gap:.6rem;align-items:center;">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>

            <?php if (!empty($status['connected'])): ?>
            <form method="POST" action="" style="margin-top:.75rem;">
                <input type="hidden" name="action" value="refresh_meta">
                <button type="submit" class="btn btn-outline btn-sm">🔄 Load accounts &amp; tax rates from Xero</button>
                <?php if ($metaFetchedAt): ?>
                    <span style="font-size:.78rem;color:#9CA3AF;margin-left:.5rem;">last loaded <?= htmlspecialchars($metaFetchedAt) ?></span>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Method → Xero Account mapping -->
    <?php if (!empty($paymentMethods)): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header"><h3 class="card-title">Payment Methods → Xero Accounts</h3></div>
        <div class="card-body">
            <p style="font-size:.82rem;color:#6B7280;margin:0 0 1rem;">
                Map each payment method to the Xero bank/cash account its money lands in.
                When a paid invoice pushes, its payment goes to the matched account.
                Unmapped methods fall back to the default Payment Account above.
            </p>
            <?php if (empty($paymentAccounts)): ?>
            <div class="alert alert-warning" style="font-size:.85rem;">
                Click <strong>Load accounts &amp; tax rates from Xero</strong> above first to choose accounts here.
            </div>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_payment_map">
                <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:.4rem .5rem;color:#6B7280;font-size:.75rem;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Payment Method</th>
                            <th style="text-align:left;padding:.4rem .5rem;color:#6B7280;font-size:.75rem;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Xero Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentMethods as $pm):
                            $cur = $paymentMap[$pm['code']] ?? '';
                        ?>
                        <tr>
                            <td style="padding:.45rem .5rem;border-bottom:1px solid #F3F4F6;">
                                <?= htmlspecialchars($pm['icon'] ?? '') ?> <strong><?= htmlspecialchars($pm['name']) ?></strong>
                                <span style="color:#9CA3AF;font-family:monospace;font-size:.78rem;">(<?= htmlspecialchars($pm['code']) ?>)</span>
                            </td>
                            <td style="padding:.45rem .5rem;border-bottom:1px solid #F3F4F6;">
                                <?php if (!empty($paymentAccounts)): ?>
                                <select name="pm[<?= htmlspecialchars($pm['code']) ?>]" class="form-control" style="min-width:200px;">
                                    <option value="">— Use default / don't push —</option>
                                    <?php foreach ($paymentAccounts as $a): ?>
                                    <option value="<?= htmlspecialchars($a['Code']) ?>" <?= $cur === ($a['Code'] ?? '') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['Code'] . ' — ' . ($a['Name'] ?? '')) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <input type="text" name="pm[<?= htmlspecialchars($pm['code']) ?>]" class="form-control"
                                       style="max-width:120px;" value="<?= htmlspecialchars($cur) ?>" placeholder="Account code">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="form-actions" style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary">Save Mapping</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- RIGHT: Controls + How it works + Log -->
<div class="col-main">

    <!-- Sync Control: on/off + direction -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3 class="card-title">Sync Control</h3></div>
        <div class="card-body">
            <form method="POST" action="" id="sync-mode-form">
                <input type="hidden" name="action" value="save_sync_mode">

                <!-- Master on/off -->
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.5rem 0 1rem;border-bottom:1px solid #E5E7EB;margin-bottom:1rem;">
                    <div>
                        <div style="font-weight:600;font-size:.95rem;">Sync <?= $syncEnabled ? 'Enabled' : 'Disabled' ?></div>
                        <div style="font-size:.82rem;color:#6B7280;margin-top:.15rem;">
                            Master switch. When off, no data moves in either direction (the Xero connection stays intact).
                        </div>
                    </div>
                    <label class="xero-switch">
                        <input type="checkbox" name="sync_enabled" value="1" <?= $syncEnabled ? 'checked' : '' ?>
                               onchange="document.getElementById('sync-mode-form').submit()">
                        <span class="xero-slider"></span>
                    </label>
                </div>

                <!-- What to sync (independent entities) -->
                <div style="margin-bottom:1.25rem;">
                    <label class="form-label" style="margin-bottom:.5rem;">What to sync</label>
                    <div style="display:flex;flex-direction:column;gap:.6rem;">
                        <?php
                        $entities = [
                            'sync_customers' => ['Contacts / Customers', $syncCustomers, 'Sync customer records with Xero contacts.'],
                            'sync_invoices'  => ['Invoices', $syncInvoices, 'Auto-push finalised invoices in bulk. Leave OFF to push them one-by-one from the invoice list.'],
                            'sync_quotes'    => ['Quotes', $syncQuotes, 'Push sent/accepted quotes to Xero.'],
                        ];
                        foreach ($entities as $field => [$label, $on, $desc]):
                        ?>
                        <label style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem .85rem;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer;">
                            <span>
                                <span style="font-weight:600;font-size:.9rem;"><?= $label ?></span>
                                <span style="display:block;font-size:.78rem;color:#6B7280;margin-top:.15rem;line-height:1.4;"><?= $desc ?></span>
                            </span>
                            <span class="xero-switch" style="width:44px;height:26px;">
                                <input type="checkbox" name="<?= $field ?>" value="1" <?= $on ? 'checked' : '' ?>
                                       onchange="document.getElementById('sync-mode-form').submit()">
                                <span class="xero-slider"></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Sync start date -->
                <div style="margin-bottom:1.25rem;padding:.85rem 1rem;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;">
                    <label class="form-label" style="margin-bottom:.35rem;color:#92400E;">🛡 Only sync invoices dated on/after</label>
                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                        <input type="date" name="sync_from_date" value="<?= htmlspecialchars($syncFromDate) ?>"
                               class="form-control" style="max-width:180px;"
                               onchange="document.getElementById('sync-mode-form').submit()">
                        <?php if ($syncFromDate): ?>
                            <button type="submit" name="sync_from_date" value=""
                                    class="btn btn-sm btn-outline">Clear (sync all dates)</button>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.78rem;color:#92400E;margin-top:.5rem;line-height:1.5;">
                        Invoices and quotes dated <strong>before</strong> this date are never pushed to Xero,
                        and historical Xero invoices before it are never pulled in. Set this to protect the
                        invoices you already captured manually in Xero. Leave blank to sync everything.
                    </div>
                </div>

                <!-- Direction -->
                <div>
                    <label class="form-label" style="margin-bottom:.5rem;">Sync Direction</label>
                    <div class="xero-dir-group">
                        <?php
                        $dirs = [
                            'both' => ['⇄', 'Merge Both Ways', 'Push portal changes to Xero and pull Xero changes back. Local edits win conflicts.'],
                            'push' => ['⬆', 'Portal → Xero Only', 'Only send portal customers/invoices/quotes to Xero. Never pull.'],
                            'pull' => ['⬇', 'Xero → Portal Only', 'Only pull Xero contacts/invoices into the portal. Never push.'],
                        ];
                        foreach ($dirs as $val => [$icon, $label, $desc]):
                            $checked = $syncDirection === $val;
                        ?>
                        <label class="xero-dir-option <?= $checked ? 'selected' : '' ?>">
                            <input type="radio" name="sync_direction" value="<?= $val ?>" <?= $checked ? 'checked' : '' ?>
                                   onchange="document.getElementById('sync-mode-form').submit()"
                                   style="position:absolute;opacity:0;">
                            <div style="font-size:1.3rem;line-height:1;"><?= $icon ?></div>
                            <div style="font-weight:600;font-size:.9rem;margin-top:.35rem;"><?= $label ?></div>
                            <div style="font-size:.78rem;color:#6B7280;margin-top:.25rem;line-height:1.4;"><?= $desc ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <noscript>
                    <div class="form-actions" style="margin-top:1rem;">
                        <button type="submit" class="btn btn-primary">Save Sync Mode</button>
                    </div>
                </noscript>
            </form>
        </div>
    </div>

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
