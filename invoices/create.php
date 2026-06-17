<?php
// ============================================================
// Blackview SA Portal — New Sales Invoice (Xero-style)
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/invoice_helpers.php';

requireLogin();

$pdo = getDB();

// ============================================================
// AJAX: Customer search
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_customer') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    try {
        $s = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    COALESCE(contact_type,'individual') AS contact_type,
                    COALESCE(company_name,'') AS company_name,
                    COALESCE(vat_no,'') AS vat_no
             FROM customers
             WHERE name LIKE :q OR email LIKE :q2 OR company_name LIKE :q3 OR phone LIKE :q4
             ORDER BY name ASC LIMIT 10"
        );
        $s->execute([':q'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like]);
    } catch (Throwable $e) {
        $s = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    'individual' AS contact_type, '' AS company_name, '' AS vat_no
             FROM customers WHERE name LIKE :q OR email LIKE :q2 OR phone LIKE :q3
             ORDER BY name ASC LIMIT 10"
        );
        $s->execute([':q'=>$like,':q2'=>$like,':q3'=>$like]);
    }
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ============================================================
// AJAX: Product search
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_product') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode([]); exit; }
    try {
        $exact = $pdo->prepare(
            "SELECT id, name, sku, selling_price, COALESCE(vat_rate,15) AS vat_rate,
                    COALESCE(is_serialised,1) AS is_serialised,
                    COALESCE(product_type,'physical') AS product_type
             FROM products WHERE sku = :q AND is_active = 1 LIMIT 1"
        );
        $exact->execute([':q' => $q]);
        $results = $exact->fetchAll(PDO::FETCH_ASSOC);
        if (empty($results)) {
            $like = $pdo->prepare(
                "SELECT id, name, sku, selling_price, COALESCE(vat_rate,15) AS vat_rate,
                        COALESCE(is_serialised,1) AS is_serialised,
                        COALESCE(product_type,'physical') AS product_type
                 FROM products
                 WHERE (name LIKE :q OR sku LIKE :q2) AND is_active = 1
                 ORDER BY name ASC LIMIT 10"
            );
            $like->execute([':q' => '%'.$q.'%', ':q2' => '%'.$q.'%']);
            $results = $like->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($results as &$r) {
            $r['selling_price_incl'] = round((float)$r['selling_price'] * (1 + (float)$r['vat_rate'] / 100), 2);
            $r['is_serialised']      = (int)$r['is_serialised'];
        }
        unset($r);
    } catch (Throwable $e) {
        $results = [];
    }
    echo json_encode($results);
    exit;
}

$pageTitle = 'New Sales Invoice';
$errors    = [];

// Load company settings
$_appSettings = getSettings($pdo);
$coName    = !empty($_appSettings['company_name'])    ? $_appSettings['company_name']    : 'Blackview SA';
$coVatNo   = $_appSettings['company_vat_no']   ?? '';
$coAddress = $_appSettings['company_address']  ?? '';
$coEmail   = $_appSettings['company_email']    ?? '';
$coPhone   = $_appSettings['company_phone']    ?? '';
$coLogo    = $_appSettings['logo_path']        ?? '';
$coReg     = $_appSettings['company_reg_no']   ?? '';

// Available channels
$channels = [
    'email'    => 'Email / Remote',
    'instore'  => 'In-Store',
    'takealot' => 'Takealot',
    'makro'    => 'Makro',
    'transfer' => 'Transfer',
];

// ============================================================
// POST: Create Invoice
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? 'save_draft';
    if (!in_array($action, ['save_draft', 'save_finalise'], true)) $action = 'save_draft';

    // Customer
    $customerId  = isset($_POST['customer_id']) && is_numeric($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $custName    = trim($_POST['customer_name']     ?? '');
    $custEmail   = trim($_POST['customer_email']    ?? '');
    $custPhone   = trim($_POST['customer_phone']    ?? '');
    $custAddress = trim($_POST['customer_address']  ?? '');
    $custIdNum   = trim($_POST['customer_id_number'] ?? '');
    $contactType = in_array($_POST['contact_type'] ?? '', ['individual','business']) ? $_POST['contact_type'] : 'individual';
    $companyName = trim($_POST['company_name'] ?? '');
    $vatNo       = trim($_POST['vat_no']       ?? '');

    // Invoice meta
    $channel     = array_key_exists($_POST['channel'] ?? '', $channels) ? $_POST['channel'] : 'email';
    $invoiceDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['invoice_date'] ?? '') ? $_POST['invoice_date'] : date('Y-m-d');
    $dueDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date']     ?? '') ? $_POST['due_date']     : '';
    $notes       = trim($_POST['notes']        ?? '');
    $discountPct = min(100.0, max(0.0, (float)($_POST['discount_pct'] ?? 0)));

    // Line items
    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemDescs      = $_POST['item_desc']        ?? [];
    $itemQtys       = $_POST['item_qty']         ?? [];
    $itemPrices     = $_POST['item_unit_price']  ?? [];
    $itemVatRates   = $_POST['item_vat_rate']    ?? [];

    if ($custName === '') $errors[] = 'Customer name is required.';

    // Load product metadata
    $allPids = array_filter(array_map('intval', $itemProductIds));
    $prodTypeMap = []; $prodSerialMap = []; $prodNameMap = [];
    if (!empty($allPids)) {
        $inSql = implode(',', array_fill(0, count($allPids), '?'));
        try {
            $ptQ = $pdo->prepare("SELECT id, COALESCE(product_type,'physical') AS product_type, COALESCE(is_serialised,1) AS is_serialised, name FROM products WHERE id IN ($inSql)");
            $ptQ->execute($allPids);
            foreach ($ptQ->fetchAll() as $pt) {
                $prodTypeMap[$pt['id']]   = $pt['product_type'];
                $prodSerialMap[$pt['id']] = (bool)$pt['is_serialised'];
                $prodNameMap[$pt['id']]   = $pt['name'];
            }
        } catch (Throwable $e) {}
    }

    // Build sale items (prices submitted incl. VAT → convert to excl.)
    $saleItems = [];
    foreach ($itemProductIds as $i => $pid) {
        $pid   = (int)$pid;
        $qty   = max(1, (int)($itemQtys[$i]   ?? 1));
        $priceIncl = (float)($itemPrices[$i]  ?? 0);
        $vr    = max(0.0, (float)($itemVatRates[$i] ?? 15));
        $desc  = trim($itemDescs[$i] ?? '');
        if ($pid > 0) {
            $priceExcl = round($priceIncl / (1 + $vr / 100), 4);
            $saleItems[] = [
                'product_id'   => $pid,
                'desc'         => $desc ?: ($prodNameMap[$pid] ?? ''),
                'qty'          => $qty,
                'unit_price'   => $priceExcl,
                'vat_rate'     => $vr,
                'is_service'   => ($prodTypeMap[$pid] ?? 'physical') === 'service',
            ];
        }
    }

    if (empty($saleItems)) $errors[] = 'Please add at least one line item.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // ---- Upsert customer ----
            if ($customerId > 0) {
                try {
                    $pdo->prepare("UPDATE customers SET name=:n,email=:e,phone=:ph,address=:a,id_number=:idn,contact_type=:ct,company_name=:cn,vat_no=:vn WHERE id=:id")
                        ->execute([':n'=>$custName,':e'=>$custEmail,':ph'=>$custPhone,':a'=>$custAddress,':idn'=>$custIdNum,':ct'=>$contactType,':cn'=>$companyName,':vn'=>$vatNo,':id'=>$customerId]);
                } catch (Throwable $e) {
                    $pdo->prepare("UPDATE customers SET name=:n,email=:e,phone=:ph,address=:a,id_number=:idn WHERE id=:id")
                        ->execute([':n'=>$custName,':e'=>$custEmail,':ph'=>$custPhone,':a'=>$custAddress,':idn'=>$custIdNum,':id'=>$customerId]);
                }
            } else {
                try {
                    $pdo->prepare("INSERT INTO customers (name,email,phone,address,id_number,contact_type,company_name,vat_no) VALUES (:n,:e,:ph,:a,:idn,:ct,:cn,:vn)")
                        ->execute([':n'=>$custName,':e'=>$custEmail,':ph'=>$custPhone,':a'=>$custAddress,':idn'=>$custIdNum,':ct'=>$contactType,':cn'=>$companyName,':vn'=>$vatNo]);
                } catch (Throwable $e) {
                    $pdo->prepare("INSERT INTO customers (name,email,phone,address,id_number) VALUES (:n,:e,:ph,:a,:idn)")
                        ->execute([':n'=>$custName,':e'=>$custEmail,':ph'=>$custPhone,':a'=>$custAddress,':idn'=>$custIdNum]);
                }
                $customerId = (int)$pdo->lastInsertId();
            }

            // ---- Totals ----
            $subtotal = 0;
            foreach ($saleItems as $si) {
                $subtotal += $si['unit_price'] * $si['qty'];
            }
            $subtotal       = round($subtotal, 2);
            $discountAmount = round($subtotal * $discountPct / 100, 2);
            $discountedSub  = round($subtotal - $discountAmount, 2);
            $grandTotal     = round($discountedSub * 1.15, 2);
            $vatTotal       = round($grandTotal - $discountedSub, 2);

            // ---- Invoice number ----
            $isDraft  = ($action === 'save_draft');
            $prefix   = $isDraft ? 'DRF' : 'INV';
            $mth      = date('Ym');
            $numQ     = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE :p");
            $numQ->execute([':p' => "{$prefix}-{$mth}-%"]);
            $seq      = (int)$numQ->fetchColumn() + 1;
            $invoiceNo = sprintf('%s-%s-%04d', $prefix, $mth, $seq);
            $status   = $isDraft ? 'draft' : 'active';

            // ---- Insert invoice ----
            try {
                $pdo->prepare(
                    "INSERT INTO invoices (invoice_no,customer_id,channel,payment_method,discount_pct,discount_amount,
                                          subtotal,vat_amount,total,notes,status,invoice_date,due_date,created_by,created_at)
                     VALUES (:no,:cust,:ch,'eft',:dpct,:damt,:sub,:vat,:tot,:notes,:status,:invdate,:due,:uid,NOW())"
                )->execute([
                    ':no'     => $invoiceNo, ':cust'    => $customerId,
                    ':ch'     => $channel,   ':dpct'    => $discountPct,
                    ':damt'   => $discountAmount, ':sub' => $discountedSub,
                    ':vat'    => $vatTotal,  ':tot'     => $grandTotal,
                    ':notes'  => $notes,     ':status'  => $status,
                    ':invdate'=> $invoiceDate, ':due'   => $dueDate ?: null,
                    ':uid'    => $_SESSION['user_id'] ?? null,
                ]);
            } catch (Throwable $e) {
                // Fallback without invoice_date/due_date columns (not yet migrated)
                $pdo->prepare(
                    "INSERT INTO invoices (invoice_no,customer_id,channel,payment_method,discount_pct,discount_amount,
                                          subtotal,vat_amount,total,notes,status,created_by,created_at)
                     VALUES (:no,:cust,:ch,'eft',:dpct,:damt,:sub,:vat,:tot,:notes,:status,:uid,NOW())"
                )->execute([
                    ':no'     => $invoiceNo, ':cust'  => $customerId,
                    ':ch'     => $channel,   ':dpct'  => $discountPct,
                    ':damt'   => $discountAmount, ':sub' => $discountedSub,
                    ':vat'    => $vatTotal,  ':tot'   => $grandTotal,
                    ':notes'  => $notes,     ':status'=> $status,
                    ':uid'    => $_SESSION['user_id'] ?? null,
                ]);
            }
            $invoiceId = (int)$pdo->lastInsertId();

            // ---- Insert line items ----
            $insItem = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id,product_id,serial_no,warehouse_id,qty,unit_price,vat_rate,vat_amount,line_total)
                 VALUES (:inv,:prod,'',NULL,:qty,:price,:vr,:va,:lt)"
            );
            foreach ($saleItems as $si) {
                $lineSub   = round($si['unit_price'] * $si['qty'], 2);
                $lineTotal = round($si['unit_price'] * $si['qty'] * (1 + $si['vat_rate'] / 100), 2);
                $vatAmt    = round($lineTotal - $lineSub, 2);
                $insItem->execute([
                    ':inv'   => $invoiceId, ':prod'  => $si['product_id'],
                    ':qty'   => $si['qty'],  ':price' => $si['unit_price'],
                    ':vr'    => $si['vat_rate'], ':va' => $vatAmt, ':lt' => $lineTotal,
                ]);
            }

            // ---- If finalising immediately: deduct stock ----
            if (!$isDraft) {
                finaliseDraftStock($pdo, $invoiceId, $invoiceNo, $channel);
                // Mark finalised time
                try {
                    $pdo->prepare("UPDATE invoices SET finalised_at=NOW(), finalised_by=:uid WHERE id=:id")
                        ->execute([':uid' => $_SESSION['user_id'] ?? null, ':id' => $invoiceId]);
                } catch (Throwable $e) {}
            }

            logAudit($pdo, $isDraft ? 'create_draft' : 'create_invoice', 'invoices', $invoiceId,
                "Sales invoice $invoiceNo — $custName — status: $status — total: R " . number_format($grandTotal, 2));

            $pdo->commit();
            header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to save: ' . $e->getMessage();
        }
    }

    // Re-populate from POST on error
    $postBack = $_POST;
}

// Default form values
$today   = date('Y-m-d');
$due30   = date('Y-m-d', strtotime('+30 days'));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ---- Sales Invoice Page Styles ---- */
.si-page {
    background: #f3f4f6;
    min-height: 100vh;
    padding: 1.5rem;
}
.si-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: .75rem;
}
.si-topbar h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: #111827;
    margin: 0;
}
.si-topbar .si-draft-label {
    display: inline-block;
    background: #FEF3C7;
    color: #92400E;
    font-size: .78rem;
    font-weight: 600;
    padding: .2rem .6rem;
    border-radius: 4px;
    border: 1px solid #FCD34D;
    margin-left: .5rem;
    vertical-align: middle;
}
.si-doc {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 16px rgba(0,0,0,.10);
    max-width: 960px;
    margin: 0 auto;
}
.si-doc-header {
    background: #1A4DB3;
    color: #fff;
    padding: 1.75rem 2rem;
    border-radius: 10px 10px 0 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    align-items: start;
}
.si-doc-header .si-from h2 {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0 0 .35rem 0;
}
.si-doc-header .si-from p {
    font-size: .82rem;
    opacity: .85;
    margin: .15rem 0;
    line-height: 1.4;
}
.si-meta-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .35rem .75rem;
    align-items: center;
    font-size: .875rem;
}
.si-meta-grid label { opacity: .75; white-space: nowrap; font-size: .8rem; }
.si-meta-grid input, .si-meta-grid select {
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.35);
    color: #fff;
    border-radius: 5px;
    padding: .3rem .55rem;
    font-size: .875rem;
    width: 100%;
}
.si-meta-grid input::placeholder { color: rgba(255,255,255,.6); }
.si-meta-grid select option { background: #1A4DB3; }
.si-meta-title {
    text-align: right;
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: .06em;
    opacity: .95;
    margin-bottom: .5rem;
}
.si-body {
    padding: 1.75rem 2rem;
}
.si-bill-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 1.5rem;
}
.si-bill-to h4, .si-right-col h4 {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #6b7280;
    margin: 0 0 .6rem 0;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: .35rem;
}
.cust-search-wrap { position: relative; margin-bottom: .5rem; }
.cust-drop {
    position: absolute; z-index: 200; top: 100%; left: 0; right: 0;
    background: #fff; border: 1px solid #d1d5db; border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,.15); max-height: 220px; overflow-y: auto;
    display: none;
}
.cust-drop-item {
    padding: .55rem .85rem; cursor: pointer; border-bottom: 1px solid #f3f4f6;
    font-size: .85rem; transition: background .1s;
}
.cust-drop-item:hover { background: #eff6ff; }
.si-input-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .4rem .75rem;
    margin-top: .35rem;
}
.si-input-group .full { grid-column: 1 / -1; }
/* Line items table */
.si-lines-section { margin-bottom: 1.5rem; }
.si-lines-section h4 {
    font-size: .7rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    color: #6b7280; margin: 0 0 .6rem 0; border-bottom: 1px solid #e5e7eb; padding-bottom: .35rem;
}
.si-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.si-table thead th {
    text-align: left; padding: .5rem .6rem; font-size: .72rem; font-weight: 600;
    letter-spacing: .06em; text-transform: uppercase; color: #6b7280;
    border-bottom: 2px solid #e5e7eb; background: #f9fafb;
}
.si-table thead th:last-child, .si-table td:last-child { width: 40px; }
.si-table thead th.th-right { text-align: right; }
.si-table tbody tr { border-bottom: 1px solid #f3f4f6; }
.si-table tbody tr:hover { background: #fafafa; }
.si-table td { padding: .45rem .5rem; vertical-align: middle; }
.prod-search-wrap { position: relative; min-width: 180px; }
.prod-drop {
    position: absolute; z-index: 150; top: 100%; left: 0;
    background: #fff; border: 1px solid #d1d5db; border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,.15); max-height: 220px; overflow-y: auto;
    min-width: 280px; display: none;
}
.prod-drop-item {
    padding: .5rem .75rem; cursor: pointer; border-bottom: 1px solid #f3f4f6;
    font-size: .82rem; display: flex; justify-content: space-between; align-items: center;
}
.prod-drop-item:hover { background: #eff6ff; }
.si-table input[type="number"], .si-table input[type="text"] {
    border: 1px solid #d1d5db; border-radius: 5px; padding: .3rem .5rem;
    font-size: .875rem; width: 100%; background: #fff;
}
.si-table input:focus { border-color: #1A4DB3; outline: none; box-shadow: 0 0 0 2px rgba(26,77,179,.15); }
.remove-line-btn {
    background: none; border: none; cursor: pointer; color: #9ca3af;
    font-size: 1.2rem; padding: .2rem .3rem; border-radius: 4px; transition: color .15s;
}
.remove-line-btn:hover { color: #dc2626; }
.si-add-line {
    margin-top: .6rem;
    background: none;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    padding: .55rem 1.25rem;
    font-size: .875rem;
    color: #1A4DB3;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    width: 100%;
    text-align: left;
}
.si-add-line:hover { border-color: #1A4DB3; background: #eff6ff; }
/* Totals + notes */
.si-footer-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 2rem;
    align-items: start;
    border-top: 2px solid #e5e7eb;
    padding-top: 1.25rem;
}
.si-totals-table { min-width: 280px; font-size: .9rem; }
.si-totals-table tr td { padding: .3rem .5rem; }
.si-totals-table .tot-label { color: #6b7280; }
.si-totals-table .tot-val   { text-align: right; font-variant-numeric: tabular-nums; }
.si-totals-table .tot-total td {
    font-size: 1.1rem; font-weight: 700;
    border-top: 2px solid #e5e7eb; padding-top: .6rem;
}
.si-actions-bar {
    display: flex; align-items: center; justify-content: flex-end;
    gap: .75rem; margin-top: 1.25rem; padding-top: 1.25rem;
    border-top: 1px solid #e5e7eb;
}
/* Responsive */
@media (max-width: 700px) {
    .si-doc-header, .si-bill-row, .si-footer-row { grid-template-columns: 1fr; }
    .si-meta-title { text-align: left; }
    .si-body { padding: 1rem; }
    .si-doc-header { padding: 1.25rem; }
    .si-input-group { grid-template-columns: 1fr; }
}
</style>

<div class="si-page">
    <div class="si-topbar">
        <div>
            <h1>New Sales Invoice <span class="si-draft-label">DRAFT</span></h1>
        </div>
        <div style="display:flex;gap:.6rem;align-items:center;">
            <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-outline">← POS</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error" style="max-width:960px;margin:0 auto .75rem auto;"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="POST" action="" id="si-form">
    <div class="si-doc">

        <!-- ===== DOCUMENT HEADER ===== -->
        <div class="si-doc-header">
            <!-- FROM: Company Info -->
            <div class="si-from">
                <?php if ($coLogo): ?>
                    <img src="<?= BASE_URL . '/' . htmlspecialchars($coLogo) ?>" alt="Logo"
                         style="max-height:50px;max-width:200px;object-fit:contain;margin-bottom:.75rem;filter:brightness(0) invert(1);">
                <?php endif; ?>
                <h2><?= htmlspecialchars($coName) ?></h2>
                <?php if ($coVatNo): ?><p>VAT No: <?= htmlspecialchars($coVatNo) ?></p><?php endif; ?>
                <?php if ($coReg):   ?><p>Reg: <?= htmlspecialchars($coReg) ?></p><?php endif; ?>
                <?php if ($coAddress):?><p><?= nl2br(htmlspecialchars($coAddress)) ?></p><?php endif; ?>
                <?php if ($coEmail): ?><p><?= htmlspecialchars($coEmail) ?></p><?php endif; ?>
                <?php if ($coPhone): ?><p><?= htmlspecialchars($coPhone) ?></p><?php endif; ?>
            </div>

            <!-- Invoice Meta -->
            <div>
                <div class="si-meta-title">INVOICE</div>
                <div class="si-meta-grid">
                    <label>Date</label>
                    <input type="date" name="invoice_date" id="inv-date"
                           value="<?= htmlspecialchars($postBack['invoice_date'] ?? $today) ?>" required>

                    <label>Due Date</label>
                    <input type="date" name="due_date" id="inv-due"
                           value="<?= htmlspecialchars($postBack['due_date'] ?? $due30) ?>">

                    <label>Terms</label>
                    <select id="inv-terms">
                        <option value="0">Due on receipt</option>
                        <option value="7">Net 7 days</option>
                        <option value="14">Net 14 days</option>
                        <option value="30" selected>Net 30 days</option>
                        <option value="60">Net 60 days</option>
                        <option value="custom">Custom</option>
                    </select>

                    <label>Channel</label>
                    <select name="channel" id="inv-channel">
                        <?php foreach ($channels as $code => $label): ?>
                        <option value="<?= $code ?>"
                            <?= ($postBack['channel'] ?? 'email') === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ===== BODY ===== -->
        <div class="si-body">

            <!-- BILL TO + RIGHT COL -->
            <div class="si-bill-row">

                <!-- BILL TO: Customer -->
                <div class="si-bill-to">
                    <h4>Bill To</h4>
                    <input type="hidden" name="customer_id"       id="cust-id"      value="<?= (int)($postBack['customer_id'] ?? 0) ?>">
                    <input type="hidden" name="contact_type"      id="cust-type"    value="<?= htmlspecialchars($postBack['contact_type'] ?? 'individual') ?>">
                    <input type="hidden" name="customer_id_number" id="cust-idnum"   value="<?= htmlspecialchars($postBack['customer_id_number'] ?? '') ?>">

                    <div class="cust-search-wrap">
                        <input type="text" id="cust-search-input" class="form-control"
                               placeholder="🔍 Search existing customer…" autocomplete="off"
                               style="margin-bottom:.5rem;">
                        <div class="cust-drop" id="cust-drop"></div>
                    </div>

                    <div class="si-input-group">
                        <div class="full">
                            <input type="text" name="customer_name" id="cust-name" class="form-control"
                                   placeholder="Full Name / Company *" required
                                   value="<?= htmlspecialchars($postBack['customer_name'] ?? '') ?>">
                        </div>
                        <div>
                            <input type="text" name="company_name" id="cust-company" class="form-control"
                                   placeholder="Company Name"
                                   value="<?= htmlspecialchars($postBack['company_name'] ?? '') ?>">
                        </div>
                        <div>
                            <input type="text" name="vat_no" id="cust-vat" class="form-control"
                                   placeholder="VAT Number"
                                   value="<?= htmlspecialchars($postBack['vat_no'] ?? '') ?>">
                        </div>
                        <div>
                            <input type="email" name="customer_email" id="cust-email" class="form-control"
                                   placeholder="Email"
                                   value="<?= htmlspecialchars($postBack['customer_email'] ?? '') ?>">
                        </div>
                        <div>
                            <input type="text" name="customer_phone" id="cust-phone" class="form-control"
                                   placeholder="Phone"
                                   value="<?= htmlspecialchars($postBack['customer_phone'] ?? '') ?>">
                        </div>
                        <div class="full">
                            <input type="text" name="customer_address" id="cust-address" class="form-control"
                                   placeholder="Address"
                                   value="<?= htmlspecialchars($postBack['customer_address'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Discount + Notes -->
                <div class="si-right-col">
                    <h4>Invoice Details</h4>

                    <div class="form-group">
                        <label class="form-label">Discount</label>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <input type="number" name="discount_pct" id="discount-pct"
                                   class="form-control" style="width:90px;" step="0.5" min="0" max="100"
                                   value="<?= (float)($postBack['discount_pct'] ?? 0) ?>"
                                   placeholder="0">
                            <span style="color:#6b7280;">%</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:.75rem;">
                        <label class="form-label">Notes / Terms</label>
                        <textarea name="notes" class="form-control" rows="4"
                                  placeholder="Payment terms, delivery notes…"><?= htmlspecialchars($postBack['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- LINE ITEMS -->
            <div class="si-lines-section">
                <h4>Line Items</h4>
                <table class="si-table" id="si-lines-table">
                    <thead>
                        <tr>
                            <th style="width:28px;">#</th>
                            <th style="min-width:190px;">Product</th>
                            <th style="min-width:150px;">Description</th>
                            <th style="width:70px;">Qty</th>
                            <th style="width:120px;">Unit Price (incl)</th>
                            <th style="width:55px;">VAT%</th>
                            <th class="th-right" style="width:110px;">Line Total</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="si-lines-body">
                        <!-- Rows injected by JS -->
                    </tbody>
                </table>
                <button type="button" class="si-add-line" id="si-add-line-btn">
                    + Add Line Item
                </button>
            </div>

            <!-- TOTALS + NOTES ROW -->
            <div class="si-footer-row">
                <div>
                    <!-- spacer / any extra notes shown here if needed -->
                </div>
                <div>
                    <table class="si-totals-table">
                        <tr>
                            <td class="tot-label">Subtotal (excl. VAT)</td>
                            <td class="tot-val" id="tot-subtotal">R 0.00</td>
                        </tr>
                        <tr id="tot-discount-row" style="display:none;">
                            <td class="tot-label">Discount (<span id="tot-disc-pct">0</span>%)</td>
                            <td class="tot-val" style="color:#dc2626;" id="tot-discount">−R 0.00</td>
                        </tr>
                        <tr>
                            <td class="tot-label">VAT (15%)</td>
                            <td class="tot-val" id="tot-vat">R 0.00</td>
                        </tr>
                        <tr class="tot-total">
                            <td class="tot-label" style="color:#111827;">Total (incl. VAT)</td>
                            <td class="tot-val" style="color:#1A4DB3;" id="tot-grand">R 0.00</td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="si-actions-bar">
                <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-outline">Cancel</a>
                <button type="submit" name="action" value="save_draft"
                        class="btn btn-outline" style="border-color:#d97706;color:#d97706;"
                        onclick="document.getElementById('si-form-action').value='save_draft'">
                    💾 Save as Draft
                </button>
                <button type="submit" name="action" value="save_finalise"
                        class="btn btn-primary"
                        onclick="document.getElementById('si-form-action').value='save_finalise'">
                    ✅ Save &amp; Finalise
                </button>
                <input type="hidden" name="action" id="si-form-action" value="save_draft">
            </div>

        </div><!-- /.si-body -->
    </div><!-- /.si-doc -->
    </form>
</div><!-- /.si-page -->

<?php
// Pre-populate JS array if postback (errors)
$jsPostItems = [];
if (!empty($postBack['item_product_id'])) {
    foreach ($postBack['item_product_id'] as $i => $pid) {
        if (!$pid) continue;
        $jsPostItems[] = [
            'pid'   => (int)$pid,
            'name'  => $prodNameMap[(int)$pid] ?? '',
            'qty'   => (int)($postBack['item_qty'][$i] ?? 1),
            'price' => (float)($postBack['item_unit_price'][$i] ?? 0),
            'vr'    => (float)($postBack['item_vat_rate'][$i] ?? 15),
            'desc'  => $postBack['item_desc'][$i] ?? '',
        ];
    }
}
?>

<script>
(function () {
    var BASE_URL   = <?= json_encode(BASE_URL) ?>;
    var lineCount  = 0;
    var _custTimer = null;

    // ---- Utility ----
    function fmt(n) { return 'R ' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ---- Add line ----
    function addLine(data) {
        lineCount++;
        var idx  = lineCount;
        var d    = data || {};
        var pid  = d.pid   || '';
        var name = d.name  || '';
        var qty  = d.qty   || 1;
        var price= d.price || '0.00';
        var vr   = d.vr    || 15;
        var desc = d.desc  || name;

        var tr = document.createElement('tr');
        tr.setAttribute('data-line', idx);
        tr.innerHTML =
            '<td style="color:#9ca3af;font-size:.78rem;padding-right:.4rem;">' + idx + '</td>' +
            '<td>' +
              '<div class="prod-search-wrap">' +
                '<input type="text" class="prod-search" placeholder="Search product…" autocomplete="off"' +
                '       style="width:100%;" value="' + escHtml(name) + '">' +
                '<input type="hidden" name="item_product_id[]" class="prod-id" value="' + escHtml(pid) + '">' +
                '<input type="hidden" name="item_vat_rate[]"   class="prod-vr" value="' + escHtml(vr) + '">' +
                '<div class="prod-drop"></div>' +
              '</div>' +
            '</td>' +
            '<td><input type="text"   name="item_desc[]"       class="prod-desc"  value="' + escHtml(desc)  + '" placeholder="Description" style="width:100%;"></td>' +
            '<td><input type="number" name="item_qty[]"        class="prod-qty"   value="' + qty   + '" min="1" style="width:68px;"></td>' +
            '<td><input type="number" name="item_unit_price[]" class="prod-price" value="' + parseFloat(price).toFixed(2) + '" step="0.01" min="0" style="width:108px;"></td>' +
            '<td style="text-align:center;color:#6b7280;font-size:.82rem;" class="prod-vr-disp">' + vr + '%</td>' +
            '<td style="text-align:right;font-weight:600;font-size:.9rem;white-space:nowrap;" class="prod-total">R 0.00</td>' +
            '<td><button type="button" class="remove-line-btn" title="Remove line">×</button></td>';

        var tbody = document.getElementById('si-lines-body');
        tbody.appendChild(tr);

        // Wire up this row
        wireRow(tr);
        calcLine(tr);
        calcTotals();
        return tr;
    }

    function wireRow(tr) {
        var searchIn  = tr.querySelector('.prod-search');
        var prodId    = tr.querySelector('.prod-id');
        var prodVr    = tr.querySelector('.prod-vr');
        var prodDesc  = tr.querySelector('.prod-desc');
        var prodQty   = tr.querySelector('.prod-qty');
        var prodPrice = tr.querySelector('.prod-price');
        var prodDrop  = tr.querySelector('.prod-drop');
        var removeBtn = tr.querySelector('.remove-line-btn');
        var vrDisp    = tr.querySelector('.prod-vr-disp');
        var _timer    = null;

        // Product search
        searchIn.addEventListener('input', function () {
            clearTimeout(_timer);
            var q = this.value.trim();
            if (q.length < 2) { prodDrop.style.display = 'none'; return; }
            _timer = setTimeout(function () {
                fetch(BASE_URL + '/invoices/ajax.php?ajax=search_product&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (results) {
                        prodDrop.innerHTML = '';
                        if (!results.length) { prodDrop.style.display = 'none'; return; }
                        results.forEach(function (prod) {
                            var item = document.createElement('div');
                            item.className = 'prod-drop-item';
                            var badge = prod.product_type === 'service'
                                ? ' <span style="font-size:.7rem;background:#EDE9FE;color:#5B21B6;padding:.1rem .3rem;border-radius:3px;">Service</span>'
                                : (!prod.is_serialised ? ' <span style="font-size:.7rem;background:#FEF3C7;color:#92400E;padding:.1rem .3rem;border-radius:3px;">Bulk</span>' : '');
                            item.innerHTML =
                                '<span><strong>' + escHtml(prod.name) + '</strong>' +
                                (prod.sku ? ' <span style="color:#9ca3af;font-size:.78rem;">(' + escHtml(prod.sku) + ')</span>' : '') +
                                badge + '</span>' +
                                '<span style="font-weight:600;color:#1A4DB3;">R ' + parseFloat(prod.selling_price_incl).toFixed(2) + '</span>';
                            item.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                prodId.value    = prod.id;
                                prodVr.value    = prod.vat_rate;
                                vrDisp.textContent = prod.vat_rate + '%';
                                prodPrice.value = parseFloat(prod.selling_price_incl).toFixed(2);
                                if (!prodDesc.value || prodDesc.value === searchIn.value) {
                                    prodDesc.value = prod.name;
                                }
                                searchIn.value  = prod.name;
                                prodDrop.style.display = 'none';
                                calcLine(tr);
                                calcTotals();
                                prodQty.focus();
                            });
                            prodDrop.appendChild(item);
                        });
                        prodDrop.style.display = 'block';
                    })
                    .catch(function () { prodDrop.style.display = 'none'; });
            }, 250);
        });

        searchIn.addEventListener('blur', function () {
            setTimeout(function () { prodDrop.style.display = 'none'; }, 200);
        });

        // Recalc on qty/price change
        prodQty.addEventListener('input',   function () { calcLine(tr); calcTotals(); });
        prodPrice.addEventListener('input', function () { calcLine(tr); calcTotals(); });

        // Remove row
        removeBtn.addEventListener('click', function () {
            tr.remove();
            calcTotals();
        });
    }

    function calcLine(tr) {
        var qty   = parseFloat(tr.querySelector('.prod-qty').value)   || 0;
        var price = parseFloat(tr.querySelector('.prod-price').value) || 0;
        var total = qty * price;
        tr.querySelector('.prod-total').textContent = fmt(total);
    }

    function calcTotals() {
        var discPct = parseFloat(document.getElementById('discount-pct').value) || 0;
        var totalIncl = 0;
        document.querySelectorAll('#si-lines-body tr').forEach(function (tr) {
            var qty   = parseFloat(tr.querySelector('.prod-qty').value)   || 0;
            var price = parseFloat(tr.querySelector('.prod-price').value) || 0;
            var vr    = parseFloat(tr.querySelector('.prod-vr').value)    || 15;
            // price is incl. VAT; convert to excl for subtotal
            var priceExcl = price / (1 + vr / 100);
            totalIncl += qty * price;
            _ = priceExcl; // used for subtotal calc below
        });

        // Recalculate properly: sum of excl. prices → discount → +VAT
        var subtotalExcl = 0;
        document.querySelectorAll('#si-lines-body tr').forEach(function (tr) {
            var qty   = parseFloat(tr.querySelector('.prod-qty').value)   || 0;
            var price = parseFloat(tr.querySelector('.prod-price').value) || 0;
            var vr    = parseFloat(tr.querySelector('.prod-vr').value)    || 15;
            subtotalExcl += qty * price / (1 + vr / 100);
        });

        var discAmount = subtotalExcl * discPct / 100;
        var discSub    = subtotalExcl - discAmount;
        var vatAmount  = discSub * 0.15;
        var grand      = discSub + vatAmount;

        document.getElementById('tot-subtotal').textContent = fmt(subtotalExcl);
        document.getElementById('tot-vat').textContent = fmt(vatAmount);
        document.getElementById('tot-grand').textContent = fmt(grand);

        var discRow = document.getElementById('tot-discount-row');
        if (discPct > 0) {
            discRow.style.display = '';
            document.getElementById('tot-disc-pct').textContent = discPct;
            document.getElementById('tot-discount').textContent = '−' + fmt(discAmount);
        } else {
            discRow.style.display = 'none';
        }
    }

    // ---- Payment terms → due date ----
    var termsSel = document.getElementById('inv-terms');
    var dateIn   = document.getElementById('inv-date');
    var dueIn    = document.getElementById('inv-due');

    termsSel.addEventListener('change', function () {
        var days = parseInt(this.value, 10);
        if (isNaN(days)) return; // custom
        var base = dateIn.value ? new Date(dateIn.value) : new Date();
        base.setDate(base.getDate() + days);
        dueIn.value = base.toISOString().slice(0, 10);
    });

    dateIn.addEventListener('change', function () {
        var days = parseInt(termsSel.value, 10);
        if (!isNaN(days)) termsSel.dispatchEvent(new Event('change'));
    });

    // ---- Discount change ----
    document.getElementById('discount-pct').addEventListener('input', calcTotals);

    // ---- Add Line button ----
    document.getElementById('si-add-line-btn').addEventListener('click', function () {
        var row = addLine();
        row.querySelector('.prod-search').focus();
    });

    // ---- Customer search ----
    var custSearchIn = document.getElementById('cust-search-input');
    var custDrop     = document.getElementById('cust-drop');

    custSearchIn.addEventListener('input', function () {
        clearTimeout(_custTimer);
        var q = this.value.trim();
        if (q.length < 2) { custDrop.style.display = 'none'; return; }
        _custTimer = setTimeout(function () {
            fetch(BASE_URL + '/invoices/ajax.php?ajax=search_customer&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    custDrop.innerHTML = '';
                    if (!results.length) {
                        var noItem = document.createElement('div');
                        noItem.className = 'cust-drop-item';
                        noItem.style.color = '#9ca3af';
                        noItem.textContent = 'No customers found for "' + q + '"';
                        custDrop.appendChild(noItem);
                    } else {
                        results.forEach(function (c) {
                            var item = document.createElement('div');
                            item.className = 'cust-drop-item';
                            var display = c.company_name ? escHtml(c.name) + ' — ' + escHtml(c.company_name) : escHtml(c.name);
                            item.innerHTML = '<strong>' + display + '</strong>' +
                                (c.email ? '<br><span style="color:#6b7280;font-size:.78rem;">' + escHtml(c.email) + '</span>' : '');
                            item.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                fillCustomer(c);
                                custDrop.style.display = 'none';
                                custSearchIn.value = '';
                            });
                            custDrop.appendChild(item);
                        });
                    }
                    // Add new customer option always at bottom
                    var addItem = document.createElement('div');
                    addItem.className = 'cust-drop-item';
                    addItem.style.cssText = 'color:#1A4DB3;font-weight:600;background:#EFF6FF;border-radius:0 0 6px 6px;';
                    addItem.innerHTML = '➕ Add <em>"' + escHtml(q) + '"</em> as new customer';
                    addItem.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        document.getElementById('cust-name').value = q;
                        document.getElementById('cust-id').value   = '';
                        custSearchIn.value = '';
                        custDrop.style.display = 'none';
                        document.getElementById('cust-email').focus();
                    });
                    custDrop.appendChild(addItem);
                    custDrop.style.display = 'block';
                })
                .catch(function () { custDrop.style.display = 'none'; });
        }, 250);
    });

    custSearchIn.addEventListener('blur', function () {
        setTimeout(function () { custDrop.style.display = 'none'; }, 200);
    });

    function fillCustomer(c) {
        document.getElementById('cust-id').value       = c.id || '';
        document.getElementById('cust-name').value     = c.name || '';
        document.getElementById('cust-email').value    = c.email || '';
        document.getElementById('cust-phone').value    = c.phone || '';
        document.getElementById('cust-address').value  = c.address || '';
        document.getElementById('cust-idnum').value    = c.id_number || '';
        document.getElementById('cust-company').value  = c.company_name || '';
        document.getElementById('cust-vat').value      = c.vat_no || '';
        document.getElementById('cust-type').value     = c.contact_type || 'individual';
    }

    // ---- Init: add first blank line (or restore postback) ----
    var postItems = <?= json_encode($jsPostItems) ?>;
    if (postItems.length > 0) {
        postItems.forEach(function (item) { addLine(item); });
    } else {
        addLine();
    }

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
