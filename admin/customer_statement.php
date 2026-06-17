<?php
// ============================================================
// Blackview SA Portal — Customer Account Statement
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';

requireLogin();
requireAdmin();

$pdo       = getDB();
$pageTitle = 'Customer Statement';

$settings  = getSettings($pdo);
$coName    = $settings['company_name']    ?? 'Blackview SA';
$coVatNo   = $settings['company_vat_no']  ?? '';
$coAddress = $settings['company_address'] ?? '';
$coEmail   = $settings['company_email']   ?? '';
$coPhone   = $settings['company_phone']   ?? '';
$coReg     = $settings['company_reg_no']  ?? '';
$coLogo    = $settings['logo_path']       ?? '';

// ============================================================
// POST: Send statement by email
// ============================================================
$emailSent  = false;
$emailError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_statement') {
    $emailTo   = trim($_POST['email_to']   ?? '');
    $emailNote = trim($_POST['email_note'] ?? '');
    $custId    = (int)($_POST['customer_id'] ?? 0);
    $dtFrom    = $_POST['date_from'] ?? '';
    $dtTo      = $_POST['date_to']   ?? '';
    $htmlBody  = $_POST['statement_html'] ?? '';

    if (!$emailTo || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
        $emailError = 'Please enter a valid email address.';
    } elseif (!$htmlBody) {
        $emailError = 'No statement content to send.';
    } else {
        // Wrap the statement snippet in a proper email shell
        $noteHtml = $emailNote ? '<p style="color:#374151;font-size:.9rem;line-height:1.6;margin-bottom:1.25rem;padding:1rem;background:#f9fafb;border-left:4px solid #1A4DB3;border-radius:0 6px 6px 0;">'
            . nl2br(htmlspecialchars($emailNote)) . '</p>' : '';

        $fullHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0;}
.wrap{max-width:780px;margin:30px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);}
.hdr{background:#1A4DB3;padding:24px 32px;color:#fff;}
.hdr h1{margin:0;font-size:1.3rem;letter-spacing:.06em;}
.body{padding:28px 32px;}
table{width:100%;border-collapse:collapse;font-size:.85rem;}
th{background:#f1f5f9;text-align:left;padding:.5rem .6rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;}
td{padding:.45rem .6rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.tr-invoice td{background:#fff;}
.tr-payment td{background:#f0fdf4;}
.tr-opening td,.tr-closing td{background:#fefce8;font-weight:700;}
.num{text-align:right;font-variant-numeric:tabular-nums;}
.red{color:#dc2626;}.green{color:#16a34a;}.blue{color:#1A4DB3;}
.ftr{padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:.78rem;}
</style>
</head><body>
<div class="wrap">
<div class="hdr"><h1>ACCOUNT STATEMENT — ' . htmlspecialchars($coName) . '</h1></div>
<div class="body">' . $noteHtml . $htmlBody . '</div>
<div class="ftr">' . htmlspecialchars($coName) . ' &mdash; ' . htmlspecialchars($coEmail) . ' &mdash; ' . htmlspecialchars($coPhone) . '<br>This is an automated statement. Please contact us if you have any queries.</div>
</div></body></html>';

        $custName = trim($_POST['customer_name'] ?? 'Customer');
        $subject  = "Account Statement — {$coName}" . ($dtFrom ? " ({$dtFrom} to {$dtTo})" : '');

        $result = sendDirectEmail($pdo, $emailTo, $custName, $subject, $fullHtml);
        if ($result['ok']) {
            $emailSent = true;
            logAudit($pdo, 'send_statement', 'customers', $custId,
                "Statement emailed to $emailTo for period $dtFrom – $dtTo");
        } else {
            $emailError = $result['error'] ?? 'Failed to send email.';
        }
    }
}

// ============================================================
// GET: Load statement data
// ============================================================
$customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$dateFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01', strtotime('-3 months'));
$dateTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');

$customer       = null;
$transactions   = [];   // merged timeline: invoices + payments
$openingBalance = 0.0;
$closingBalance = 0.0;
$totalDebits    = 0.0;
$totalCredits   = 0.0;

if ($customerId > 0) {
    // Load customer
    try {
        $cstmt = $pdo->prepare(
            "SELECT c.*, COALESCE(c.company_name,'') AS company_name, COALESCE(c.vat_no,'') AS vat_no,
                    COALESCE(c.contact_type,'individual') AS contact_type
             FROM customers c WHERE c.id = :id LIMIT 1"
        );
        $cstmt->execute([':id' => $customerId]);
        $customer = $cstmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cstmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
        $cstmt->execute([':id' => $customerId]);
        $customer = $cstmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $customer['company_name'] = '';
            $customer['vat_no']       = '';
            $customer['contact_type'] = 'individual';
        }
    }

    if ($customer) {
        // ---- Opening balance: outstanding on invoices before period ----
        $obStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(i.total - COALESCE(p.paid,0)), 0) AS ob
             FROM invoices i
             LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM invoice_payments GROUP BY invoice_id) p
               ON p.invoice_id = i.id
             WHERE i.customer_id = :cid
               AND i.status = 'active'
               AND DATE(COALESCE(i.invoice_date, i.created_at)) < :df"
        );
        $obStmt->execute([':cid' => $customerId, ':df' => $dateFrom]);
        $openingBalance = (float)$obStmt->fetchColumn();

        // ---- Invoices in period ----
        $invStmt = $pdo->prepare(
            "SELECT i.id, i.invoice_no,
                    DATE(COALESCE(i.invoice_date, i.created_at)) AS txn_date,
                    i.total AS amount,
                    COALESCE(p.paid, 0) AS paid_total,
                    i.total - COALESCE(p.paid, 0) AS outstanding,
                    i.status
             FROM invoices i
             LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM invoice_payments GROUP BY invoice_id) p
               ON p.invoice_id = i.id
             WHERE i.customer_id = :cid
               AND i.status IN ('active','voided')
               AND DATE(COALESCE(i.invoice_date, i.created_at)) BETWEEN :df AND :dt
             ORDER BY txn_date ASC, i.id ASC"
        );
        $invStmt->execute([':cid' => $customerId, ':df' => $dateFrom, ':dt' => $dateTo]);
        $invoicesInPeriod = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        // ---- Payments in period ----
        $payStmt = $pdo->prepare(
            "SELECT ip.id, ip.invoice_id, ip.amount, ip.payment_method, ip.reference,
                    DATE(ip.created_at) AS txn_date,
                    i.invoice_no
             FROM invoice_payments ip
             JOIN invoices i ON i.id = ip.invoice_id
             WHERE i.customer_id = :cid
               AND DATE(ip.created_at) BETWEEN :df AND :dt
             ORDER BY ip.created_at ASC, ip.id ASC"
        );
        $payStmt->execute([':cid' => $customerId, ':df' => $dateFrom, ':dt' => $dateTo]);
        $paymentsInPeriod = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        // ---- Merge into timeline ----
        foreach ($invoicesInPeriod as $inv) {
            $transactions[] = [
                'type'    => 'invoice',
                'date'    => $inv['txn_date'],
                'ref'     => $inv['invoice_no'],
                'desc'    => $inv['status'] === 'voided' ? 'Invoice (Voided)' : 'Invoice',
                'debit'   => $inv['status'] !== 'voided' ? (float)$inv['amount'] : 0.0,
                'credit'  => 0.0,
                'voided'  => $inv['status'] === 'voided',
                'id'      => $inv['id'],
            ];
        }
        foreach ($paymentsInPeriod as $pay) {
            $transactions[] = [
                'type'   => 'payment',
                'date'   => $pay['txn_date'],
                'ref'    => $pay['invoice_no'],
                'desc'   => 'Payment received' . ($pay['reference'] ? ' — ' . $pay['reference'] : '') . ' (' . strtoupper($pay['payment_method']) . ')',
                'debit'  => 0.0,
                'credit' => (float)$pay['amount'],
                'voided' => false,
                'id'     => $pay['invoice_id'],
            ];
        }

        // Sort by date then type (invoices before payments on same day)
        usort($transactions, function ($a, $b) {
            $d = strcmp($a['date'], $b['date']);
            if ($d !== 0) return $d;
            // invoices first on same day
            $ta = $a['type'] === 'invoice' ? 0 : 1;
            $tb = $b['type'] === 'invoice' ? 0 : 1;
            return $ta - $tb;
        });

        // ---- Calculate running balance ----
        $runBalance = $openingBalance;
        foreach ($transactions as &$txn) {
            if (!$txn['voided']) {
                $runBalance += $txn['debit'] - $txn['credit'];
                $totalDebits  += $txn['debit'];
                $totalCredits += $txn['credit'];
            }
            $txn['balance'] = $runBalance;
        }
        unset($txn);
        $closingBalance = $runBalance;
    }
}

// ---- Customer list for select ----
$customerList = $pdo->query("SELECT id, name, COALESCE(company_name,'') AS company_name, email FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';

// ---- Build reusable statement HTML (for screen + email) ----
function buildStatementHtml($customer, $coName, $coVatNo, $coReg, $coAddress, $coEmail, $coPhone, $coLogo,
                             $dateFrom, $dateTo, $openingBalance, $transactions, $totalDebits, $totalCredits, $closingBalance, $forEmail = false) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    ob_start();
    $custDisplay = htmlspecialchars($customer['name']);
    if (!empty($customer['company_name'])) $custDisplay .= ' — ' . htmlspecialchars($customer['company_name']);
    ?>
    <!-- Statement Header -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:1.5rem;">
        <tr>
            <td style="vertical-align:top;width:50%;">
                <?php if ($coLogo && !$forEmail): ?>
                    <img src="<?= $baseUrl . '/' . htmlspecialchars($coLogo) ?>" alt="" style="max-height:48px;max-width:180px;object-fit:contain;margin-bottom:.5rem;display:block;">
                <?php endif; ?>
                <strong style="font-size:1.05rem;"><?= htmlspecialchars($coName) ?></strong><br>
                <?php if ($coVatNo): ?><span style="color:#6b7280;font-size:.82rem;">VAT: <?= htmlspecialchars($coVatNo) ?></span><br><?php endif; ?>
                <?php if ($coReg):   ?><span style="color:#6b7280;font-size:.82rem;">Reg: <?= htmlspecialchars($coReg) ?></span><br><?php endif; ?>
                <?php if ($coAddress): ?><span style="color:#6b7280;font-size:.82rem;"><?= nl2br(htmlspecialchars($coAddress)) ?></span><br><?php endif; ?>
                <?php if ($coEmail): ?><span style="color:#6b7280;font-size:.82rem;"><?= htmlspecialchars($coEmail) ?></span><br><?php endif; ?>
                <?php if ($coPhone): ?><span style="color:#6b7280;font-size:.82rem;"><?= htmlspecialchars($coPhone) ?></span><?php endif; ?>
            </td>
            <td style="vertical-align:top;text-align:right;">
                <div style="font-size:1.8rem;font-weight:800;letter-spacing:.06em;color:#1A4DB3;margin-bottom:.35rem;">STATEMENT</div>
                <table style="border-collapse:collapse;margin-left:auto;font-size:.85rem;">
                    <tr><td style="color:#6b7280;padding:.15rem .4rem;text-align:right;">Customer:</td><td style="padding:.15rem .4rem;font-weight:600;"><?= $custDisplay ?></td></tr>
                    <?php if (!empty($customer['email'])): ?><tr><td style="color:#6b7280;padding:.15rem .4rem;text-align:right;">Email:</td><td style="padding:.15rem .4rem;"><?= htmlspecialchars($customer['email']) ?></td></tr><?php endif; ?>
                    <?php if (!empty($customer['phone'])): ?><tr><td style="color:#6b7280;padding:.15rem .4rem;text-align:right;">Phone:</td><td style="padding:.15rem .4rem;"><?= htmlspecialchars($customer['phone']) ?></td></tr><?php endif; ?>
                    <tr><td style="color:#6b7280;padding:.15rem .4rem;text-align:right;">Period:</td><td style="padding:.15rem .4rem;"><?= htmlspecialchars($dateFrom) ?> – <?= htmlspecialchars($dateTo) ?></td></tr>
                    <tr><td style="color:#6b7280;padding:.15rem .4rem;text-align:right;">Printed:</td><td style="padding:.15rem .4rem;"><?= date('d M Y') ?></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Transactions Table -->
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <thead>
            <tr style="background:#f1f5f9;">
                <th style="text-align:left;padding:.5rem .65rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;">Date</th>
                <th style="text-align:left;padding:.5rem .65rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;">Reference</th>
                <th style="text-align:left;padding:.5rem .65rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;">Description</th>
                <th style="text-align:right;padding:.5rem .65rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;">Debit (R)</th>
                <th style="text-align:right;padding:.5rem .65rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;">Credit (R)</th>
                <th style="text-align:right;padding:.5rem .65rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;">Balance (R)</th>
            </tr>
        </thead>
        <tbody>
            <!-- Opening Balance -->
            <tr style="background:#fefce8;">
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;font-weight:700;"><?= htmlspecialchars($dateFrom) ?></td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;" colspan="2"><strong>Opening Balance</strong></td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;text-align:right;"></td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;text-align:right;"></td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:700;color:<?= $openingBalance > 0 ? '#dc2626' : '#16a34a' ?>;">
                    <?= number_format($openingBalance, 2) ?>
                </td>
            </tr>

            <?php if (empty($transactions)): ?>
            <tr>
                <td colspan="6" style="padding:1.25rem .65rem;text-align:center;color:#9ca3af;font-style:italic;border-bottom:1px solid #f3f4f6;">
                    No transactions in this period.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($transactions as $txn): ?>
            <tr style="<?= $txn['voided'] ? 'opacity:.5;text-decoration:line-through;' : ($txn['type'] === 'payment' ? 'background:#f0fdf4;' : '') ?>">
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;white-space:nowrap;">
                    <?= htmlspecialchars(date('d M Y', strtotime($txn['date']))) ?>
                </td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;">
                    <?php if (!$forEmail && $txn['id'] && !$txn['voided']): ?>
                        <a href="<?= $baseUrl ?>/pos/invoice.php?id=<?= $txn['id'] ?>" target="_blank" style="color:#1A4DB3;text-decoration:none;font-weight:600;"><?= htmlspecialchars($txn['ref']) ?></a>
                    <?php else: ?>
                        <strong><?= htmlspecialchars($txn['ref']) ?></strong>
                    <?php endif; ?>
                </td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;color:#374151;"><?= htmlspecialchars($txn['desc']) ?></td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;text-align:right;font-variant-numeric:tabular-nums;color:<?= $txn['debit'] > 0 ? '#dc2626' : '#9ca3af' ?>;">
                    <?= $txn['debit'] > 0 ? number_format($txn['debit'], 2) : '' ?>
                </td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;text-align:right;font-variant-numeric:tabular-nums;color:<?= $txn['credit'] > 0 ? '#16a34a' : '#9ca3af' ?>;">
                    <?= $txn['credit'] > 0 ? number_format($txn['credit'], 2) : '' ?>
                </td>
                <td style="padding:.45rem .65rem;border-bottom:1px solid #f3f4f6;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;color:<?= $txn['balance'] > 0.005 ? '#dc2626' : '#16a34a' ?>;">
                    <?= $txn['voided'] ? '' : number_format($txn['balance'], 2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Closing Balance / Totals -->
            <tr style="background:#f1f5f9;border-top:2px solid #e5e7eb;">
                <td style="padding:.55rem .65rem;font-weight:700;" colspan="3">Period Totals</td>
                <td style="padding:.55rem .65rem;text-align:right;font-weight:700;color:#dc2626;font-variant-numeric:tabular-nums;"><?= number_format($totalDebits, 2) ?></td>
                <td style="padding:.55rem .65rem;text-align:right;font-weight:700;color:#16a34a;font-variant-numeric:tabular-nums;"><?= number_format($totalCredits, 2) ?></td>
                <td style="padding:.55rem .65rem;"></td>
            </tr>
            <tr style="background:#fefce8;">
                <td style="padding:.55rem .65rem;font-weight:800;font-size:.95rem;" colspan="5">Closing Balance (Amount Due)</td>
                <td style="padding:.55rem .65rem;text-align:right;font-weight:800;font-size:1rem;color:<?= $closingBalance > 0.005 ? '#dc2626' : '#16a34a' ?>;">
                    R <?= number_format($closingBalance, 2) ?>
                    <?php if ($closingBalance <= 0.005): ?> <span style="font-size:.75rem;color:#16a34a;">✓ Paid</span><?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

$statementHtml = '';
if ($customer) {
    $statementHtml = buildStatementHtml(
        $customer, $coName, $coVatNo, $coReg, $coAddress, $coEmail, $coPhone, $coLogo,
        $dateFrom, $dateTo, $openingBalance, $transactions, $totalDebits, $totalCredits, $closingBalance
    );
}
?>

<style>
@media print {
    .no-print { display: none !important; }
    .si-page, body { background: #fff !important; }
    .statement-card { box-shadow: none !important; border: none !important; }
}
.stmt-filter-bar { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.08); padding:1.25rem 1.5rem; margin-bottom:1.25rem; display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; }
.stmt-filter-bar .fg { display:flex; flex-direction:column; gap:.3rem; }
.stmt-filter-bar label { font-size:.78rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; }
.statement-card { background:#fff; border-radius:10px; box-shadow:0 2px 16px rgba(0,0,0,.10); padding:2rem 2.25rem; max-width:980px; margin:0 auto; }
.email-panel { background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:1.25rem 1.5rem; margin-top:1.5rem; }
.email-panel h4 { margin:0 0 .75rem; font-size:.9rem; font-weight:700; color:#0369a1; }
</style>

<div style="padding:1.5rem;background:#f3f4f6;min-height:100vh;">

    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
        <h1 style="font-size:1.35rem;font-weight:700;color:#111827;margin:0;">Customer Account Statement</h1>
        <?php if ($customer): ?>
        <div style="display:flex;gap:.6rem;">
            <button onclick="window.print()" class="btn btn-outline no-print">🖨 Print</button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($emailSent): ?>
        <div class="alert alert-success no-print" style="max-width:980px;margin:0 auto .75rem auto;">
            ✅ Statement emailed successfully to <strong><?= htmlspecialchars($_POST['email_to'] ?? '') ?></strong>.
        </div>
    <?php elseif ($emailError): ?>
        <div class="alert alert-error no-print" style="max-width:980px;margin:0 auto .75rem auto;">
            ❌ <?= htmlspecialchars($emailError) ?>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <form method="GET" action="" class="stmt-filter-bar no-print" style="max-width:980px;margin:0 auto 1.25rem auto;">
        <div class="fg" style="flex:2;min-width:200px;position:relative;">
            <label for="cust-filter-search">Customer</label>
            <input type="text" id="cust-filter-search" class="form-control"
                   placeholder="Search customer…" autocomplete="off"
                   value="<?= $customer ? htmlspecialchars($customer['name'] . ($customer['company_name'] ? ' — ' . $customer['company_name'] : '')) : '' ?>">
            <input type="hidden" name="customer_id" id="cust-filter-id" value="<?= $customerId ?: '' ?>">
            <div id="cust-filter-drop"
                 style="display:none;position:absolute;top:100%;left:0;right:0;z-index:300;
                        background:#fff;border:1px solid #d1d5db;border-radius:8px;
                        box-shadow:0 4px 16px rgba(0,0,0,.14);max-height:260px;overflow-y:auto;margin-top:2px;">
            </div>
        </div>
        <div class="fg">
            <label>From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="fg">
            <label>To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="fg">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary" <?= !$customerId ? 'disabled' : '' ?> id="generate-btn">Generate</button>
        </div>
        <!-- Quick range shortcuts -->
        <div class="fg" style="flex-basis:100%;display:flex;gap:.4rem;flex-wrap:wrap;margin-top:-.25rem;">
            <span style="font-size:.78rem;color:#9ca3af;margin-right:.25rem;align-self:center;">Quick:</span>
            <?php
            $ranges = [
                'This Month'     => [date('Y-m-01'), date('Y-m-d')],
                'Last Month'     => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                'Last 3 Months'  => [date('Y-m-d', strtotime('-3 months')), date('Y-m-d')],
                'This Year'      => [date('Y-01-01'), date('Y-m-d')],
                'All Time'       => ['2020-01-01', date('Y-m-d')],
            ];
            foreach ($ranges as $label => [$from, $to]):
            ?>
            <a href="?customer_id=<?= $customerId ?>&date_from=<?= $from ?>&date_to=<?= $to ?>"
               class="btn btn-sm btn-outline"
               style="font-size:.75rem;padding:.2rem .55rem;<?= ($dateFrom === $from && $dateTo === $to) ? 'background:#1A4DB3;color:#fff;border-color:#1A4DB3;' : '' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
    </form>

    <?php if (!$customer && !$customerId): ?>
        <div class="statement-card no-print" style="text-align:center;padding:3rem 2rem;color:#9ca3af;">
            <div style="font-size:3rem;margin-bottom:.75rem;">📋</div>
            <p style="font-size:1rem;font-weight:600;color:#6b7280;">Select a customer and date range to generate a statement.</p>
        </div>
    <?php elseif (!$customer): ?>
        <div class="alert alert-error" style="max-width:980px;margin:0 auto;">Customer not found.</div>
    <?php else: ?>

        <!-- Statement Document -->
        <div class="statement-card" id="statement-output">
            <?= $statementHtml ?>
        </div>

        <!-- Email Panel -->
        <div class="email-panel no-print" style="max-width:980px;margin:1.25rem auto 0;">
            <h4>📧 Email Statement to Customer</h4>
            <form method="POST" action="">
                <input type="hidden" name="action" value="send_statement">
                <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                <input type="hidden" name="customer_name" value="<?= htmlspecialchars($customer['name']) ?>">
                <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                <input type="hidden" name="statement_html" id="statement-html-payload"
                       value="<?= htmlspecialchars(buildStatementHtml(
                           $customer, $coName, $coVatNo, $coReg, $coAddress, $coEmail, $coPhone, $coLogo,
                           $dateFrom, $dateTo, $openingBalance, $transactions, $totalDebits, $totalCredits, $closingBalance, true
                       )) ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;align-items:end;flex-wrap:wrap;">
                    <div>
                        <label class="form-label">Send To (Email) <span class="required">*</span></label>
                        <input type="email" name="email_to" class="form-control"
                               value="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                               placeholder="customer@email.com" required>
                    </div>
                    <div>
                        <label class="form-label">Personal Note <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                        <input type="text" name="email_note" class="form-control"
                               placeholder="e.g. Please find your account statement attached…">
                    </div>
                </div>
                <div style="margin-top:.75rem;display:flex;gap:.75rem;align-items:center;">
                    <button type="submit" class="btn btn-primary">📤 Send Statement</button>
                    <span style="font-size:.8rem;color:#6b7280;">
                        <?php if (empty($settings['smtp_enabled'])): ?>
                            ⚠ Email not configured — go to <a href="<?= BASE_URL ?>/admin/settings.php">Admin → Settings</a> to set up email.
                        <?php else: ?>
                            Will send via <?= strtoupper(htmlspecialchars($settings['smtp_provider'] ?? 'SMTP')) ?>
                        <?php endif; ?>
                    </span>
                </div>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
(function () {
    var BASE_URL  = <?= json_encode(BASE_URL) ?>;
    var searchIn  = document.getElementById('cust-filter-search');
    var hiddenId  = document.getElementById('cust-filter-id');
    var dropdown  = document.getElementById('cust-filter-drop');
    var genBtn    = document.getElementById('generate-btn');
    if (!searchIn) return;

    var _timer = null;

    function selectCustomer(id, label) {
        hiddenId.value  = id;
        searchIn.value  = label;
        dropdown.style.display = 'none';
        if (genBtn) genBtn.disabled = false;
    }

    searchIn.addEventListener('input', function () {
        clearTimeout(_timer);
        hiddenId.value = '';
        if (genBtn) genBtn.disabled = true;
        var q = this.value.trim();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        _timer = setTimeout(function () {
            fetch(BASE_URL + '/invoices/ajax.php?ajax=search_customer&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    dropdown.innerHTML = '';
                    if (!results.length) {
                        dropdown.innerHTML = '<div style="padding:.6rem 1rem;color:#9ca3af;font-size:.85rem;">No customers found</div>';
                    } else {
                        results.forEach(function (c) {
                            var div = document.createElement('div');
                            div.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.9rem;';
                            var sub = [];
                            if (c.company_name) sub.push(c.company_name);
                            if (c.email)        sub.push(c.email);
                            div.innerHTML = '<strong>' + c.name.replace(/</g,'&lt;') + '</strong>'
                                + (sub.length ? '<br><span style="color:#6b7280;font-size:.8rem;">' + sub.map(function(s){return s.replace(/</g,'&lt;');}).join(' · ') + '</span>' : '');
                            div.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                var label = c.company_name ? c.name + ' — ' + c.company_name : c.name;
                                selectCustomer(c.id, label);
                            });
                            div.addEventListener('mouseover', function () { div.style.background = '#f0f9ff'; });
                            div.addEventListener('mouseout',  function () { div.style.background = ''; });
                            dropdown.appendChild(div);
                        });
                    }
                    dropdown.style.display = 'block';
                })
                .catch(function () { dropdown.style.display = 'none'; });
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!searchIn.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
