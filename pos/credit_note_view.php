<?php
// ============================================================
// Blackview SA Portal — View / Print Credit Note
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';

requireLogin();

$pdo = getDB();

$cnId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cnId === 0) {
    setFlash('error', 'Invalid credit note ID.');
    header('Location: ' . BASE_URL . '/pos/invoices.php');
    exit;
}

// Load credit note
$cnStmt = $pdo->prepare(
    "SELECT cn.*, inv.invoice_no, inv.id AS invoice_id,
            c.name AS customer_name, c.email AS customer_email,
            c.phone AS customer_phone, c.address AS customer_address,
            u.name AS created_by_name
     FROM credit_notes cn
     JOIN invoices inv ON inv.id = cn.invoice_id
     LEFT JOIN customers c ON c.id = inv.customer_id
     LEFT JOIN users u ON u.id = cn.created_by
     WHERE cn.id = :id LIMIT 1"
);
$cnStmt->execute([':id' => $cnId]);
$cn = $cnStmt->fetch();

if (!$cn) {
    setFlash('error', 'Credit note not found.');
    header('Location: ' . BASE_URL . '/pos/invoices.php');
    exit;
}

$pageTitle = 'Credit Note ' . $cn['credit_note_no'];

// Load line items
$cnLines = $pdo->prepare(
    "SELECT * FROM credit_note_items WHERE credit_note_id = :id ORDER BY id ASC"
);
$cnLines->execute([':id' => $cnId]);
$cnLines = $cnLines->fetchAll();

// Load company settings
$_appSettings = getSettings($pdo);
$coName    = !empty($_appSettings['company_name'])    ? $_appSettings['company_name']    : 'Blackview SA';
$coTagline = !empty($_appSettings['company_tagline']) ? $_appSettings['company_tagline'] : '';
$coVatNo   = $_appSettings['company_vat_no']   ?? '';
$coAddress = $_appSettings['company_address']  ?? '';
$coEmail   = $_appSettings['company_email']    ?? '';
$coPhone   = $_appSettings['company_phone']    ?? '';
$coLogo    = $_appSettings['logo_path']        ?? '';

// Void action — superuser only
if (isset($_GET['void']) && $_GET['void'] == '1' && $cn['status'] !== 'voided') {
    if (!isSuperuser()) {
        setFlash('error', 'Only superusers can void credit notes.');
        header('Location: ' . BASE_URL . '/pos/credit_note_view.php?id=' . $cnId);
        exit;
    }
    try {
        $pdo->beginTransaction();
        // Remove any payment allocation linked to this CN
        $pdo->prepare("DELETE FROM invoice_payments WHERE credit_note_id = :id")->execute([':id' => $cnId]);
        // Void the CN
        $pdo->prepare("UPDATE credit_notes SET status = 'voided' WHERE id = :id")->execute([':id' => $cnId]);
        logAudit($pdo, 'void_credit_note', 'credit_notes', $cnId,
            "Credit note {$cn['credit_note_no']} voided by {$_SESSION['user_name']}");
        $pdo->commit();
        setFlash('success', "Credit note {$cn['credit_note_no']} has been voided.");
        header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $cn['invoice_id']);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        setFlash('error', 'Could not void: ' . $e->getMessage());
    }
}

// Handle send email POST
$emailResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_email') {
    $toEmail     = trim($_POST['email_to']      ?? '');
    $toName      = trim($_POST['email_to_name'] ?? '');
    $personalMsg = trim($_POST['personal_note'] ?? '');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $emailResult = ['ok' => false, 'error' => 'Please enter a valid email address.'];
    } else {
        $personalNoteHtml = $personalMsg !== ''
            ? '<p style="background:#fffbeb;border-left:4px solid #d97706;padding:10px 14px;border-radius:4px;font-style:italic;color:#374151;">'
              . nl2br(htmlspecialchars($personalMsg)) . '</p>'
            : '';

        $vars = [
            'customer_name'  => $cn['customer_name'] ?: 'Valued Customer',
            'credit_note_no' => $cn['credit_note_no'],
            'invoice_no'     => $cn['invoice_no'],
            'date'           => date('d F Y', strtotime($cn['created_at'])),
            'total'          => number_format((float)$cn['total'], 2),
            'reason'         => $cn['reason'] ?? '',
            'personal_note'  => $personalNoteHtml,
            'company_name'   => $coName,
            'company_email'  => $coEmail,
            'company_phone'  => $coPhone,
        ];

        $emailResult = sendDocumentEmail($pdo, 'credit_note', $vars, $toEmail, $toName);
        if ($emailResult['ok']) {
            logAudit($pdo, 'email_credit_note', 'credit_notes', $cnId,
                "Credit note {$cn['credit_note_no']} emailed to $toEmail");
            setFlash('success', "Credit note emailed to $toEmail successfully.");
            header('Location: ' . BASE_URL . '/pos/credit_note_view.php?id=' . $cnId);
            exit;
        }
    }
}

// Handle log-print AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_print') {
    logAudit($pdo, 'print_credit_note', 'credit_notes', $cnId,
        "Credit note {$cn['credit_note_no']} printed / saved as PDF");
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

// Load document history from audit_log
$_docHistoryActions = ['email_credit_note','print_credit_note','void_credit_note'];
$_dhi = implode(',', array_fill(0, count($_docHistoryActions), '?'));
$_dhStmt = $pdo->prepare(
    "SELECT al.action, al.details, al.created_at, u.name AS user_name
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.entity = 'credit_notes' AND al.entity_id = ? AND al.action IN ($_dhi)
     ORDER BY al.created_at ASC"
);
$_dhStmt->execute(array_merge([$cnId], $_docHistoryActions));
$_docHistory = $_dhStmt->fetchAll();

$_docActionMeta = [
    'email_credit_note' => ['label' => 'Emailed', 'icon' => '✉️',  'color' => '#0369a1'],
    'print_credit_note' => ['label' => 'Printed', 'icon' => '🖨️', 'color' => '#374151'],
    'void_credit_note'  => ['label' => 'Voided',  'icon' => '🚫',  'color' => '#dc2626'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="invoice-footer-bar" style="margin-bottom:1.5rem;">
    <button type="button" class="btn btn-primary" onclick="printCreditNote()">Print / Save PDF</button>
    <a href="<?= BASE_URL ?>/pos/invoice.php?id=<?= $cn['invoice_id'] ?>" class="btn btn-outline">← Back to Invoice</a>
    <button type="button" class="btn btn-outline" style="color:#0369a1;border-color:#0369a1;"
            onclick="document.getElementById('emailPanel').style.display='block';this.style.display='none'">
        ✉️ Email to Client
    </button>
    <?php if (isSuperuser()): ?>
        <?php if ($cn['status'] !== 'voided'): ?>
        <a href="?id=<?= $cnId ?>&void=1"
           class="btn btn-outline" style="color:#dc2626;border-color:#dc2626;"
           onclick="return confirm('Void credit note <?= addslashes(htmlspecialchars($cn['credit_note_no'])) ?>? This is irreversible and any applied payment will be removed.')">
            🚫 Void Credit Note
        </a>
        <?php else: ?>
        <span class="btn btn-outline" style="color:#dc2626;border-color:#dc2626;opacity:.5;cursor:default;">
            🚫 Already Voided
        </span>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Email to Client panel -->
<?php if ($emailResult && !$emailResult['ok']): ?>
<div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($emailResult['error']) ?></div>
<?php endif; ?>
<div id="emailPanel" style="display:<?= ($emailResult && !$emailResult['ok']) ? 'block' : 'none' ?>;
     background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem;">
    <strong style="display:block;margin-bottom:.75rem;color:#0369a1;">✉️ Email Credit Note to Client</strong>
    <form method="POST" action="">
        <input type="hidden" name="action" value="send_email">
        <div class="form-row">
            <div class="form-group form-group--half">
                <label class="form-label">Recipient Email <span class="required">*</span></label>
                <input type="email" name="email_to" class="form-control" required
                       value="<?= htmlspecialchars($_POST['email_to'] ?? $cn['customer_email'] ?? '') ?>"
                       placeholder="client@example.com">
            </div>
            <div class="form-group form-group--half">
                <label class="form-label">Recipient Name</label>
                <input type="text" name="email_to_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['email_to_name'] ?? $cn['customer_name'] ?? '') ?>"
                       placeholder="Customer Name">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Personal Note <small style="color:var(--color-muted);">(optional — shown in email)</small></label>
            <textarea name="personal_note" class="form-control" rows="3"
                      placeholder="e.g. Hi John, please find the credit note for your recent return."><?= htmlspecialchars($_POST['personal_note'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Send Email</button>
            <button type="button" class="btn btn-outline"
                    onclick="document.getElementById('emailPanel').style.display='none';
                             document.querySelector('button[onclick*=emailPanel]').style.display=''">Cancel</button>
        </div>
    </form>
</div>

<div class="invoice-print-card">

    <div class="invoice-print-header">
        <div>
            <?php if ($coLogo): ?>
                <img src="<?= BASE_URL . '/' . htmlspecialchars($coLogo) ?>"
                     alt="<?= htmlspecialchars($coName) ?>"
                     style="max-height:64px;max-width:200px;object-fit:contain;display:block;margin-bottom:.4rem;">
            <?php endif; ?>
            <div class="invoice-co-name"><?= htmlspecialchars($coName) ?></div>
            <?php if ($coTagline): ?><div class="invoice-co-sub"><?= htmlspecialchars($coTagline) ?></div><?php endif; ?>
            <?php if ($coVatNo):   ?><div class="invoice-co-sub">VAT Reg. No: <?= htmlspecialchars($coVatNo) ?></div><?php endif; ?>
            <?php if ($coAddress): ?><div class="invoice-co-sub"><?= nl2br(htmlspecialchars($coAddress)) ?></div><?php endif; ?>
        </div>
        <div>
            <div class="invoice-title" style="color:#DC2626;">CREDIT NOTE</div>
            <div class="invoice-meta">
                <div><strong>Credit Note No:</strong> <?= htmlspecialchars($cn['credit_note_no']) ?></div>
                <div><strong>Date:</strong> <?= date('d F Y', strtotime($cn['created_at'])) ?></div>
                <div><strong>Against Invoice:</strong> <?= htmlspecialchars($cn['invoice_no']) ?></div>
                <div><strong>Status:</strong>
                    <span style="text-transform:capitalize;font-weight:600;color:<?= $cn['status'] === 'voided' ? '#DC2626' : ($cn['status'] === 'applied' ? '#16A34A' : '#D97706') ?>">
                        <?= htmlspecialchars($cn['status']) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="invoice-bill-section">
        <div>
            <div class="invoice-section-label">Credit To</div>
            <div class="invoice-section-value">
                <strong><?= htmlspecialchars($cn['customer_name'] ?? 'Walk-in Customer') ?></strong><br>
                <?php if ($cn['customer_email']): ?><?= htmlspecialchars($cn['customer_email']) ?><br><?php endif; ?>
                <?php if ($cn['customer_phone']): ?><?= htmlspecialchars($cn['customer_phone']) ?><br><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="invoice-section-label">Reason</div>
            <div class="invoice-section-value"><?= nl2br(htmlspecialchars($cn['reason'] ?? '')) ?></div>
        </div>
    </div>

    <table class="table" style="margin-bottom:1.5rem;width:100%;table-layout:fixed;">
        <colgroup>
            <col style="width:4%;">   <!-- # -->
            <col style="width:46%;">  <!-- Description + serial -->
            <col style="width:7%;">   <!-- Qty -->
            <col style="width:15%;">  <!-- Unit Price -->
            <col style="width:12%;">  <!-- VAT -->
            <col style="width:16%;">  <!-- Total -->
        </colgroup>
        <thead>
            <tr>
                <th style="text-align:left;padding:.5rem;background:#FEF2F2;border-bottom:2px solid #FECACA;">#</th>
                <th style="text-align:left;padding:.5rem;background:#FEF2F2;border-bottom:2px solid #FECACA;">Description</th>
                <th style="text-align:center;padding:.5rem;background:#FEF2F2;border-bottom:2px solid #FECACA;">Qty</th>
                <th style="text-align:right;padding:.5rem;background:#FEF2F2;border-bottom:2px solid #FECACA;">Unit Price (excl.)</th>
                <th style="text-align:right;padding:.5rem;background:#FEF2F2;border-bottom:2px solid #FECACA;">VAT (15%)</th>
                <th style="text-align:right;padding:.5rem;background:#FEF2F2;border-bottom:2px solid #FECACA;">Total (incl.)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cnLines as $i => $line): ?>
            <tr style="border-bottom:1px solid var(--color-border);">
                <td style="padding:.5rem;vertical-align:top;"><?= $i + 1 ?></td>
                <td style="padding:.5rem;vertical-align:top;word-break:break-word;">
                    <?= htmlspecialchars($line['description']) ?>
                    <?php if (!empty($line['serial_no'])): ?>
                        <br><small style="font-family:monospace;color:#4B5563;font-size:.8rem;">S/N: <?= htmlspecialchars($line['serial_no']) ?></small>
                    <?php endif; ?>
                </td>
                <td style="padding:.5rem;text-align:center;vertical-align:top;"><?= (int)$line['qty'] ?></td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;">R <?= number_format((float)$line['unit_price'], 2) ?></td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;">R <?= number_format((float)$line['vat_amount'], 2) ?></td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;font-weight:600;">R <?= number_format((float)$line['line_total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="invoice-totals-block">
        <table class="invoice-totals-table">
            <tbody>
                <tr><td>Subtotal (excl. VAT)</td><td>R <?= number_format((float)$cn['subtotal'], 2) ?></td></tr>
                <tr><td>VAT (15%)</td><td>R <?= number_format((float)$cn['vat_amount'], 2) ?></td></tr>
                <tr class="invoice-grand-total">
                    <td><strong>Credit Note Total (incl. VAT)</strong></td>
                    <td><strong style="color:#DC2626;">R <?= number_format((float)$cn['total'], 2) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="invoice-thank-you">
        This credit note is valid against invoice <?= htmlspecialchars($cn['invoice_no']) ?>.<br>
        Issued by: <?= htmlspecialchars($cn['created_by_name'] ?? '—') ?> &mdash; <?= htmlspecialchars($coName) ?>
    </div>

</div>

<!-- Stock return status (no-print, below the printable card) -->
<?php if (!empty($cn['return_to_stock'])): ?>
<div class="no-print" style="margin-top:1rem;">
    <div class="card">
        <div class="card-header"><h3 class="card-title">Stock Return</h3></div>
        <div class="card-body">
            <?php
            $whName = '—';
            if (!empty($cn['return_warehouse_id'])) {
                $whRow = $pdo->prepare("SELECT name FROM warehouses WHERE id = :id LIMIT 1");
                $whRow->execute([':id' => $cn['return_warehouse_id']]);
                $whName = $whRow->fetchColumn() ?: '—';
            }
            $condLabel = $cn['return_condition'] === 'damaged'
                ? '<span style="color:#DC2626;font-weight:600;">✗ Damaged / Write-off — NOT returned to stock</span>'
                : '<span style="color:#16A34A;font-weight:600;">✓ Resellable — returned to available stock</span>';
            ?>
            <div style="display:flex;gap:2rem;flex-wrap:wrap;font-size:.9rem;">
                <div><strong>Warehouse:</strong> <?= htmlspecialchars($whName) ?></div>
                <div><strong>Condition:</strong> <?= $condLabel ?></div>
            </div>
            <?php
            // Show which line items were returned
            $returnedLines = array_filter($cnLines, fn($l) => !empty($l['product_id']));
            if (!empty($returnedLines)):
            ?>
            <div style="margin-top:.75rem;">
                <strong style="font-size:.82rem;color:var(--color-muted);">Items processed:</strong>
                <ul style="margin:.35rem 0 0 1.2rem;font-size:.85rem;">
                <?php foreach ($returnedLines as $rl): ?>
                    <li><?= htmlspecialchars($rl['description']) ?> — Qty <?= (int)$rl['qty'] ?>
                        <?php if (!empty($rl['serial_no'])): ?>
                            <code style="font-size:.8rem;">(<?= htmlspecialchars($rl['serial_no']) ?>)</code>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Document History -->
<div class="no-print" style="margin-top:1.5rem;">
<div class="card">
    <div class="card-header"><h3 class="card-title">Document History</h3></div>
    <?php if (empty($_docHistory)): ?>
    <div class="card-body" style="color:var(--color-muted);font-size:.9rem;">No activity recorded yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:155px;">Date &amp; Time</th>
                    <th style="width:130px;">Action</th>
                    <th style="width:160px;">By</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($_docHistory as $_dh):
                    $_m = $_docActionMeta[$_dh['action']] ?? ['label' => ucwords(str_replace('_', ' ', $_dh['action'])), 'icon' => '•', 'color' => '#6b7280'];
                ?>
                <tr>
                    <td style="font-size:.82rem;color:var(--color-muted);white-space:nowrap;"><?= date('d M Y H:i', strtotime($_dh['created_at'])) ?></td>
                    <td><span style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600;color:<?= $_m['color'] ?>"><?= $_m['icon'] ?> <?= htmlspecialchars($_m['label']) ?></span></td>
                    <td style="font-size:.875rem;"><?= htmlspecialchars($_dh['user_name'] ?? 'System') ?></td>
                    <td style="font-size:.82rem;color:var(--color-muted);"><?= htmlspecialchars($_dh['details']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function printCreditNote() {
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=log_print' }).catch(function(){});

    var card = document.querySelector('.invoice-print-card');
    if (!card) { alert('Credit note card not found.'); return; }

    var css = [
        '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }',
        'body { font-family: "Segoe UI", Arial, sans-serif; background: #fff; color: #1a202c; padding: 24px; font-size: 14px; line-height: 1.5; }',

        /* Card */
        '.invoice-print-card { background: #fff; padding: 2rem; max-width: 780px; margin: 0 auto; }',
        '.invoice-print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; border-bottom: 2px solid #1e3a5f; padding-bottom: 1.25rem; }',
        '.invoice-co-name { font-size: 1.4rem; font-weight: 800; color: #1e3a5f; }',
        '.invoice-co-sub { font-size: .8rem; color: #6b7280; margin-top: .15rem; }',
        '.invoice-title { font-size: 1.6rem; font-weight: 900; color: #DC2626; letter-spacing: .05em; text-align: right; }',
        '.invoice-meta { font-size: .875rem; text-align: right; color: #6b7280; margin-top: .4rem; line-height: 1.8; }',

        /* Bill section */
        '.invoice-bill-section { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1.5rem; }',
        '.invoice-section-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin-bottom: .35rem; }',
        '.invoice-section-value { font-size: .9rem; color: #1a202c; line-height: 1.7; }',

        /* Table */
        'table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 1.5rem; }',
        'thead th { padding: .5rem; background: #FEF2F2; border-bottom: 2px solid #FECACA; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; text-align: left; }',
        'thead th:nth-child(3) { text-align: center; }',
        'thead th:nth-child(4), thead th:nth-child(5), thead th:nth-child(6) { text-align: right; }',
        'tbody td { padding: .5rem; border-bottom: 1px solid #e5e7eb; font-size: .875rem; vertical-align: top; word-break: break-word; text-align: left; }',
        'tbody td:nth-child(3) { text-align: center; }',
        'tbody td:nth-child(4), tbody td:nth-child(5) { text-align: right; }',
        'tbody td:nth-child(6) { text-align: right; font-weight: 600; }',
        'tbody tr:last-child td { border-bottom: none; }',
        'small { font-size: .8rem; color: #6b7280; }',

        /* Totals */
        '.invoice-totals-block { display: flex; justify-content: flex-end; margin-bottom: 1.5rem; }',
        '.invoice-totals-table { width: 360px; border-collapse: collapse; }',
        '.invoice-totals-table td { padding: .35rem .5rem; font-size: .9rem; }',
        '.invoice-totals-table td:last-child { text-align: right; font-weight: 600; }',
        '.invoice-grand-total td { border-top: 2px solid #1e3a5f; font-weight: 800; font-size: 1rem; padding-top: .5rem; }',

        /* Footer */
        '.invoice-thank-you { text-align: center; color: #6b7280; font-size: .8rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }',

        '@media print {',
        '  body { padding: 0; }',
        '  .invoice-print-card { max-width: 100%; padding: 1rem; }',
        '}'
    ].join('\n');

    var html = '<!DOCTYPE html><html><head>' +
        '<meta charset="UTF-8">' +
        '<title>Credit Note <?= addslashes(htmlspecialchars($cn['credit_note_no'])) ?></title>' +
        '<style>' + css + '</style>' +
        '</head><body>' +
        card.outerHTML +
        '</body></html>';

    var win = window.open('', '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(function() { win.print(); }, 300);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
