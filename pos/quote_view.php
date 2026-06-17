<?php
// ============================================================
// Blackview SA Portal — Quote View / Print
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';

requireLogin();

$pdo = getDB();

$quoteId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quoteId === 0) {
    setFlash('error', 'Invalid quote ID.');
    header('Location: ' . BASE_URL . '/pos/quotes.php');
    exit;
}

// Load quote
$qStmt = $pdo->prepare(
    "SELECT q.*, u.name AS created_by_name
     FROM quotes q
     LEFT JOIN users u ON u.id = q.created_by
     WHERE q.id = :id LIMIT 1"
);
$qStmt->execute([':id' => $quoteId]);
$quote = $qStmt->fetch();

if (!$quote) {
    setFlash('error', 'Quote not found.');
    header('Location: ' . BASE_URL . '/pos/quotes.php');
    exit;
}

$pageTitle = 'Quote ' . $quote['quote_no'];

// Load company settings
$_appSettings = getSettings($pdo);
$coName    = !empty($_appSettings['company_name'])    ? $_appSettings['company_name']    : 'Blackview SA';
$coTagline = !empty($_appSettings['company_tagline']) ? $_appSettings['company_tagline'] : 'Authorised Blackview Distributor';
$coVatNo   = $_appSettings['company_vat_no']   ?? '';
$coAddress = $_appSettings['company_address']  ?? '';
$coEmail   = $_appSettings['company_email']    ?? '';
$coPhone   = $_appSettings['company_phone']    ?? '';
$coLogo    = $_appSettings['logo_path']        ?? '';

// Load line items
$itemsStmt = $pdo->prepare(
    "SELECT qi.*, p.sku AS product_sku
     FROM quote_items qi
     LEFT JOIN products p ON p.id = qi.product_id
     WHERE qi.quote_id = :qid
     ORDER BY qi.id ASC"
);
$itemsStmt->execute([':qid' => $quoteId]);
$quoteItems = $itemsStmt->fetchAll();

$channelLabels = [
    'takealot' => 'Takealot',
    'makro'    => 'Makro',
    'instore'  => 'In-Store',
    'email'    => 'Email',
    'other'    => 'Other',
];

$statusLabels = [
    'draft'    => 'Draft',
    'sent'     => 'Sent',
    'accepted' => 'Accepted',
    'declined' => 'Declined',
    'expired'  => 'Expired',
];

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
            'customer_name' => $quote['customer_name'] ?: 'Valued Customer',
            'quote_no'      => $quote['quote_no'],
            'quote_date'    => date('d F Y', strtotime($quote['created_at'])),
            'valid_until'   => !empty($quote['valid_until']) ? date('d F Y', strtotime($quote['valid_until'])) : 'N/A',
            'total'         => number_format((float)$quote['total'], 2),
            'personal_note' => $personalNoteHtml,
            'company_name'  => $coName,
            'company_email' => $coEmail,
            'company_phone' => $coPhone,
        ];

        $emailResult = sendDocumentEmail($pdo, 'quote', $vars, $toEmail, $toName);
        if ($emailResult['ok']) {
            logAudit($pdo, 'email_quote', 'quotes', $quoteId,
                "Quote {$quote['quote_no']} emailed to $toEmail");
            setFlash('success', "Quote emailed to $toEmail successfully.");
            header('Location: ' . BASE_URL . '/pos/quote_view.php?id=' . $quoteId);
            exit;
        }
    }
}

// Handle log-print AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_print') {
    logAudit($pdo, 'print_quote', 'quotes', $quoteId,
        "Quote {$quote['quote_no']} printed / saved as PDF");
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

// Load document history from audit_log
$_docHistoryActions = ['email_quote','print_quote'];
$_dhi = implode(',', array_fill(0, count($_docHistoryActions), '?'));
$_dhStmt = $pdo->prepare(
    "SELECT al.action, al.details, al.created_at, u.name AS user_name
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.entity = 'quotes' AND al.entity_id = ? AND al.action IN ($_dhi)
     ORDER BY al.created_at ASC"
);
$_dhStmt->execute(array_merge([$quoteId], $_docHistoryActions));
$_docHistory = $_dhStmt->fetchAll();

$_docActionMeta = [
    'email_quote' => ['label' => 'Emailed', 'icon' => '✉️',  'color' => '#0369a1'],
    'print_quote' => ['label' => 'Printed', 'icon' => '🖨️', 'color' => '#374151'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Action buttons (hidden on print) -->
<div class="invoice-footer-bar" style="margin-bottom:1.5rem;">
    <button type="button" class="btn btn-primary" onclick="printQuote()">Print / Save PDF</button>
    <a href="<?= BASE_URL ?>/pos/quotes.php" class="btn btn-outline">← Back to Quotes</a>
    <?php if ($quote['status'] === 'draft'): ?>
    <a href="<?= BASE_URL ?>/pos/quotes.php?edit=<?= $quoteId ?>" class="btn btn-outline">Edit</a>
    <?php endif; ?>
    <button type="button" class="btn btn-outline" style="color:#0369a1;border-color:#0369a1;"
            onclick="document.getElementById('emailPanel').style.display='block';this.style.display='none'">
        ✉️ Email to Client
    </button>
    <form method="POST" action="<?= BASE_URL ?>/pos/quotes.php" style="display:inline;">
        <input type="hidden" name="action"   value="convert_to_invoice">
        <input type="hidden" name="quote_id" value="<?= $quoteId ?>">
        <button type="submit" class="btn btn-success"
                onclick="return confirm('Convert this quote to an invoice? You will be taken to the POS with items pre-filled.');">
            Convert to Invoice
        </button>
    </form>
</div>

<!-- Email to Client panel -->
<?php if ($emailResult && !$emailResult['ok']): ?>
<div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($emailResult['error']) ?></div>
<?php endif; ?>
<div id="emailPanel" style="display:<?= ($emailResult && !$emailResult['ok']) ? 'block' : 'none' ?>;
     background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem;">
    <strong style="display:block;margin-bottom:.75rem;color:#0369a1;">✉️ Email Quote to Client</strong>
    <form method="POST" action="">
        <input type="hidden" name="action" value="send_email">
        <div class="form-row">
            <div class="form-group form-group--half">
                <label class="form-label">Recipient Email <span class="required">*</span></label>
                <input type="email" name="email_to" class="form-control" required
                       value="<?= htmlspecialchars($_POST['email_to'] ?? $quote['customer_email'] ?? '') ?>"
                       placeholder="client@example.com">
            </div>
            <div class="form-group form-group--half">
                <label class="form-label">Recipient Name</label>
                <input type="text" name="email_to_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['email_to_name'] ?? $quote['customer_name'] ?? '') ?>"
                       placeholder="Customer Name">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Personal Note <small style="color:var(--color-muted);">(optional — shown in email)</small></label>
            <textarea name="personal_note" class="form-control" rows="3"
                      placeholder="e.g. Hi John, please find your quotation. Let us know if you have any questions!"><?= htmlspecialchars($_POST['personal_note'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Send Email</button>
            <button type="button" class="btn btn-outline"
                    onclick="document.getElementById('emailPanel').style.display='none';
                             document.querySelector('button[onclick*=emailPanel]').style.display=''">Cancel</button>
        </div>
    </form>
</div>

<!-- Printable Quote Card -->
<div class="invoice-print-card">

    <!-- Header -->
    <div class="invoice-print-header">
        <div>
            <?php if ($coLogo !== ''): ?>
                <img src="<?= BASE_URL . '/' . htmlspecialchars($coLogo) ?>"
                     alt="<?= htmlspecialchars($coName) ?>"
                     style="max-height:64px;max-width:200px;object-fit:contain;display:block;margin-bottom:.4rem;">
            <?php endif; ?>
            <div class="invoice-co-name"><?= htmlspecialchars($coName) ?></div>
            <?php if ($coTagline !== ''): ?>
                <div class="invoice-co-sub"><?= htmlspecialchars($coTagline) ?></div>
            <?php endif; ?>
            <?php if ($coVatNo !== ''): ?>
                <div class="invoice-co-sub" style="margin-top:.25rem;">VAT Reg. No: <?= htmlspecialchars($coVatNo) ?></div>
            <?php endif; ?>
            <?php if ($coAddress !== ''): ?>
                <div class="invoice-co-sub" style="margin-top:.15rem;"><?= nl2br(htmlspecialchars($coAddress)) ?></div>
            <?php endif; ?>
            <?php if ($coEmail !== ''): ?>
                <div class="invoice-co-sub"><?= htmlspecialchars($coEmail) ?></div>
            <?php endif; ?>
            <?php if ($coPhone !== ''): ?>
                <div class="invoice-co-sub"><?= htmlspecialchars($coPhone) ?></div>
            <?php endif; ?>
        </div>
        <div>
            <div class="invoice-title">QUOTATION</div>
            <div class="invoice-meta">
                <div><strong>Quote No:</strong> <?= htmlspecialchars($quote['quote_no']) ?></div>
                <div><strong>Date:</strong> <?= date('d F Y', strtotime($quote['created_at'])) ?></div>
                <?php if (!empty($quote['valid_until'])): ?>
                <div><strong>Valid Until:</strong> <?= date('d F Y', strtotime($quote['valid_until'])) ?></div>
                <?php endif; ?>
                <div><strong>Status:</strong> <?= htmlspecialchars($statusLabels[$quote['status']] ?? ucfirst($quote['status'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Quote For / Quote Details -->
    <div class="invoice-bill-section">
        <div>
            <div class="invoice-section-label">Quote For</div>
            <div class="invoice-section-value">
                <strong><?= htmlspecialchars($quote['customer_name'] ?: 'Customer') ?></strong><br>
                <?php if (!empty($quote['customer_email'])): ?>
                    <?= htmlspecialchars($quote['customer_email']) ?><br>
                <?php endif; ?>
                <?php if (!empty($quote['customer_phone'])): ?>
                    <?= htmlspecialchars($quote['customer_phone']) ?><br>
                <?php endif; ?>
                <?php if (!empty($quote['customer_address'])): ?>
                    <?= nl2br(htmlspecialchars($quote['customer_address'])) ?><br>
                <?php endif; ?>
                <?php if (!empty($quote['customer_id_number'])): ?>
                    Acc: <?= htmlspecialchars($quote['customer_id_number']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div class="invoice-section-label">Quote Details</div>
            <div class="invoice-section-value">
                <strong>Quote #:</strong> <?= htmlspecialchars($quote['quote_no']) ?><br>
                <strong>Date:</strong> <?= date('d M Y', strtotime($quote['created_at'])) ?><br>
                <?php if (!empty($quote['valid_until'])): ?>
                <strong>Valid Until:</strong> <?= date('d M Y', strtotime($quote['valid_until'])) ?><br>
                <?php endif; ?>
                <strong>Channel:</strong> <?= htmlspecialchars($channelLabels[$quote['channel']] ?? ucfirst($quote['channel'])) ?><br>
                <strong>Status:</strong> <?= htmlspecialchars($statusLabels[$quote['status']] ?? ucfirst($quote['status'])) ?><br>
                <strong>Prepared by:</strong> <?= htmlspecialchars($quote['created_by_name'] ?? '—') ?>
            </div>
        </div>
    </div>

    <!-- Line Items Table -->
    <table class="table" style="margin-bottom:1.5rem;width:100%;">
        <thead>
            <tr>
                <th style="text-align:left;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">#</th>
                <th style="text-align:left;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Description</th>
                <th style="text-align:left;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Product SKU</th>
                <th style="text-align:center;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Qty</th>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Unit Price (excl.)</th>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">VAT (15%)</th>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Total (incl.)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($quoteItems as $i => $qi): ?>
            <tr style="border-bottom:1px solid var(--color-border);">
                <td style="padding:.5rem;vertical-align:top;"><?= $i + 1 ?></td>
                <td style="padding:.5rem;vertical-align:top;font-weight:600;">
                    <?= htmlspecialchars($qi['description']) ?>
                </td>
                <td style="padding:.5rem;vertical-align:top;font-family:monospace;font-size:.875rem;">
                    <?= !empty($qi['product_sku']) ? htmlspecialchars($qi['product_sku']) : '<span style="color:var(--color-muted);">—</span>' ?>
                </td>
                <td style="padding:.5rem;text-align:center;vertical-align:top;"><?= (int)$qi['qty'] ?></td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;">
                    R <?= number_format((float)$qi['unit_price'], 2) ?>
                </td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;">
                    R <?= number_format((float)$qi['vat_amount'], 2) ?>
                </td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;font-weight:600;">
                    R <?= number_format((float)$qi['line_total'], 2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($quoteItems)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--color-muted);padding:1rem;">No items on this quote.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totals Block -->
    <div class="invoice-totals-block">
        <table class="invoice-totals-table">
            <tbody>
                <tr>
                    <td>Subtotal (excl. VAT)</td>
                    <td>R <?= number_format((float)$quote['subtotal'], 2) ?></td>
                </tr>
                <tr>
                    <td>VAT Amount (15%)</td>
                    <td>R <?= number_format((float)$quote['vat_amount'], 2) ?></td>
                </tr>
                <tr class="invoice-grand-total">
                    <td><strong>Grand Total (incl. VAT)</strong></td>
                    <td><strong>R <?= number_format((float)$quote['total'], 2) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Notes -->
    <?php if (!empty($quote['notes'])): ?>
    <div style="margin-top:1.5rem;">
        <div class="invoice-section-label">Notes</div>
        <div class="invoice-section-value" style="font-size:.875rem;"><?= nl2br(htmlspecialchars($quote['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Quote Footer -->
    <div class="invoice-thank-you" style="margin-top:2rem;border-top:1px solid var(--color-border);padding-top:1rem;">
        <?php if (!empty($quote['valid_until'])): ?>
        This quote is valid until <strong><?= date('d F Y', strtotime($quote['valid_until'])) ?></strong>.
        Prices exclude VAT unless otherwise stated.<br>
        <?php else: ?>
        Prices exclude VAT unless otherwise stated.<br>
        <?php endif; ?>
        <strong><?= htmlspecialchars($coName) ?></strong>
        <?php if ($coTagline): ?> &mdash; <?= htmlspecialchars($coTagline) ?><?php endif; ?>
        <?php if ($coEmail): ?> &middot; <?= htmlspecialchars($coEmail) ?><?php endif; ?>
        <?php if ($coPhone): ?> &middot; <?= htmlspecialchars($coPhone) ?><?php endif; ?>
    </div>

</div><!-- /.invoice-print-card -->

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
function printQuote() {
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=log_print' }).catch(function(){});

    var card = document.querySelector('.invoice-print-card');
    if (!card) { alert('Quote card not found.'); return; }

    var css = [
        '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }',
        'body { font-family: "Segoe UI", Arial, sans-serif; background: #fff; color: #1a202c; padding: 24px; font-size: 14px; line-height: 1.5; }',
        '.invoice-print-card { background: #fff; padding: 2rem; max-width: 780px; margin: 0 auto; }',
        '.invoice-print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; border-bottom: 2px solid #1e3a5f; padding-bottom: 1.25rem; }',
        '.invoice-co-name { font-size: 1.4rem; font-weight: 800; color: #1e3a5f; }',
        '.invoice-co-sub { font-size: .8rem; color: #6b7280; margin-top: .15rem; }',
        '.invoice-title { font-size: 1.6rem; font-weight: 900; color: #1e3a5f; letter-spacing: .05em; text-align: right; }',
        '.invoice-meta { font-size: .875rem; text-align: right; color: #6b7280; margin-top: .4rem; line-height: 1.8; }',
        '.invoice-bill-section { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1.5rem; }',
        '.invoice-section-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin-bottom: .35rem; }',
        '.invoice-section-value { font-size: .9rem; color: #1a202c; line-height: 1.7; }',
        'table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; table-layout: fixed; }',
        'thead th { text-align: left; padding: .5rem; background: #f8fafc; border-bottom: 2px solid #e5e7eb; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }',
        'thead th:nth-child(3) { text-align: center; }',
        'thead th:nth-child(4), thead th:nth-child(5), thead th:nth-child(6), thead th:nth-child(7) { text-align: right; }',
        'tbody td { padding: .5rem; border-bottom: 1px solid #e5e7eb; font-size: .875rem; vertical-align: top; word-break: break-word; }',
        'tbody td:nth-child(3) { text-align: center; }',
        'tbody td:nth-child(4), tbody td:nth-child(5), tbody td:nth-child(6) { text-align: right; }',
        'tbody td:nth-child(7) { text-align: right; font-weight: 600; }',
        'tbody tr:last-child td { border-bottom: none; }',
        '.invoice-totals-block { display: flex; justify-content: flex-end; margin-bottom: 1.5rem; }',
        '.invoice-totals-table { width: 360px; border-collapse: collapse; }',
        '.invoice-totals-table td { padding: .35rem .5rem; font-size: .9rem; }',
        '.invoice-totals-table td:last-child { text-align: right; font-weight: 600; }',
        '.invoice-grand-total td { border-top: 2px solid #1e3a5f; font-weight: 800; font-size: 1rem; padding-top: .5rem; }',
        '.invoice-thank-you { text-align: center; color: #6b7280; font-size: .8rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }',
        '@media print { body { padding: 0; } .invoice-print-card { max-width: 100%; padding: 1rem; } }'
    ].join('\n');

    var html = '<!DOCTYPE html><html><head>' +
        '<meta charset="UTF-8">' +
        '<title>Quote <?= addslashes(htmlspecialchars($quote['quote_no'])) ?></title>' +
        '<style>' + css + '</style>' +
        '</head><body>' + card.outerHTML + '</body></html>';

    var win = window.open('', '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(function() { win.print(); }, 300);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
