<?php
// ============================================================
// Blackview SA Portal — Admin: Email Templates
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

requireLogin();
requireAdmin();

$pdo       = getDB();
$pageTitle = 'Email Templates';

// ---- Available variables per template ----------------------
$templateVars = [
    'invoice' => [
        '{{customer_name}}'   => 'Customer full name',
        '{{invoice_no}}'      => 'Invoice number (e.g. INV-0001)',
        '{{invoice_date}}'    => 'Invoice date',
        '{{total}}'           => 'Grand total incl. VAT',
        '{{balance}}'         => 'Amount still outstanding',
        '{{balance_color}}'   => 'CSS color — green if paid, amber if due',
        '{{payment_method}}'  => 'Payment method used',
        '{{personal_note}}'   => 'Optional message typed at send time (HTML <p>)',
        '{{company_name}}'    => 'Your company name',
        '{{company_email}}'   => 'Your company email',
        '{{company_phone}}'   => 'Your company phone',
    ],
    'quote' => [
        '{{customer_name}}'  => 'Customer full name',
        '{{quote_no}}'       => 'Quote number (e.g. QUO-0001)',
        '{{quote_date}}'     => 'Quote date',
        '{{valid_until}}'    => 'Expiry date',
        '{{total}}'          => 'Grand total incl. VAT',
        '{{personal_note}}'  => 'Optional message typed at send time (HTML <p>)',
        '{{company_name}}'   => 'Your company name',
        '{{company_email}}'  => 'Your company email',
        '{{company_phone}}'  => 'Your company phone',
    ],
    'credit_note' => [
        '{{customer_name}}'   => 'Customer full name',
        '{{credit_note_no}}'  => 'Credit note number',
        '{{invoice_no}}'      => 'Original invoice number',
        '{{date}}'            => 'Credit note date',
        '{{total}}'           => 'Credit amount',
        '{{reason}}'          => 'Reason for the credit note',
        '{{personal_note}}'   => 'Optional message typed at send time (HTML <p>)',
        '{{company_name}}'    => 'Your company name',
        '{{company_email}}'   => 'Your company email',
        '{{company_phone}}'   => 'Your company phone',
    ],
];

// ---- Load all templates from DB ----------------------------
$templates = $pdo->query(
    "SELECT * FROM email_templates ORDER BY FIELD(template_key,'invoice','quote','credit_note')"
)->fetchAll();
$templateMap = [];
foreach ($templates as $t) {
    $templateMap[$t['template_key']] = $t;
}

// ---- Handle which tab is active ----------------------------
$activeKey = trim($_GET['tpl'] ?? 'invoice');
if (!array_key_exists($activeKey, $templateVars)) $activeKey = 'invoice';
$activeTpl = $templateMap[$activeKey] ?? null;

// ---- Handle save POST --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    $saveKey     = trim($_POST['template_key'] ?? '');
    $newSubject  = trim($_POST['subject']      ?? '');
    $newBody     = trim($_POST['body_html']    ?? '');

    if (!array_key_exists($saveKey, $templateVars)) {
        setFlash('error', 'Invalid template key.');
    } elseif ($newSubject === '' || $newBody === '') {
        setFlash('error', 'Subject and body cannot be empty.');
    } else {
        $stmt = $pdo->prepare(
            "UPDATE email_templates
             SET subject = :subj, body_html = :body, updated_at = NOW(), updated_by = :uid
             WHERE template_key = :key"
        );
        $stmt->execute([
            ':subj' => $newSubject,
            ':body' => $newBody,
            ':uid'  => $_SESSION['user_id'],
            ':key'  => $saveKey,
        ]);
        logAudit($pdo, 'update_email_template', 'email_templates', 0,
            "Updated email template: $saveKey");
        setFlash('success', 'Template saved.');
        header('Location: ' . BASE_URL . '/admin/email_templates.php?tpl=' . urlencode($saveKey));
        exit;
    }
}

// ---- Handle reset to default POST --------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_template') {
    $saveKey = trim($_POST['template_key'] ?? '');
    if (!array_key_exists($saveKey, $templateVars)) {
        setFlash('error', 'Invalid template key.');
    } else {
        // We'll re-run the INSERT from the migration SQL defaults by re-inserting with ON DUPLICATE KEY UPDATE
        // Instead, just redirect with a flag and show the default values inline
        setFlash('info', 'To reset, paste the default content from migration_015.sql.');
        header('Location: ' . BASE_URL . '/admin/email_templates.php?tpl=' . urlencode($saveKey));
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Email Templates</h2>
    <p class="page-subtitle">Customise the HTML emails sent to clients for invoices, quotes and credit notes.</p>
</div>

<?php if ($activeTpl === null): ?>
<div class="alert alert-error">
    Template not found in database. Please run <strong>migration_015.sql</strong> in phpMyAdmin first.
</div>
<?php else: ?>

<!-- Tab bar -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:2px solid var(--color-border);padding-bottom:0;">
    <?php
    $tabLabels = [
        'invoice'     => '📄 Invoice',
        'quote'       => '📋 Quotation',
        'credit_note' => '🔴 Credit Note',
    ];
    foreach ($tabLabels as $key => $label):
        $isActive = ($key === $activeKey);
    ?>
    <a href="?tpl=<?= $key ?>"
       style="padding:.5rem 1.25rem;border-radius:6px 6px 0 0;text-decoration:none;font-weight:600;font-size:.9rem;
              background:<?= $isActive ? '#1e40af' : '#f1f5f9' ?>;
              color:<?= $isActive ? '#fff' : 'var(--color-text)' ?>;
              border:1px solid <?= $isActive ? '#1e40af' : 'var(--color-border)' ?>;
              border-bottom:none;
              margin-bottom:-2px;">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:flex-start;">

    <!-- Edit Form -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <?= $tabLabels[$activeKey] ?> Template
                <?php if ($activeTpl['updated_at']): ?>
                <small style="font-weight:400;color:var(--color-muted);font-size:.78rem;">
                    — Last saved <?= date('d M Y H:i', strtotime($activeTpl['updated_at'])) ?>
                </small>
                <?php endif; ?>
            </h3>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action"       value="save_template">
                <input type="hidden" name="template_key" value="<?= htmlspecialchars($activeKey) ?>">

                <div class="form-group">
                    <label class="form-label">Email Subject <span class="required">*</span></label>
                    <input type="text" name="subject" class="form-control"
                           value="<?= htmlspecialchars($activeTpl['subject']) ?>" required>
                    <small class="form-hint">You can use <code>{{variable}}</code> placeholders — see the reference on the right.</small>
                </div>

                <div class="form-group" style="margin-top:1rem;">
                    <label class="form-label">Email Body (HTML) <span class="required">*</span></label>
                    <textarea name="body_html" class="form-control"
                              rows="20"
                              style="font-family:monospace;font-size:.78rem;resize:vertical;"
                              required><?= htmlspecialchars($activeTpl['body_html']) ?></textarea>
                    <small class="form-hint">Full HTML email. Use inline CSS for best mail client compatibility.</small>
                </div>

                <div class="form-actions" style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary">💾 Save Template</button>
                    <button type="button" class="btn btn-outline"
                            onclick="previewEmail()">👁 Preview</button>
                    <a href="?tpl=<?= $activeKey ?>" class="btn btn-outline">↺ Discard changes</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Variables Reference -->
    <div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Available Variables</h3></div>
            <div class="card-body" style="padding:.75rem 1rem;">
                <p style="font-size:.8rem;color:var(--color-muted);margin-bottom:.75rem;">
                    Click a variable to insert it at the cursor position in the body field.
                </p>
                <table style="width:100%;font-size:.8rem;border-collapse:collapse;">
                    <?php foreach ($templateVars[$activeKey] as $var => $desc): ?>
                    <tr style="border-bottom:1px solid var(--color-border);">
                        <td style="padding:.35rem .25rem;vertical-align:top;">
                            <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;cursor:pointer;white-space:nowrap;display:inline-block;"
                                  onclick="insertVar(<?= json_encode($var) ?>)"
                                  title="Click to insert">
                                <?= htmlspecialchars($var) ?>
                            </code>
                        </td>
                        <td style="padding:.35rem .5rem;color:var(--color-muted);vertical-align:top;">
                            <?= htmlspecialchars($desc) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <!-- Tips -->
        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h3 class="card-title">Tips</h3></div>
            <div class="card-body" style="font-size:.82rem;color:var(--color-muted);line-height:1.6;">
                <p>• Use <strong>inline CSS</strong> — many email clients strip <code>&lt;style&gt;</code> blocks.</p>
                <p style="margin-top:.5rem;">• The <code>{{personal_note}}</code> variable is replaced with a styled <code>&lt;p&gt;</code> tag containing the message typed by staff at send time, or blank if none.</p>
                <p style="margin-top:.5rem;">• Test your template using the <strong>Preview</strong> button to see it in your browser.</p>
                <p style="margin-top:.5rem;">• The From address is set in <a href="<?= BASE_URL ?>/admin/settings.php">Admin → Settings → SMTP</a>.</p>
            </div>
        </div>
    </div>

</div><!-- /grid -->

<?php endif; ?>

<script>
function insertVar(varName) {
    var ta = document.querySelector('textarea[name="body_html"]');
    if (!ta) return;
    var start = ta.selectionStart;
    var end   = ta.selectionEnd;
    var val   = ta.value;
    ta.value = val.substring(0, start) + varName + val.substring(end);
    ta.selectionStart = ta.selectionEnd = start + varName.length;
    ta.focus();
}

function previewEmail() {
    var body = document.querySelector('textarea[name="body_html"]').value;
    // Replace all {{...}} with sample text for preview
    body = body.replace(/\{\{personal_note\}\}/g, '<p style="background:#fffbeb;border-left:4px solid #d97706;padding:10px 14px;font-style:italic;">Sample personal note from staff.</p>');
    body = body.replace(/\{\{[\w_]+\}\}/g, '<span style="background:#fef3c7;padding:0 3px;border-radius:2px;font-size:.85em;">[sample]</span>');
    var win = window.open('', '_blank', 'width=700,height=700,scrollbars=yes');
    win.document.open();
    win.document.write('<!DOCTYPE html><html><body>' + body + '</body></html>');
    win.document.close();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
