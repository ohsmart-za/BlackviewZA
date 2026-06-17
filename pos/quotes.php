<?php
// ============================================================
// Blackview SA Portal — Quick Quotes
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Quotes';

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

$statusColors = [
    'draft'    => '#6B7280',
    'sent'     => '#2563EB',
    'accepted' => '#16A34A',
    'declined' => '#DC2626',
    'expired'  => '#EA580C',
];

$quoteErrors = [];

// ============================================================
// POST: Delete Quote
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_quote') {
    $delId = (int)($_POST['quote_id'] ?? 0);
    if ($delId > 0) {
        $chk = $pdo->prepare("SELECT id, status FROM quotes WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $delId]);
        $row = $chk->fetch();
        if ($row && $row['status'] === 'draft') {
            $pdo->prepare("DELETE FROM quotes WHERE id = :id")->execute([':id' => $delId]);
            logAudit($pdo, 'quote_delete', 'quotes', $delId, "Deleted quote ID $delId");
            setFlash('success', 'Quote deleted.');
        } else {
            setFlash('error', 'Only draft quotes can be deleted.');
        }
    }
    header('Location: ' . BASE_URL . '/pos/quotes.php');
    exit;
}

// ============================================================
// POST: Convert Quote to Invoice
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_to_invoice') {
    $convertId = (int)($_POST['quote_id'] ?? 0);
    if ($convertId > 0) {
        $chk = $pdo->prepare("SELECT id FROM quotes WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $convertId]);
        if ($chk->fetch()) {
            $_SESSION['prefill_quote_id'] = $convertId;
            header('Location: ' . BASE_URL . '/pos/index.php');
            exit;
        }
    }
    setFlash('error', 'Quote not found.');
    header('Location: ' . BASE_URL . '/pos/quotes.php');
    exit;
}

// ============================================================
// POST: Save (create) or Update Quote
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_quote', 'update_quote'], true)) {

    $isUpdate  = ($_POST['action'] === 'update_quote');
    $editId    = $isUpdate ? (int)($_POST['quote_id'] ?? 0) : 0;

    $custName    = trim($_POST['customer_name']      ?? '');
    $custEmail   = trim($_POST['customer_email']     ?? '');
    $custPhone   = trim($_POST['customer_phone']     ?? '');
    $custAddress = trim($_POST['customer_address']   ?? '');
    $custIdNum   = trim($_POST['customer_id_number'] ?? '');
    $channel     = trim($_POST['channel']            ?? 'instore');
    $validUntil  = trim($_POST['valid_until']        ?? '');
    $notes       = trim($_POST['notes']              ?? '');
    $status      = trim($_POST['status']             ?? 'draft');

    $custType    = trim($_POST['contact_type']  ?? 'individual');
    $custCompany = trim($_POST['company_name']  ?? '');
    $custVatNo   = trim($_POST['vat_no']        ?? '');
    if (!in_array($custType, ['individual', 'business'], true)) $custType = 'individual';

    $validChannels = ['takealot', 'makro', 'instore', 'email', 'other'];
    $validStatuses = ['draft', 'sent', 'accepted', 'declined', 'expired'];
    if (!in_array($channel, $validChannels, true)) $channel = 'instore';
    if (!in_array($status,  $validStatuses,  true)) $status  = 'draft';

    if ($custName === '') $quoteErrors[] = 'Customer name is required.';
    if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
        $quoteErrors[] = 'Valid Until must be a valid date.';
        $validUntil    = '';
    }

    // Parse line items
    $itemDescs   = $_POST['item_description'] ?? [];
    $itemPids    = $_POST['item_product_id']  ?? [];
    $itemQtys    = $_POST['item_qty']         ?? [];
    $itemPrices  = $_POST['item_unit_price']  ?? [];

    $quoteItems = [];
    foreach ($itemDescs as $i => $desc) {
        $desc  = trim($desc);
        $pid   = isset($itemPids[$i]) && is_numeric($itemPids[$i]) ? (int)$itemPids[$i] : null;
        $qty   = max(1, (int)($itemQtys[$i] ?? 1));
        $price = max(0.0, (float)($itemPrices[$i] ?? 0));
        if ($desc !== '' || $pid !== null) {
            $quoteItems[] = [
                'product_id'  => $pid,
                'description' => $desc !== '' ? $desc : 'Item',
                'qty'         => $qty,
                'unit_price'  => $price,
            ];
        }
    }

    if (empty($quoteItems)) $quoteErrors[] = 'Please add at least one line item.';

    if (empty($quoteErrors)) {
        try {
            $pdo->beginTransaction();

            $vatRate   = 15.0;
            $subtotal  = 0;
            $vatTotal  = 0;
            foreach ($quoteItems as &$qi) {
                $lineSub      = $qi['unit_price'] * $qi['qty'];
                $vatAmt       = round($lineSub * ($vatRate / 100), 2);
                $lineTotal    = round($lineSub + $vatAmt, 2);
                $qi['vat_rate']   = $vatRate;
                $qi['vat_amount'] = $vatAmt;
                $qi['line_total'] = $lineTotal;
                $subtotal        += $lineSub;
                $vatTotal        += $vatAmt;
            }
            unset($qi);
            $grandTotal = round($subtotal + $vatTotal, 2);

            if ($isUpdate && $editId > 0) {
                // Update existing quote header
                try {
                    $pdo->prepare(
                        "UPDATE quotes SET
                            customer_name      = :cn,
                            customer_email     = :ce,
                            customer_phone     = :cp,
                            customer_address   = :ca,
                            customer_id_number = :ci,
                            channel            = :ch,
                            subtotal           = :sub,
                            vat_amount         = :vat,
                            total              = :tot,
                            status             = :st,
                            valid_until        = :vu,
                            notes              = :notes,
                            customer_type      = :ctype,
                            customer_company   = :cco,
                            customer_vat_no    = :cvat
                         WHERE id = :id"
                    )->execute([
                        ':cn'    => $custName,
                        ':ce'    => $custEmail,
                        ':cp'    => $custPhone,
                        ':ca'    => $custAddress,
                        ':ci'    => $custIdNum,
                        ':ch'    => $channel,
                        ':sub'   => $subtotal,
                        ':vat'   => $vatTotal,
                        ':tot'   => $grandTotal,
                        ':st'    => $status,
                        ':vu'    => $validUntil ?: null,
                        ':notes' => $notes,
                        ':ctype' => $custType,
                        ':cco'   => $custCompany ?: null,
                        ':cvat'  => $custVatNo   ?: null,
                        ':id'    => $editId,
                    ]);
                } catch (Throwable $colErr) {
                    // fallback: migration_019 not yet run on this server
                    $pdo->prepare(
                        "UPDATE quotes SET
                            customer_name      = :cn,
                            customer_email     = :ce,
                            customer_phone     = :cp,
                            customer_address   = :ca,
                            customer_id_number = :ci,
                            channel            = :ch,
                            subtotal           = :sub,
                            vat_amount         = :vat,
                            total              = :tot,
                            status             = :st,
                            valid_until        = :vu,
                            notes              = :notes
                         WHERE id = :id"
                    )->execute([
                        ':cn'    => $custName,
                        ':ce'    => $custEmail,
                        ':cp'    => $custPhone,
                        ':ca'    => $custAddress,
                        ':ci'    => $custIdNum,
                        ':ch'    => $channel,
                        ':sub'   => $subtotal,
                        ':vat'   => $vatTotal,
                        ':tot'   => $grandTotal,
                        ':st'    => $status,
                        ':vu'    => $validUntil ?: null,
                        ':notes' => $notes,
                        ':id'    => $editId,
                    ]);
                }

                // Replace line items
                $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :qid")->execute([':qid' => $editId]);
                $quoteId = $editId;

                logAudit($pdo, 'quote_update', 'quotes', $quoteId, "Updated quote ID $quoteId");

            } else {
                // Generate quote number: QUO-YYYYMM-####
                $monthPrefix = date('Ym');
                $qCountStmt  = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE quote_no LIKE :p");
                $qCountStmt->execute([':p' => "QUO-{$monthPrefix}-%"]);
                $qSeq        = (int)$qCountStmt->fetchColumn() + 1;
                $quoteNo     = sprintf('QUO-%s-%04d', $monthPrefix, $qSeq);

                try {
                    $pdo->prepare(
                        "INSERT INTO quotes (quote_no, customer_name, customer_email, customer_phone,
                                            customer_address, customer_id_number, channel, subtotal,
                                            vat_amount, total, status, valid_until, notes,
                                            customer_type, customer_company, customer_vat_no,
                                            created_by, created_at)
                         VALUES (:qno, :cn, :ce, :cp, :ca, :ci, :ch, :sub, :vat, :tot, :st, :vu, :notes,
                                 :ctype, :cco, :cvat, :uid, NOW())"
                    )->execute([
                        ':qno'   => $quoteNo,
                        ':cn'    => $custName,
                        ':ce'    => $custEmail,
                        ':cp'    => $custPhone,
                        ':ca'    => $custAddress,
                        ':ci'    => $custIdNum,
                        ':ch'    => $channel,
                        ':sub'   => $subtotal,
                        ':vat'   => $vatTotal,
                        ':tot'   => $grandTotal,
                        ':st'    => $status,
                        ':vu'    => $validUntil ?: null,
                        ':notes' => $notes,
                        ':ctype' => $custType,
                        ':cco'   => $custCompany ?: null,
                        ':cvat'  => $custVatNo   ?: null,
                        ':uid'   => $_SESSION['user_id'] ?? null,
                    ]);
                } catch (Throwable $colErr) {
                    // fallback: migration_019 not yet run on this server
                    $pdo->prepare(
                        "INSERT INTO quotes (quote_no, customer_name, customer_email, customer_phone,
                                            customer_address, customer_id_number, channel, subtotal,
                                            vat_amount, total, status, valid_until, notes, created_by, created_at)
                         VALUES (:qno, :cn, :ce, :cp, :ca, :ci, :ch, :sub, :vat, :tot, :st, :vu, :notes, :uid, NOW())"
                    )->execute([
                        ':qno'   => $quoteNo,
                        ':cn'    => $custName,
                        ':ce'    => $custEmail,
                        ':cp'    => $custPhone,
                        ':ca'    => $custAddress,
                        ':ci'    => $custIdNum,
                        ':ch'    => $channel,
                        ':sub'   => $subtotal,
                        ':vat'   => $vatTotal,
                        ':tot'   => $grandTotal,
                        ':st'    => $status,
                        ':vu'    => $validUntil ?: null,
                        ':notes' => $notes,
                        ':uid'   => $_SESSION['user_id'] ?? null,
                    ]);
                }
                $quoteId = (int)$pdo->lastInsertId();

                logAudit($pdo, 'quote_create', 'quotes', $quoteId, "Created quote $quoteNo");
            }

            // Insert line items
            $insQI = $pdo->prepare(
                "INSERT INTO quote_items (quote_id, product_id, description, qty, unit_price, vat_rate, vat_amount, line_total)
                 VALUES (:qid, :pid, :desc, :qty, :price, :vr, :va, :lt)"
            );
            foreach ($quoteItems as $qi) {
                $insQI->execute([
                    ':qid'   => $quoteId,
                    ':pid'   => $qi['product_id'],
                    ':desc'  => $qi['description'],
                    ':qty'   => $qi['qty'],
                    ':price' => $qi['unit_price'],
                    ':vr'    => $qi['vat_rate'],
                    ':va'    => $qi['vat_amount'],
                    ':lt'    => $qi['line_total'],
                ]);
            }

            $pdo->commit();

            setFlash('success', $isUpdate ? 'Quote updated successfully.' : 'Quote created successfully.');
            header('Location: ' . BASE_URL . '/pos/quote_view.php?id=' . $quoteId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $quoteErrors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// ============================================================
// Determine view mode
// ============================================================
$isNew  = isset($_GET['new']);
$editId = isset($_GET['edit']) && is_numeric($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isEdit = ($editId > 0);
$showForm = $isNew || $isEdit || !empty($quoteErrors);

// Load quote for editing
$editQuote      = null;
$editQuoteItems = [];
if ($isEdit) {
    $eqStmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id LIMIT 1");
    $eqStmt->execute([':id' => $editId]);
    $editQuote = $eqStmt->fetch();
    if (!$editQuote) {
        setFlash('error', 'Quote not found.');
        header('Location: ' . BASE_URL . '/pos/quotes.php');
        exit;
    }
    $eqiStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = :qid ORDER BY id ASC");
    $eqiStmt->execute([':qid' => $editId]);
    $editQuoteItems = $eqiStmt->fetchAll();
}

// ============================================================
// LIST VIEW: load quotes with filters
// ============================================================
$statusFilter  = $_GET['status']    ?? 'all';
$listDateFrom  = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$listDateTo    = $_GET['date_to']   ?? date('Y-m-d');
$listSearch    = trim($_GET['search'] ?? '');

$validStatuses  = ['all', 'draft', 'sent', 'accepted', 'declined', 'expired'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDateFrom)) $listDateFrom = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDateTo))   $listDateTo   = date('Y-m-d');

$listWhere  = ['q.created_at >= :df', 'q.created_at <= :dt'];
$listParams = [
    ':df' => $listDateFrom . ' 00:00:00',
    ':dt' => $listDateTo   . ' 23:59:59',
];
if ($statusFilter !== 'all') {
    $listWhere[]     = 'q.status = :st';
    $listParams[':st'] = $statusFilter;
}
if ($listSearch !== '') {
    $listWhere[]         = '(q.customer_name LIKE :srch OR q.quote_no LIKE :srch2)';
    $listParams[':srch']  = '%' . $listSearch . '%';
    $listParams[':srch2'] = '%' . $listSearch . '%';
}
$listWhereSQL = 'WHERE ' . implode(' AND ', $listWhere);

$quotesStmt = $pdo->prepare(
    "SELECT q.*, u.name AS created_by_name
     FROM quotes q
     LEFT JOIN users u ON u.id = q.created_by
     $listWhereSQL
     ORDER BY q.created_at DESC
     LIMIT 100"
);
$quotesStmt->execute($listParams);
$quotesList = $quotesStmt->fetchAll();

// ============================================================
// Quick Pick products (for create/edit form)
// ============================================================
$products  = $pdo->query("SELECT id, sku, name, selling_price FROM products WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$quickPick = $pdo->query(
    "SELECT id, sku, name, selling_price, image_path
     FROM products WHERE is_active = 1 AND is_featured = 1 ORDER BY name ASC LIMIT 6"
)->fetchAll();

if (count($quickPick) < 6) {
    $needed      = 6 - count($quickPick);
    $featuredIds = array_column($quickPick, 'id');
    if (!empty($featuredIds)) {
        $notInSql = implode(',', array_fill(0, count($featuredIds), '?'));
        $fillStmt = $pdo->prepare(
            "SELECT id, sku, name, selling_price, image_path
             FROM products WHERE is_active = 1 AND is_featured = 0 AND id NOT IN ($notInSql)
             ORDER BY name ASC LIMIT $needed"
        );
        $fillStmt->execute($featuredIds);
    } else {
        $fillStmt = $pdo->prepare(
            "SELECT id, sku, name, selling_price, image_path
             FROM products WHERE is_active = 1 ORDER BY name ASC LIMIT $needed"
        );
        $fillStmt->execute();
    }
    $quickPick = array_merge($quickPick, $fillStmt->fetchAll());
}

// Default valid_until = today + 14 days
$defaultValidUntil = date('Y-m-d', strtotime('+14 days'));

// ============================================================
// CRM prefill: pre-fill customer fields from CRM
// ============================================================
$crmPrefillCustomer = null;
$crmCustomerId = isset($_GET['crm_customer_id']) && is_numeric($_GET['crm_customer_id'])
    ? (int)$_GET['crm_customer_id'] : 0;
if ($crmCustomerId > 0 && $isNew) {
    try {
        $crmStmt = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    COALESCE(contact_type,'individual') AS contact_type,
                    COALESCE(company_name,'') AS company_name,
                    COALESCE(vat_no,'') AS vat_no
             FROM customers WHERE id = :id LIMIT 1"
        );
        $crmStmt->execute([':id' => $crmCustomerId]);
        $crmPrefillCustomer = $crmStmt->fetch();
    } catch (Throwable $e) {
        try {
            $crmStmt = $pdo->prepare("SELECT id, name, email, phone, address, id_number FROM customers WHERE id = :id LIMIT 1");
            $crmStmt->execute([':id' => $crmCustomerId]);
            $crmPrefillCustomer = $crmStmt->fetch() ?: null;
        } catch (Throwable $e2) { /* ignore */ }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div>
        <h2 class="page-title"><?= $showForm ? ($isEdit ? 'Edit Quote' : 'New Quote') : 'Quotes' ?></h2>
        <p class="page-subtitle"><?= $showForm ? 'Fill in the details below.' : 'Manage your quotations.' ?></p>
    </div>
    <?php if (!$showForm): ?>
        <a href="<?= BASE_URL ?>/pos/quotes.php?new=1" class="btn btn-primary btn-sm">+ New Quote</a>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/pos/quotes.php" class="btn btn-outline btn-sm">← Back to Quotes</a>
    <?php endif; ?>
</div>

<?php foreach ($quoteErrors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if ($showForm): ?>
<!-- ============================================================
     CREATE / EDIT FORM VIEW
     ============================================================ -->

<!-- Hidden submit form -->
<form method="POST" action="" id="quote-submit-form" style="display:none;">
    <input type="hidden" name="action"          id="hf-action" value="<?= $isEdit ? 'update_quote' : 'save_quote' ?>">
    <input type="hidden" name="quote_id"        id="hf-quote-id" value="<?= $editId ?>">
    <input type="hidden" name="customer_name"      id="hf-cust-name">
    <input type="hidden" name="customer_email"     id="hf-cust-email">
    <input type="hidden" name="customer_phone"     id="hf-cust-phone">
    <input type="hidden" name="customer_address"   id="hf-cust-address">
    <input type="hidden" name="customer_id_number" id="hf-cust-idnum">
    <input type="hidden" name="contact_type"       id="hf-contact-type" value="individual">
    <input type="hidden" name="company_name"       id="hf-company-name">
    <input type="hidden" name="vat_no"             id="hf-vat-no">
    <input type="hidden" name="channel"            id="hf-channel">
    <input type="hidden" name="valid_until"        id="hf-valid-until">
    <input type="hidden" name="notes"              id="hf-notes">
    <input type="hidden" name="status"             id="hf-status">
    <div id="hf-items-container"></div>
</form>

<div class="pos-layout">

    <!-- LEFT COLUMN -->
    <div class="pos-entry-col">

        <!-- Quick Pick -->
        <?php if (!empty($quickPick)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h3 class="card-title">Quick Pick</h3></div>
            <div class="card-body" style="padding-bottom:.75rem;">
                <div class="pos-quickpick-grid">
                    <?php foreach ($quickPick as $qp): ?>
                    <div class="pos-quickpick-card"
                         data-product-id="<?= $qp['id'] ?>"
                         data-product-name="<?= htmlspecialchars($qp['name']) ?>"
                         data-product-sku="<?= htmlspecialchars($qp['sku']) ?>"
                         data-product-price="<?= number_format((float)$qp['selling_price'], 2, '.', '') ?>">
                        <?php if (!empty($qp['image_path'])): ?>
                            <img src="<?= BASE_URL . '/' . htmlspecialchars($qp['image_path']) ?>"
                                 alt="" class="pos-quickpick-img">
                        <?php else: ?>
                            <div class="pos-quickpick-placeholder">&#128247;</div>
                        <?php endif; ?>
                        <div class="pos-quickpick-name"><?= htmlspecialchars($qp['name']) ?></div>
                        <div class="pos-quickpick-price">R <?= number_format((float)$qp['selling_price'], 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add Line Item -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h3 class="card-title">Add Line Item</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Product (optional)</label>
                    <select id="q-product-select" class="form-control">
                        <option value="">— Select Product —</option>
                        <?php foreach ($products as $prod): ?>
                        <option value="<?= $prod['id'] ?>"
                                data-price="<?= number_format((float)$prod['selling_price'], 2, '.', '') ?>"
                                data-name="<?= htmlspecialchars($prod['name']) ?>"
                                data-sku="<?= htmlspecialchars($prod['sku']) ?>">
                            <?= htmlspecialchars($prod['name']) ?>
                            (R <?= number_format((float)$prod['selling_price'], 2) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" id="q-description" class="form-control" placeholder="Line item description…">
                </div>
                <div class="form-group">
                    <label class="form-label">Qty</label>
                    <input type="number" id="q-qty" class="form-control" min="1" value="1" style="max-width:120px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Unit Price (R, excl. VAT)</label>
                    <input type="number" id="q-unit-price" class="form-control" step="0.01" min="0" value="0.00">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="q-add-btn">Add to Quote</button>
                    <button type="button" class="btn btn-outline" id="q-clear-item-btn">Clear Item</button>
                </div>
            </div>
        </div>

        <!-- Customer Details -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title">Customer Details</h3>
                <?php if ($crmPrefillCustomer): ?>
                <span style="background:#DCFCE7;color:#16A34A;padding:.2rem .6rem;border-radius:6px;font-size:.8rem;font-weight:600;">✓ From CRM</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$isEdit): ?>
                <!-- Customer search autocomplete -->
                <div class="form-group" style="position:relative;">
                    <label class="form-label">Search Existing Customer</label>
                    <input type="text" id="q-cust-search" class="form-control"
                           placeholder="Type name, email, phone or company…" autocomplete="off">
                    <div id="q-cust-dropdown"
                         style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                                background:#fff;border:1px solid #D1D5DB;border-radius:8px;
                                box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;">
                    </div>
                </div>
                <?php endif; ?>
                <?php
                $qPrefillType = $crmPrefillCustomer['contact_type'] ?? ($editQuote['customer_type'] ?? 'individual');
                ?>
                <!-- Individual / Business toggle -->
                <div class="form-group">
                    <label class="form-label">Customer Type</label>
                    <div style="display:flex;gap:.5rem;">
                        <button type="button" id="q-type-individual"
                                onclick="setQCustType('individual')"
                                class="btn btn-sm <?= $qPrefillType === 'individual' ? 'btn-primary' : 'btn-outline' ?>"
                                style="flex:1;">👤 Individual</button>
                        <button type="button" id="q-type-business"
                                onclick="setQCustType('business')"
                                class="btn btn-sm <?= $qPrefillType === 'business' ? 'btn-primary' : 'btn-outline' ?>"
                                style="flex:1;">🏢 Business</button>
                    </div>
                </div>

                <!-- Business-only fields -->
                <div id="q-business-fields" style="display:<?= $qPrefillType === 'business' ? 'block' : 'none' ?>;">
                    <div class="form-group">
                        <label class="form-label">Company Name <span class="required">*</span></label>
                        <input type="text" id="q-cust-company" class="form-control"
                               placeholder="Registered company name"
                               value="<?= htmlspecialchars($crmPrefillCustomer['company_name'] ?? ($editQuote['customer_company'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">VAT Number <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                        <input type="text" id="q-cust-vat" class="form-control"
                               placeholder="e.g. 4123456789"
                               value="<?= htmlspecialchars($crmPrefillCustomer['vat_no'] ?? ($editQuote['customer_vat_no'] ?? '')) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" id="q-name-label"><?= $qPrefillType === 'business' ? 'Contact Person' : 'Full Name' ?> <span class="required">*</span></label>
                    <input type="text" id="q-cust-name" class="form-control"
                           placeholder="Full name"
                           value="<?= htmlspecialchars($crmPrefillCustomer['name'] ?? ($editQuote['customer_name'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" id="q-cust-email" class="form-control"
                           placeholder="customer@email.com"
                           value="<?= htmlspecialchars($crmPrefillCustomer['email'] ?? ($editQuote['customer_email'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" id="q-cust-phone" class="form-control"
                           placeholder="+27 82 000 0000"
                           value="<?= htmlspecialchars($crmPrefillCustomer['phone'] ?? ($editQuote['customer_phone'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea id="q-cust-address" class="form-control" rows="2"
                              placeholder="Street address (optional)"><?= htmlspecialchars($crmPrefillCustomer['address'] ?? ($editQuote['customer_address'] ?? '')) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                        Account No
                        <?php if (!$isEdit): ?><span style="font-size:.75rem;color:#9CA3AF;font-weight:400;">auto-generated</span><?php endif; ?>
                    </label>
                    <input type="text" id="q-cust-idnum" class="form-control"
                           placeholder="e.g. JOHN-202605-001"
                           value="<?= htmlspecialchars($crmPrefillCustomer['id_number'] ?? ($editQuote['customer_id_number'] ?? '')) ?>"
                           style="font-family:monospace;letter-spacing:.03em;">
                </div>
            </div>
        </div>

        <!-- Quote Details -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h3 class="card-title">Quote Details</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Channel</label>
                    <select id="q-channel" class="form-control">
                        <?php foreach ($channelLabels as $val => $lbl): ?>
                        <option value="<?= $val ?>"
                            <?= (($editQuote['channel'] ?? 'instore') === $val) ? 'selected' : '' ?>>
                            <?= $lbl ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Valid Until</label>
                    <input type="date" id="q-valid-until" class="form-control"
                           value="<?= htmlspecialchars($editQuote['valid_until'] ?? $defaultValidUntil) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select id="q-status" class="form-control">
                        <?php foreach ($statusLabels as $val => $lbl): ?>
                        <option value="<?= $val ?>"
                            <?= (($editQuote['status'] ?? 'draft') === $val) ? 'selected' : '' ?>>
                            <?= $lbl ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea id="q-notes" class="form-control" rows="2"
                              placeholder="Optional notes…"><?= htmlspecialchars($editQuote['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="card">
            <div class="card-body">
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="q-submit-btn">
                        <?= $isEdit ? 'Update Quote' : 'Save Quote' ?>
                    </button>
                    <a href="<?= BASE_URL ?>/pos/quotes.php" class="btn btn-outline">Cancel</a>
                </div>
            </div>
        </div>

    </div><!-- /.pos-entry-col -->

    <!-- RIGHT COLUMN: Preview -->
    <div class="pos-preview-card">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Quote Preview</h3></div>
            <div class="card-body">

                <div id="q-lines-display">
                    <div class="invoice-empty-state" id="q-empty-state">
                        No items added yet. Pick a product or type a description and click "Add to Quote".
                    </div>
                </div>

                <div class="invoice-totals" id="q-totals-block" style="display:none;">
                    <div class="invoice-total-row">
                        <span>Subtotal (excl. VAT)</span>
                        <span id="q-subtotal">R 0.00</span>
                    </div>
                    <div class="invoice-total-row">
                        <span>VAT (15%)</span>
                        <span id="q-vat-total">R 0.00</span>
                    </div>
                    <div class="invoice-total-row grand-total">
                        <span>Grand Total (incl. VAT)</span>
                        <span id="q-grand-total">R 0.00</span>
                    </div>
                </div>

                <div id="q-customer-preview" style="margin-top:1rem;display:none;">
                    <hr style="margin:.75rem 0;border:none;border-top:1px solid var(--color-border);">
                    <div class="invoice-section-label">Quote For</div>
                    <div id="q-customer-preview-text" class="invoice-section-value" style="font-size:.85rem;"></div>
                </div>

            </div>
        </div>
    </div><!-- /.pos-preview-card -->

</div><!-- /.pos-layout -->

<script>
(function () {
    var BASE_URL = '<?= BASE_URL ?>';
    var VAT_RATE = 0.15;

    // Pre-fill existing items when editing
    var quoteLines = <?= $isEdit && !empty($editQuoteItems)
        ? json_encode(array_map(function($qi) {
            return [
                'product_id'  => $qi['product_id'],
                'description' => $qi['description'],
                'qty'         => (int)$qi['qty'],
                'unit_price'  => (float)$qi['unit_price'],
            ];
        }, $editQuoteItems))
        : '[]' ?>;

    // DOM refs
    var productSel   = document.getElementById('q-product-select');
    var descInput    = document.getElementById('q-description');
    var qtyInput     = document.getElementById('q-qty');
    var priceInput   = document.getElementById('q-unit-price');
    var linesDiv     = document.getElementById('q-lines-display');
    var emptyState   = document.getElementById('q-empty-state');
    var totalsBlock  = document.getElementById('q-totals-block');
    var custPreview  = document.getElementById('q-customer-preview');
    var custPreviewT = document.getElementById('q-customer-preview-text');

    var custNameIn   = document.getElementById('q-cust-name');
    var custEmailIn  = document.getElementById('q-cust-email');
    var custPhoneIn  = document.getElementById('q-cust-phone');
    var custAddrIn   = document.getElementById('q-cust-address');
    var custIdIn     = document.getElementById('q-cust-idnum');
    var channelSel   = document.getElementById('q-channel');
    var validUntilIn = document.getElementById('q-valid-until');
    var notesIn      = document.getElementById('q-notes');
    var statusSel    = document.getElementById('q-status');

    var addBtn      = document.getElementById('q-add-btn');
    var clearBtn    = document.getElementById('q-clear-item-btn');
    var submitBtn   = document.getElementById('q-submit-btn');
    var submitForm  = document.getElementById('quote-submit-form');

    // Customer type refs
    var qCustTypeHf = document.getElementById('hf-contact-type');
    var qBizFields  = document.getElementById('q-business-fields');
    var qNameLabel  = document.getElementById('q-name-label');
    var qTypeBtnInd = document.getElementById('q-type-individual');
    var qTypeBtnBiz = document.getElementById('q-type-business');
    var qCompanyIn  = document.getElementById('q-cust-company');
    var qVatIn      = document.getElementById('q-cust-vat');

    function setQCustType(type) {
        if (qCustTypeHf) qCustTypeHf.value = type;
        if (qBizFields)  qBizFields.style.display = (type === 'business') ? 'block' : 'none';
        if (qNameLabel)  qNameLabel.innerHTML = (type === 'business'
            ? 'Contact Person <span class="required">*</span>'
            : 'Full Name <span class="required">*</span>');
        if (qTypeBtnInd) qTypeBtnInd.className = 'btn btn-sm ' + (type === 'individual' ? 'btn-primary' : 'btn-outline');
        if (qTypeBtnBiz) qTypeBtnBiz.className = 'btn btn-sm ' + (type === 'business'   ? 'btn-primary' : 'btn-outline');
    }
    // Expose for onclick= in HTML
    window.setQCustType = setQCustType;
    // Initialise from server-rendered state
    setQCustType('<?= addslashes($qPrefillType) ?>');

    // ---- Quick Pick cards ----
    var quickPickCards = document.querySelectorAll('.pos-quickpick-card');
    quickPickCards.forEach(function (card) {
        card.addEventListener('click', function () {
            quickPickCards.forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            var pid   = card.getAttribute('data-product-id');
            var name  = card.getAttribute('data-product-name');
            var price = card.getAttribute('data-product-price');
            for (var i = 0; i < productSel.options.length; i++) {
                if (productSel.options[i].value === pid) {
                    productSel.selectedIndex = i;
                    break;
                }
            }
            descInput.value  = name;
            priceInput.value = price;
            qtyInput.value   = '1';
            descInput.focus();
        });
    });

    // ---- Product select: pre-fill description + price ----
    productSel.addEventListener('change', function () {
        var opt = productSel.options[productSel.selectedIndex];
        if (opt.value) {
            descInput.value  = opt.getAttribute('data-name') || '';
            priceInput.value = opt.getAttribute('data-price') || '0.00';
        }
    });

    // ---- Add item ----
    addBtn.addEventListener('click', function () {
        var pid   = productSel.value ? parseInt(productSel.value) : null;
        var desc  = descInput.value.trim();
        var qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
        var price = parseFloat(priceInput.value) || 0;

        if (!desc && !pid) {
            alert('Please select a product or enter a description.');
            return;
        }
        if (!desc && pid) {
            var opt = productSel.options[productSel.selectedIndex];
            desc = opt.getAttribute('data-name') || 'Item';
        }

        quoteLines.push({ product_id: pid, description: desc, qty: qty, unit_price: price });
        renderPreview();

        productSel.selectedIndex = 0;
        descInput.value          = '';
        qtyInput.value           = '1';
        priceInput.value         = '0.00';
        quickPickCards.forEach(function (c) { c.classList.remove('active'); });
    });

    clearBtn.addEventListener('click', function () {
        productSel.selectedIndex = 0;
        descInput.value          = '';
        qtyInput.value           = '1';
        priceInput.value         = '0.00';
    });

    // ---- Render preview ----
    function fmt(n) {
        return 'R ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function renderPreview() {
        linesDiv.querySelectorAll('.invoice-line-item').forEach(function (el) { el.remove(); });

        if (quoteLines.length === 0) {
            emptyState.style.display  = '';
            totalsBlock.style.display = 'none';
            return;
        }
        emptyState.style.display  = 'none';
        totalsBlock.style.display = '';

        var subtotal = 0, vatTotal = 0;

        quoteLines.forEach(function (line, idx) {
            var qty       = line.qty || 1;
            var lineEx    = line.unit_price * qty;
            var vatAmt    = Math.round(lineEx * VAT_RATE * 100) / 100;
            var lineTotal = Math.round(lineEx * (1 + VAT_RATE) * 100) / 100;
            subtotal += lineEx;
            vatTotal += vatAmt;

            var priceLabel = qty > 1 ? fmt(line.unit_price) + ' × ' + qty : fmt(line.unit_price);

            var div = document.createElement('div');
            div.className = 'invoice-line-item';
            div.innerHTML =
                '<div style="flex:1;min-width:0;">' +
                    '<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + escHtml(line.description) + '">' + escHtml(line.description) + '</div>' +
                    '<div style="color:var(--color-muted);font-size:.78rem;">Qty: ' + qty + '</div>' +
                '</div>' +
                '<div style="white-space:nowrap;text-align:right;min-width:90px;">' +
                    '<div>' + priceLabel + '</div>' +
                    '<div style="color:var(--color-muted);font-size:.78rem;">VAT: ' + fmt(vatAmt) + '</div>' +
                    '<div style="font-weight:700;">= ' + fmt(lineTotal) + '</div>' +
                '</div>' +
                '<button type="button" data-idx="' + idx + '" class="btn btn-sm btn-danger q-remove-line" style="flex-shrink:0;padding:.2rem .45rem;font-size:.75rem;">×</button>';
            linesDiv.appendChild(div);
        });

        var grandTotal = Math.round((subtotal + vatTotal) * 100) / 100;
        document.getElementById('q-subtotal').textContent    = fmt(subtotal);
        document.getElementById('q-vat-total').textContent   = fmt(vatTotal);
        document.getElementById('q-grand-total').textContent = fmt(grandTotal);
    }

    linesDiv.addEventListener('click', function (e) {
        var btn = e.target.closest('.q-remove-line');
        if (!btn) return;
        quoteLines.splice(parseInt(btn.getAttribute('data-idx')), 1);
        renderPreview();
    });

    // ---- Customer preview ----
    [custNameIn, custEmailIn, custPhoneIn, custAddrIn, custIdIn].forEach(function (el) {
        el.addEventListener('input', updateCustomerPreview);
    });

    function updateCustomerPreview() {
        var name  = custNameIn.value.trim();
        var email = custEmailIn.value.trim();
        var phone = custPhoneIn.value.trim();
        if (!name && !email && !phone) {
            custPreview.style.display = 'none';
            return;
        }
        custPreview.style.display = '';
        var lines = [];
        if (name)                       lines.push('<strong>' + escHtml(name) + '</strong>');
        if (email)                      lines.push(escHtml(email));
        if (phone)                      lines.push(escHtml(phone));
        if (custAddrIn.value.trim())    lines.push(escHtml(custAddrIn.value.trim()));
        if (custIdIn.value.trim())      lines.push('ID: ' + escHtml(custIdIn.value.trim()));
        custPreviewT.innerHTML = lines.join('<br>');
    }

    // ---- Submit ----
    submitBtn.addEventListener('click', function () {
        if (quoteLines.length === 0) { alert('Please add at least one line item.'); return; }
        if (!custNameIn.value.trim()) { alert('Customer name is required.'); custNameIn.focus(); return; }

        document.getElementById('hf-cust-name').value    = custNameIn.value.trim();
        document.getElementById('hf-cust-email').value   = custEmailIn.value.trim();
        document.getElementById('hf-cust-phone').value   = custPhoneIn.value.trim();
        document.getElementById('hf-cust-address').value = custAddrIn.value.trim();
        document.getElementById('hf-cust-idnum').value   = custIdIn.value.trim();
        document.getElementById('hf-contact-type').value = qCustTypeHf ? qCustTypeHf.value : 'individual';
        document.getElementById('hf-company-name').value = qCompanyIn  ? qCompanyIn.value.trim()  : '';
        document.getElementById('hf-vat-no').value       = qVatIn      ? qVatIn.value.trim()      : '';
        document.getElementById('hf-channel').value      = channelSel.value;
        document.getElementById('hf-valid-until').value  = validUntilIn.value;
        document.getElementById('hf-notes').value        = notesIn.value.trim();
        document.getElementById('hf-status').value       = statusSel.value;

        var container = document.getElementById('hf-items-container');
        container.innerHTML = '';
        quoteLines.forEach(function (line) {
            function hi(n, v) {
                var el = document.createElement('input');
                el.type  = 'hidden';
                el.name  = n;
                el.value = v;
                container.appendChild(el);
            }
            hi('item_product_id[]',  line.product_id || '');
            hi('item_description[]', line.description);
            hi('item_qty[]',         line.qty || 1);
            hi('item_unit_price[]',  (line.unit_price || 0).toFixed(2));
        });

        submitForm.submit();
    });

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Init
    renderPreview();
    updateCustomerPreview();

    // ---- Account No auto-generation ----
    <?php if (!$isEdit): ?>
    (function () {
        if (!custNameIn || !custIdIn) return;
        var _accT = null;
        function genAcc(name) {
            var cur = custIdIn.value.trim();
            if (cur !== '' && !/^[A-Z]{2,4}-\d{6}-\d{3}$/.test(cur)) return;
            fetch(BASE_URL + '/pos/index.php?ajax=gen_account_no&name=' + encodeURIComponent(name))
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.account_no) custIdIn.value = d.account_no; })
                .catch(function () {});
        }
        custNameIn.addEventListener('input', function () {
            clearTimeout(_accT);
            var n = this.value.trim();
            if (n.length >= 2) _accT = setTimeout(function () { genAcc(n); }, 450);
        });
    }());
    <?php endif; ?>

    // ---- Customer search autocomplete ----
    (function () {
        var searchIn = document.getElementById('q-cust-search');
        var dropdown = document.getElementById('q-cust-dropdown');
        if (!searchIn || !dropdown) return;

        var _t = null;

        function fillCustomer(c) {
            custNameIn.value  = c.name      || '';
            custEmailIn.value = c.email     || '';
            custPhoneIn.value = c.phone     || '';
            custAddrIn.value  = c.address   || '';
            custIdIn.value    = c.id_number || '';
            if (c.contact_type) setQCustType(c.contact_type);
            if (qCompanyIn) qCompanyIn.value = c.company_name || '';
            if (qVatIn)     qVatIn.value     = c.vat_no       || '';
            searchIn.value    = '';
            dropdown.style.display = 'none';
            updateCustomerPreview();
        }

        function showDrop(results) {
            dropdown.innerHTML = '';
            if (!results.length) {
                dropdown.innerHTML = '<div style="padding:.6rem 1rem;color:#9CA3AF;font-size:.9rem;">No customers found</div>';
                dropdown.style.display = 'block';
                return;
            }
            results.forEach(function (c) {
                var div = document.createElement('div');
                div.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.9rem;';
                var sub = [];
                if (c.company_name) sub.push(c.company_name);
                if (c.email)        sub.push(c.email);
                if (c.phone)        sub.push(c.phone);
                div.innerHTML = '<strong>' + escHtml(c.name) + '</strong>'
                    + (sub.length ? '<br><span style="color:#6B7280;font-size:.82rem;">' + sub.map(escHtml).join(' · ') + '</span>' : '');
                div.addEventListener('mousedown', function (e) { e.preventDefault(); fillCustomer(c); });
                div.addEventListener('mouseover',  function () { div.style.background = '#F0F9FF'; });
                div.addEventListener('mouseout',   function () { div.style.background = ''; });
                dropdown.appendChild(div);
            });
            dropdown.style.display = 'block';
        }

        searchIn.addEventListener('input', function () {
            clearTimeout(_t);
            var q = this.value.trim();
            if (q.length < 2) { dropdown.style.display = 'none'; return; }
            _t = setTimeout(function () {
                fetch(BASE_URL + '/pos/index.php?ajax=search_customer&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(showDrop)
                    .catch(function () { dropdown.style.display = 'none'; });
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (!searchIn.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }());

})();
</script>

<?php else: ?>
<!-- ============================================================
     LIST VIEW
     ============================================================ -->

<!-- Filter bar -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-body" style="padding:.75rem 1rem;">
        <form method="GET" action="" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.75rem;">
            <div class="form-group" style="margin:0;min-width:120px;">
                <label class="form-label" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($statusLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:130px;">
                <label class="form-label" style="font-size:.8rem;">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($listDateFrom) ?>">
            </div>
            <div class="form-group" style="margin:0;min-width:130px;">
                <label class="form-label" style="font-size:.8rem;">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($listDateTo) ?>">
            </div>
            <div class="form-group" style="margin:0;min-width:180px;">
                <label class="form-label" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Customer name or quote no…"
                       value="<?= htmlspecialchars($listSearch) ?>">
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?= BASE_URL ?>/pos/quotes.php" class="btn btn-outline btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Quotes table -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">Quotes</h3>
        <span style="font-size:.85rem;color:var(--color-muted);">
            <?= count($quotesList) ?> result<?= count($quotesList) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="table" style="margin:0;width:100%;">
            <thead>
                <tr>
                    <th>Quote No</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Valid Until</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotesList as $q): ?>
                <tr style="cursor:pointer;"
                    onclick="window.location='<?= BASE_URL ?>/pos/quote_view.php?id=<?= $q['id'] ?>'"
                    title="View quote <?= htmlspecialchars($q['quote_no']) ?>">
                    <td style="font-family:monospace;font-size:.85rem;white-space:nowrap;">
                        <?= htmlspecialchars($q['quote_no']) ?>
                    </td>
                    <td style="font-size:.85rem;white-space:nowrap;">
                        <?= date('d M Y', strtotime($q['created_at'])) ?>
                    </td>
                    <td><?= htmlspecialchars($q['customer_name'] ?: '—') ?></td>
                    <td style="font-size:.85rem;">
                        <?= !empty($q['valid_until']) ? date('d M Y', strtotime($q['valid_until'])) : '—' ?>
                    </td>
                    <td>
                        <span class="badge"
                              style="background:<?= $statusColors[$q['status']] ?? '#6B7280' ?>;color:#fff;padding:.15rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;">
                            <?= htmlspecialchars($statusLabels[$q['status']] ?? ucfirst($q['status'])) ?>
                        </span>
                    </td>
                    <td class="text-right"><strong>R <?= number_format((float)$q['total'], 2) ?></strong></td>
                    <td style="white-space:nowrap;" onclick="event.stopPropagation()">
                        <?php if ($q['status'] === 'draft'): ?>
                        <a href="<?= BASE_URL ?>/pos/quotes.php?edit=<?= $q['id'] ?>"
                           class="btn btn-sm btn-outline">Edit</a>
                        <?php endif; ?>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="action"   value="convert_to_invoice">
                            <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline"
                                    title="Convert this quote to an invoice"
                                    onclick="return confirm('Convert quote to invoice? You will be taken to the POS with items pre-filled.');">
                                Convert
                            </button>
                        </form>
                        <?php if ($q['status'] === 'draft'): ?>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="action"   value="delete_quote">
                            <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete this draft quote? This cannot be undone.');">
                                Delete
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($quotesList)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--color-muted);padding:2rem;">
                        No quotes found. <a href="<?= BASE_URL ?>/pos/quotes.php?new=1">Create your first quote</a>.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.text-right { text-align: right; }
</style>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
