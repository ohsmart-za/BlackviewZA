<?php
// ============================================================
// Blackview SA Portal — Point of Sale
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Point of Sale';

// Check POS access — admins/superusers always allowed; others need can_use_pos=1
// Skip this check for AJAX calls so product/customer search works from any page
if (!isAdmin() && !isset($_GET['ajax'])) {
    try {
        $posChk = $pdo->prepare("SELECT COALESCE(can_use_pos,1) FROM users WHERE id=:id LIMIT 1");
        $posChk->execute([':id' => $_SESSION['user_id']]);
        if (!(bool)$posChk->fetchColumn()) {
            setFlash('info', 'POS is not enabled for your account. Use the Sales Invoice page.');
            header('Location: ' . BASE_URL . '/invoices/create.php');
            exit;
        }
    } catch (Throwable $e) { /* column not yet migrated — allow through */ }
}

// ============================================================
// AJAX: Validate serial number
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_serial') {
    header('Content-Type: application/json');
    $sn = trim($_GET['serial_no'] ?? '');
    if ($sn === '') { echo json_encode(['found' => false]); exit; }
    $stmt = $pdo->prepare(
        "SELECT si.serial_no, si.warehouse_id, w.name AS warehouse_name,
                p.id AS product_id, p.name AS product_name, p.sku,
                p.selling_price, COALESCE(p.vat_rate,15) AS vat_rate,
                COALESCE(p.is_serialised,1) AS is_serialised
         FROM stock_items si
         JOIN products p ON p.id = si.product_id
         JOIN warehouses w ON w.id = si.warehouse_id
         WHERE si.serial_no = :sn AND si.status = 'in_stock'
         LIMIT 1"
    );
    $stmt->execute([':sn' => $sn]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        $exclPrice = (float)$item['selling_price'];
        $vatRate   = (float)$item['vat_rate'];
        $inclPrice = round($exclPrice * (1 + $vatRate / 100), 2);
        echo json_encode([
            'found'            => true,
            'product_id'       => (int)$item['product_id'],
            'product_name'     => $item['product_name'],
            'sku'              => $item['sku'],
            'is_serialised'    => (int)$item['is_serialised'],
            'warehouse_id'     => $item['warehouse_id'],
            'warehouse_name'   => $item['warehouse_name'],
            'selling_price'    => number_format($exclPrice, 2, '.', ''),
            'selling_price_incl' => number_format($inclPrice, 2, '.', ''),
            'serial_no'        => $item['serial_no'],
        ]);
    } else {
        echo json_encode(['found' => false, 'message' => 'Serial not found or not in stock.']);
    }
    exit;
}

// ============================================================
// AJAX: Generate unique Account No from name
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'gen_account_no') {
    header('Content-Type: application/json');
    $name = trim($_GET['name'] ?? '');
    if ($name === '') { echo json_encode(['account_no' => '']); exit; }
    // Build prefix: first 4 uppercase alpha chars of name
    $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
    $prefix = substr($prefix, 0, 4);
    if (strlen($prefix) < 1) $prefix = 'CUST';
    $prefix = str_pad($prefix, 2, 'X'); // ensure at least 2 chars
    $month  = date('Ym');
    $base   = $prefix . '-' . $month;
    // Count existing customers whose account_no starts with this base
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE id_number LIKE :p");
    $cnt->execute([':p' => $base . '-%']);
    $seq = (int)$cnt->fetchColumn() + 1;
    echo json_encode(['account_no' => $base . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT)]);
    exit;
}

// ============================================================
// AJAX: Search customers by name / email / company / phone
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_customer') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    COALESCE(contact_type,'individual') AS contact_type,
                    COALESCE(company_name,'') AS company_name,
                    COALESCE(vat_no,'') AS vat_no
             FROM customers
             WHERE name LIKE :q OR email LIKE :q2 OR company_name LIKE :q3 OR phone LIKE :q4
             ORDER BY name ASC LIMIT 10"
        );
        $stmt->execute([':q'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        // Fallback without CRM columns
        $stmt = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    'individual' AS contact_type, '' AS company_name, '' AS vat_no
             FROM customers
             WHERE name LIKE :q OR email LIKE :q2 OR phone LIKE :q3
             ORDER BY name ASC LIMIT 10"
        );
        $stmt->execute([':q'=>$like,':q2'=>$like,':q3'=>$like]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

// ============================================================
// AJAX: Search products by SKU (exact) or name/SKU (partial)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_product') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode([]); exit; }

    // 1. Exact SKU match (barcode scan)
    $exact = $pdo->prepare(
        "SELECT id, name, sku, selling_price, COALESCE(vat_rate,15) AS vat_rate,
                COALESCE(is_serialised,1) AS is_serialised,
                COALESCE(product_type,'physical') AS product_type
         FROM products WHERE sku = :q AND is_active = 1 LIMIT 1"
    );
    $exact->execute([':q' => $q]);
    $results = $exact->fetchAll(PDO::FETCH_ASSOC);

    // 2. If no exact match, partial name/SKU search
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

    // Add incl. VAT price to each result
    foreach ($results as &$r) {
        $r['selling_price_incl'] = round((float)$r['selling_price'] * (1 + (float)$r['vat_rate'] / 100), 2);
        $r['is_serialised']      = (int)$r['is_serialised'];
    }
    unset($r);

    echo json_encode($results);
    exit;
}

// ============================================================
// POST: Create Invoice
// ============================================================
$posErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {

    $custName    = trim($_POST['customer_name']  ?? '');
    $custEmail   = trim($_POST['customer_email'] ?? '');
    $custPhone   = trim($_POST['customer_phone'] ?? '');
    $custAddress = trim($_POST['customer_address'] ?? '');
    $custIdNum   = trim($_POST['customer_id_number'] ?? '');
    $contactType = in_array($_POST['contact_type'] ?? '', ['individual','business']) ? $_POST['contact_type'] : 'individual';
    $companyName = trim($_POST['company_name'] ?? '');
    $vatNo       = trim($_POST['vat_no'] ?? '');
    $channel        = trim($_POST['channel']         ?? 'instore');
    $paymentMethod  = trim($_POST['payment_method']  ?? 'cash');
    $notes          = trim($_POST['notes']           ?? '');
    $discountPct    = min(10.0, max(0.0, (float)($_POST['discount_pct'] ?? 0)));

    $validPayments = $pdo->query("SELECT code FROM payment_methods WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($validPayments)) $validPayments = ['cash', 'eft', 'card'];
    if (!in_array($paymentMethod, $validPayments, true)) $paymentMethod = $validPayments[0];

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemSerials    = $_POST['item_serial_no']   ?? [];
    $itemPrices     = $_POST['item_unit_price']  ?? [];
    $itemQtys       = $_POST['item_qty']         ?? [];

    if ($custName === '') $posErrors[] = 'Customer name is required.';

    // Load product types in one query (needed to skip stock logic for services)
    $allPids = array_filter(array_map('intval', $itemProductIds));
    $prodTypeMap = [];
    if (!empty($allPids)) {
        $inSql = implode(',', array_fill(0, count($allPids), '?'));
        try {
            $ptStmt = $pdo->prepare(
                "SELECT id, COALESCE(product_type,'physical') AS product_type FROM products WHERE id IN ($inSql)"
            );
            $ptStmt->execute($allPids);
            foreach ($ptStmt->fetchAll() as $pt) {
                $prodTypeMap[$pt['id']] = $pt['product_type'];
            }
        } catch (Throwable $e) { /* product_type column not yet on this DB — treat all as physical */ }
    }

    // Build item list
    $saleItems = [];
    foreach ($itemProductIds as $i => $pid) {
        $sn    = trim($itemSerials[$i]  ?? '');
        $price = (float)($itemPrices[$i] ?? 0);
        $qty   = max(1, (int)($itemQtys[$i] ?? 1));
        $pid   = (int)$pid;
        if ($pid > 0) {
            $saleItems[] = [
                'product_id'   => $pid,
                'serial_no'    => $sn,
                'unit_price'   => $price,
                'qty'          => $sn !== '' ? 1 : $qty,
                'warehouse_id' => null,
                'is_service'   => ($prodTypeMap[$pid] ?? 'physical') === 'service',
            ];
        }
    }
    if (empty($saleItems)) $posErrors[] = 'Please add at least one item to the invoice.';

    // Validate stock — skip entirely for service products
    if (empty($posErrors)) {
        foreach ($saleItems as &$si) {
            if ($si['is_service']) continue; // no stock to check

            if ($si['serial_no'] !== '') {
                $chk = $pdo->prepare(
                    "SELECT si2.warehouse_id, si2.product_id FROM stock_items si2
                     WHERE si2.serial_no = :sn AND si2.status = 'in_stock' LIMIT 1"
                );
                $chk->execute([':sn' => $si['serial_no']]);
                $row = $chk->fetch();
                if (!$row) {
                    $posErrors[] = "Serial number \"{$si['serial_no']}\" is not available in stock.";
                } else {
                    $si['warehouse_id'] = $row['warehouse_id'];
                }
            } else {
                // Non-serialised: find any warehouse that has stock
                $chk = $pdo->prepare(
                    "SELECT warehouse_id FROM inventory_stock
                     WHERE product_id = :pid AND qty >= :qty LIMIT 1"
                );
                $chk->execute([':pid' => $si['product_id'], ':qty' => $si['qty']]);
                $row = $chk->fetch();
                if (!$row) {
                    $posErrors[] = "Insufficient stock for product ID {$si['product_id']} (qty {$si['qty']}).";
                } else {
                    $si['warehouse_id'] = $row['warehouse_id'];
                }
            }
        }
        unset($si);
    }

    if (empty($posErrors)) {
        try {
            $pdo->beginTransaction();

            // Create or reuse customer
            $customerId = null;
            if ($custEmail !== '') {
                $cstChk = $pdo->prepare('SELECT id FROM customers WHERE email = :e LIMIT 1');
                $cstChk->execute([':e' => $custEmail]);
                $existCust = $cstChk->fetch();
                if ($existCust) {
                    $customerId = (int)$existCust['id'];
                    // Update details
                    try {
                        $pdo->prepare('UPDATE customers SET name=:n, phone=:p, address=:a, id_number=:i,
                                       contact_type=:ct, company_name=:co, vat_no=:vat WHERE id=:id')
                            ->execute([':n'=>$custName,':p'=>$custPhone,':a'=>$custAddress,':i'=>$custIdNum,
                                       ':ct'=>$contactType,':co'=>$companyName,':vat'=>$vatNo,':id'=>$customerId]);
                    } catch (Throwable $e) {
                        // Fallback if CRM columns not yet added
                        $pdo->prepare('UPDATE customers SET name=:n, phone=:p, address=:a, id_number=:i WHERE id=:id')
                            ->execute([':n'=>$custName,':p'=>$custPhone,':a'=>$custAddress,':i'=>$custIdNum,':id'=>$customerId]);
                    }
                }
            }
            if ($customerId === null) {
                try {
                    $insCust = $pdo->prepare(
                        'INSERT INTO customers (name, email, phone, address, id_number, contact_type, company_name, vat_no, created_at)
                         VALUES (:n, :e, :p, :a, :i, :ct, :co, :vat, NOW())'
                    );
                    $insCust->execute([':n'=>$custName,':e'=>$custEmail,':p'=>$custPhone,':a'=>$custAddress,
                                       ':i'=>$custIdNum,':ct'=>$contactType,':co'=>$companyName,':vat'=>$vatNo]);
                } catch (Throwable $e) {
                    // Fallback if CRM columns not yet added
                    $insCust = $pdo->prepare(
                        'INSERT INTO customers (name, email, phone, address, id_number, created_at)
                         VALUES (:n, :e, :p, :a, :i, NOW())'
                    );
                    $insCust->execute([':n'=>$custName,':e'=>$custEmail,':p'=>$custPhone,':a'=>$custAddress,':i'=>$custIdNum]);
                }
                $customerId = (int)$pdo->lastInsertId();
            }

            // Generate invoice number: INV-YYYYMM-####
            $monthPrefix = date('Ym');
            $invCountQ   = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE :p");
            $invCountQ->execute([':p' => "INV-{$monthPrefix}-%"]);
            $invSeq      = (int)$invCountQ->fetchColumn() + 1;
            $invoiceNo   = sprintf('INV-%s-%04d', $monthPrefix, $invSeq);

            // Calculate totals
            $subtotal  = 0;
            $vatRate   = 15.0;
            foreach ($saleItems as $si) {
                $subtotal += round($si['unit_price'] * $si['qty'], 4); // accumulate with precision
            }
            $discountAmount  = round($subtotal * $discountPct / 100, 2);
            $discountedSub   = round($subtotal - $discountAmount, 2);
            // Derive VAT from total-excl so sub+vat always equals total exactly
            $grandTotal      = round($discountedSub * (1 + $vatRate / 100), 2);
            $vatTotal        = round($grandTotal - $discountedSub, 2);

            // Insert invoice header
            $insInv = $pdo->prepare(
                "INSERT INTO invoices (invoice_no, customer_id, channel, payment_method, discount_pct, discount_amount, subtotal, vat_amount, total, notes, created_by, created_at)
                 VALUES (:no, :cust, :ch, :pm, :dpct, :damt, :sub, :vat, :tot, :notes, :uid, NOW())"
            );
            $insInv->execute([
                ':no'    => $invoiceNo,
                ':cust'  => $customerId,
                ':ch'    => $channel,
                ':pm'    => $paymentMethod,
                ':dpct'  => $discountPct,
                ':damt'  => $discountAmount,
                ':sub'   => $discountedSub,
                ':vat'   => $vatTotal,
                ':tot'   => $grandTotal,
                ':notes' => $notes,
                ':uid'   => $_SESSION['user_id'] ?? null,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();

            // Auto-allocate payment — POS sales are collected at time of sale
            $pdo->prepare(
                "INSERT INTO invoice_payments
                     (invoice_id, amount, payment_method, reference, notes, created_by, created_at)
                 VALUES (:inv, :amt, :pm, '', 'Auto-allocated at POS', :uid, NOW())"
            )->execute([
                ':inv' => $invoiceId,
                ':amt' => $grandTotal,
                ':pm'  => $paymentMethod,
                ':uid' => $_SESSION['user_id'] ?? null,
            ]);

            // Insert each line item
            $insItem = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id, product_id, serial_no, warehouse_id, qty, unit_price, vat_rate, vat_amount, line_total)
                 VALUES (:inv, :prod, :sn, :wh, :qty, :price, :vr, :va, :lt)"
            );
            $insMovSerialized = $pdo->prepare(
                "INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                 VALUES (:prod, :fwh, NULL, 1, :uid, :inv_no, :ch, :notes, NOW())"
            );
            $insMovBulk = $pdo->prepare(
                "INSERT INTO stock_movements (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
                 VALUES (:prod, :fwh, NULL, :qty, :uid, :inv_no, :ch, :notes, NOW())"
            );
            $insMovSerial = $pdo->prepare(
                "INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid, :sn)"
            );
            $updStock = $pdo->prepare(
                "UPDATE stock_items SET status = 'sold' WHERE serial_no = :sn"
            );
            $decInv = $pdo->prepare(
                "UPDATE inventory_stock SET qty = GREATEST(0, qty - :qty)
                 WHERE product_id = :prod AND warehouse_id = :wh"
            );

            foreach ($saleItems as $si) {
                $lineSubtotal = round($si['unit_price'] * $si['qty'], 2);          // excl., 2dp
                $lineTotal    = round($si['unit_price'] * $si['qty'] * (1 + $vatRate / 100), 2); // incl., authoritative
                $vatAmt       = round($lineTotal - $lineSubtotal, 2);               // vat = incl - excl (no gap)

                $insItem->execute([
                    ':inv'   => $invoiceId,
                    ':prod'  => $si['product_id'],
                    ':sn'    => $si['serial_no'] ?: null,
                    ':wh'    => $si['warehouse_id'],
                    ':qty'   => $si['qty'],
                    ':price' => $si['unit_price'],
                    ':vr'    => $vatRate,
                    ':va'    => $vatAmt,
                    ':lt'    => $lineTotal,
                ]);

                // Service products have no stock — skip all inventory/movement operations
                if (!$si['is_service']) {
                    if ($si['serial_no'] !== '') {
                        // Serialised item — one movement per serial
                        $insMovSerialized->execute([
                            ':prod'   => $si['product_id'],
                            ':fwh'    => $si['warehouse_id'],
                            ':uid'    => $_SESSION['user_id'] ?? null,
                            ':inv_no' => $invoiceNo,
                            ':ch'     => $channel,
                            ':notes'  => "POS Sale — Invoice $invoiceNo",
                        ]);
                        $movId = (int)$pdo->lastInsertId();
                        $insMovSerial->execute([':mid' => $movId, ':sn' => $si['serial_no']]);
                        $updStock->execute([':sn' => $si['serial_no']]);
                        $decInv->execute([':prod' => $si['product_id'], ':wh' => $si['warehouse_id'], ':qty' => 1]);
                    } else {
                        // Non-serialised — one bulk movement for qty
                        $insMovBulk->execute([
                            ':prod'   => $si['product_id'],
                            ':fwh'    => $si['warehouse_id'],
                            ':qty'    => $si['qty'],
                            ':uid'    => $_SESSION['user_id'] ?? null,
                            ':inv_no' => $invoiceNo,
                            ':ch'     => $channel,
                            ':notes'  => "POS Sale — Invoice $invoiceNo",
                        ]);
                        $decInv->execute([':prod' => $si['product_id'], ':wh' => $si['warehouse_id'], ':qty' => $si['qty']]);
                    }
                }
            }

            logAudit($pdo, 'pos_sale', 'invoices', $invoiceId,
                "POS sale — Invoice $invoiceNo, customer: $custName, items: " . count($saleItems) . ", total: R " . number_format($grandTotal, 2));

            $pdo->commit();

            header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $posErrors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// ============================================================
// CRM prefill: load customer data into page if coming from CRM
// ============================================================
$crmPrefillCustomer = null;
$crmCustomerId = isset($_GET['crm_customer_id']) && is_numeric($_GET['crm_customer_id'])
    ? (int)$_GET['crm_customer_id'] : 0;
if ($crmCustomerId > 0) {
    try {
        $crmStmt = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    COALESCE(company_name,'') AS company_name,
                    COALESCE(vat_no,'') AS vat_no,
                    COALESCE(contact_type,'individual') AS contact_type
             FROM customers WHERE id = :id LIMIT 1"
        );
        $crmStmt->execute([':id' => $crmCustomerId]);
        $crmPrefillCustomer = $crmStmt->fetch();
    } catch (Throwable $e) {
        // Fallback without CRM columns
        try {
            $crmStmt = $pdo->prepare("SELECT id, name, email, phone, address, id_number FROM customers WHERE id = :id LIMIT 1");
            $crmStmt->execute([':id' => $crmCustomerId]);
            $row = $crmStmt->fetch();
            if ($row) { $row['company_name'] = ''; $row['vat_no'] = ''; $row['contact_type'] = 'individual'; $crmPrefillCustomer = $row; }
        } catch (Throwable $e2) { /* ignore */ }
    }
}

// ============================================================
// Quote prefill: load quote data into JS if redirected from quotes
// ============================================================
$prefillQuote      = null;
$prefillQuoteItems = [];
if (!empty($_SESSION['prefill_quote_id'])) {
    $pqId = (int)$_SESSION['prefill_quote_id'];
    unset($_SESSION['prefill_quote_id']); // consume immediately
    $pqStmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id LIMIT 1");
    $pqStmt->execute([':id' => $pqId]);
    $prefillQuote = $pqStmt->fetch();
    if ($prefillQuote) {
        $pqiStmt = $pdo->prepare("SELECT qi.*, p.name AS product_name, p.sku AS product_sku FROM quote_items qi LEFT JOIN products p ON p.id = qi.product_id WHERE qi.quote_id = :qid ORDER BY qi.id ASC");
        $pqiStmt->execute([':qid' => $pqId]);
        $prefillQuoteItems = $pqiStmt->fetchAll();
    }
}

// Load active payment methods for POS buttons
$posPaymentMethods = [
    ['code' => 'cash', 'name' => 'Cash', 'icon' => '💵'],
    ['code' => 'eft',  'name' => 'EFT',  'icon' => '🏦'],
    ['code' => 'card', 'name' => 'Card', 'icon' => '💳'],
];
try {
    $pmFetch = $pdo->query(
        "SELECT code, name, icon FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
    )->fetchAll();
    if (!empty($pmFetch)) $posPaymentMethods = $pmFetch;
} catch (Throwable $e) {
    // payment_methods table not yet created — use hardcoded defaults above
}

// Load product list for select (include is_serialised flag, vat_rate, product_type for incl. price display)
$products = $pdo->query(
    "SELECT id, sku, name, selling_price, COALESCE(vat_rate,15) AS vat_rate, COALESCE(is_serialised,1) AS is_serialised, COALESCE(product_type,'physical') AS product_type FROM products WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();

// ---- Quick Pick: up to 6 featured products, fill remainder with non-featured ----
$quickPick = $pdo->query(
    "SELECT id, sku, name, selling_price, COALESCE(vat_rate,15) AS vat_rate, image_path
     FROM products
     WHERE is_active = 1 AND is_featured = 1
     ORDER BY name ASC
     LIMIT 6"
)->fetchAll();

if (count($quickPick) < 6) {
    $needed      = 6 - count($quickPick);
    $featuredIds = array_column($quickPick, 'id');
    if (!empty($featuredIds)) {
        $notInSql = implode(',', array_fill(0, count($featuredIds), '?'));
        $fillStmt = $pdo->prepare(
            "SELECT id, sku, name, selling_price, COALESCE(vat_rate,15) AS vat_rate, image_path
             FROM products
             WHERE is_active = 1 AND is_featured = 0 AND id NOT IN ($notInSql)
             ORDER BY name ASC
             LIMIT $needed"
        );
        $fillStmt->execute($featuredIds);
    } else {
        $fillStmt = $pdo->prepare(
            "SELECT id, sku, name, selling_price, COALESCE(vat_rate,15) AS vat_rate, image_path
             FROM products
             WHERE is_active = 1
             ORDER BY name ASC
             LIMIT $needed"
        );
        $fillStmt->execute();
    }
    $quickPick = array_merge($quickPick, $fillStmt->fetchAll());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Point of Sale</h2>
    <p class="page-subtitle">Create invoices and process sales.</p>
</div>

<?php foreach ($posErrors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="pos-layout">

    <!-- LEFT: Entry Form -->
    <div class="pos-entry-col">

        <!-- Section 1: Customer Details (shown first so cashier fills this before adding items) -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title">Customer Details</h3>
                <?php if ($crmPrefillCustomer): ?>
                <span style="background:#DCFCE7;color:#16A34A;padding:.2rem .6rem;border-radius:6px;font-size:.8rem;font-weight:600;">
                    ✓ From CRM
                </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Customer search autocomplete -->
                <div class="form-group" style="position:relative;">
                    <label class="form-label">Search Existing Customer</label>
                    <input type="text" id="pos-cust-search" class="form-control"
                           placeholder="Type name, email, phone or company…"
                           autocomplete="off">
                    <div id="pos-cust-dropdown"
                         style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                                background:#fff;border:1px solid #D1D5DB;border-radius:8px;
                                box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;">
                    </div>
                </div>
                <!-- Individual / Business toggle -->
                <?php
                $prefillType = $crmPrefillCustomer['contact_type'] ?? ($_POST['contact_type'] ?? 'individual');
                ?>
                <div class="form-group">
                    <label class="form-label">Customer Type</label>
                    <div style="display:flex;gap:.5rem;">
                        <button type="button" id="pos-type-individual"
                                onclick="setCustType('individual')"
                                class="btn btn-sm <?= $prefillType === 'individual' ? 'btn-primary' : 'btn-outline' ?>"
                                style="flex:1;">
                            👤 Individual
                        </button>
                        <button type="button" id="pos-type-business"
                                onclick="setCustType('business')"
                                class="btn btn-sm <?= $prefillType === 'business' ? 'btn-primary' : 'btn-outline' ?>"
                                style="flex:1;">
                            🏢 Business
                        </button>
                    </div>
                </div>

                <!-- Business-only fields -->
                <div id="pos-business-fields" style="display:<?= $prefillType === 'business' ? 'block' : 'none' ?>;">
                    <div class="form-group">
                        <label class="form-label">Company Name <span class="required">*</span></label>
                        <input type="text" id="pos-cust-company" class="form-control"
                               placeholder="Registered company name"
                               value="<?= htmlspecialchars($crmPrefillCustomer['company_name'] ?? ($_POST['company_name'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">VAT Number <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                        <input type="text" id="pos-cust-vat" class="form-control"
                               placeholder="e.g. 4123456789"
                               value="<?= htmlspecialchars($crmPrefillCustomer['vat_no'] ?? ($_POST['vat_no'] ?? '')) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" id="pos-name-label">Full Name <span class="required">*</span></label>
                    <input type="text" id="pos-cust-name" class="form-control"
                           placeholder="Full name"
                           value="<?= htmlspecialchars($crmPrefillCustomer['name'] ?? ($_POST['customer_name'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" id="pos-cust-email" class="form-control"
                           placeholder="customer@email.com"
                           value="<?= htmlspecialchars($crmPrefillCustomer['email'] ?? ($_POST['customer_email'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" id="pos-cust-phone" class="form-control"
                           placeholder="+27 82 000 0000"
                           value="<?= htmlspecialchars($crmPrefillCustomer['phone'] ?? ($_POST['customer_phone'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea id="pos-cust-address" class="form-control" rows="2"
                              placeholder="Street address (optional)"><?= htmlspecialchars($crmPrefillCustomer['address'] ?? ($_POST['customer_address'] ?? '')) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                        Account No
                        <span style="font-size:.75rem;color:#9CA3AF;font-weight:400;">auto-generated</span>
                    </label>
                    <input type="text" id="pos-cust-idnum" class="form-control"
                           placeholder="e.g. JOHN-202605-001"
                           value="<?= htmlspecialchars($crmPrefillCustomer['id_number'] ?? ($_POST['customer_id_number'] ?? '')) ?>"
                           style="font-family:monospace;letter-spacing:.03em;">
                </div>
            </div>
        </div>

        <!-- Quick Pick Section -->
        <?php if (!empty($quickPick)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h3 class="card-title">Quick Pick</h3></div>
            <div class="card-body" style="padding-bottom:.75rem;">
                <div class="pos-quickpick-grid">
                    <?php foreach ($quickPick as $qp): ?>
                    <?php $qpInclPrice = round((float)$qp['selling_price'] * (1 + (float)$qp['vat_rate'] / 100), 2); ?>
                    <div class="pos-quickpick-card"
                         data-product-id="<?= $qp['id'] ?>"
                         data-product-name="<?= htmlspecialchars($qp['name']) ?>"
                         data-product-sku="<?= htmlspecialchars($qp['sku']) ?>"
                         data-product-price="<?= number_format($qpInclPrice, 2, '.', '') ?>">
                        <?php if (!empty($qp['image_path'])): ?>
                            <img src="<?= BASE_URL . '/' . htmlspecialchars($qp['image_path']) ?>"
                                 alt="" class="pos-quickpick-img">
                        <?php else: ?>
                            <div class="pos-quickpick-placeholder">&#128247;</div>
                        <?php endif; ?>
                        <div class="pos-quickpick-name"><?= htmlspecialchars($qp['name']) ?></div>
                        <div class="pos-quickpick-price">R <?= number_format($qpInclPrice, 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 2: Product & Serial Entry -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h3 class="card-title">Add Item</h3></div>
            <div class="card-body">

                <!-- Smart scan bar -->
                <div class="form-group" style="margin-bottom:1.25rem;padding-bottom:1.25rem;border-bottom:2px dashed var(--color-border);">
                    <label class="form-label" style="font-weight:700;font-size:.95rem;">
                        Scan Barcode or Search Product
                    </label>
                    <div style="position:relative;">
                        <input type="text" id="pos-scan-input" class="form-control"
                               placeholder="Scan product barcode, serial number, or type name…"
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                               style="padding-right:2.5rem;">
                        <span id="pos-scan-spinner" style="display:none;position:absolute;right:.75rem;top:50%;transform:translateY(-50%);color:var(--color-muted);font-size:.85rem;">⟳</span>
                        <div id="pos-scan-dropdown"
                             style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);z-index:300;
                                    background:#fff;border:1.5px solid var(--color-border);border-radius:8px;
                                    box-shadow:0 6px 20px rgba(0,0,0,.13);max-height:280px;overflow-y:auto;">
                        </div>
                    </div>
                    <small class="form-hint">
                        Barcode scanner auto-detects product or serial. Typing shows live suggestions.
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Product</label>
                    <select id="pos-product-select" class="form-control">
                        <option value="">— Select Product —</option>
                        <?php foreach ($products as $prod):
                            $prodInclPrice = round((float)$prod['selling_price'] * (1 + (float)$prod['vat_rate'] / 100), 2);
                        ?>
                        <option value="<?= $prod['id'] ?>"
                                data-price="<?= number_format($prodInclPrice, 2, '.', '') ?>"
                                data-price-excl="<?= number_format((float)$prod['selling_price'], 2, '.', '') ?>"
                                data-name="<?= htmlspecialchars($prod['name']) ?>"
                                data-sku="<?= htmlspecialchars($prod['sku']) ?>"
                                data-serialised="<?= (int)$prod['is_serialised'] ?>"
                                data-ptype="<?= htmlspecialchars($prod['product_type']) ?>">
                            <?= htmlspecialchars($prod['name']) ?>
                            (R <?= number_format($prodInclPrice, 2) ?> incl. VAT)
                            <?php if ($prod['product_type'] === 'service'): ?>
                             [Service]
                            <?php elseif (!$prod['is_serialised']): ?>
                             [No serial]
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity</label>
                    <input type="number" id="pos-qty" class="form-control" min="1" value="1"
                           style="max-width:120px;">
                </div>
                <div class="form-group" id="pos-serial-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" id="pos-serial" class="form-control"
                           placeholder="Scan or type serial number...">
                    <small id="pos-serial-feedback" class="form-hint" style="min-height:1.2em;display:block;margin-top:.25rem;"></small>
                </div>
                <div class="form-group" id="pos-serials-multi-group" style="display:none;">
                    <label class="form-label">Serial Numbers <span class="form-label-note">(one per line — leave blank if not serialised)</span></label>
                    <textarea id="pos-serials-multi" class="form-control serial-textarea" rows="4"
                              placeholder="Scan or paste serial numbers, one per line..."></textarea>
                    <small id="pos-serials-multi-feedback" class="form-hint" style="margin-top:.25rem;display:block;"></small>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit Price (R, incl. VAT)</label>
                    <input type="number" id="pos-unit-price" class="form-control" step="0.01" min="0" value="0.00">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="pos-add-btn">Add to Invoice</button>
                    <button type="button" class="btn btn-outline" id="pos-clear-item-btn">Clear Item</button>
                </div>
            </div>
        </div>

        <!-- Section 3: Sale Details -->
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><h3 class="card-title">Sale Details</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Payment Method <span class="required">*</span></label>
                    <div class="payment-method-group" id="pos-payment-group">
                        <?php foreach ($posPaymentMethods as $pm): ?>
                        <button type="button"
                                class="payment-method-btn"
                                data-method="<?= htmlspecialchars($pm['code']) ?>">
                            <span class="payment-method-icon"><?= htmlspecialchars($pm['icon']) ?></span>
                            <span><?= htmlspecialchars($pm['name']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <small id="pos-payment-required-hint" style="display:none;color:#dc2626;font-size:.8rem;margin-top:.3rem;">
                        ⚠ Please select a payment method.
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label">Channel</label>
                    <select id="pos-channel" class="form-control">
                        <option value="instore" <?= (($_POST['channel'] ?? 'instore') === 'instore') ? 'selected' : '' ?>>In-Store</option>
                        <option value="takealot" <?= (($_POST['channel'] ?? '') === 'takealot') ? 'selected' : '' ?>>Takealot</option>
                        <option value="makro" <?= (($_POST['channel'] ?? '') === 'makro') ? 'selected' : '' ?>>Makro</option>
                        <option value="email" <?= (($_POST['channel'] ?? '') === 'email') ? 'selected' : '' ?>>Email</option>
                        <option value="other" <?= (($_POST['channel'] ?? '') === 'other') ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Discount</label>
                    <div style="display:flex;align-items:center;gap:.75rem;">
                        <input type="number" id="pos-discount" class="form-control"
                               min="0" max="10" step="0.5" value="0" style="max-width:120px;" placeholder="0">
                        <span style="color:var(--color-muted);font-size:.875rem;">% &nbsp;(max 10%)</span>
                    </div>
                    <div id="pos-discount-notice" class="alert alert-warning"
                         style="display:none;margin-top:.5rem;padding:.45rem .75rem;font-size:.85rem;">
                        ⚠️ A <strong id="pos-discount-pct-display">0</strong>% discount will be applied.
                        This comes out of <strong>your commission</strong>.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea id="pos-notes" class="form-control" rows="2"
                              placeholder="Optional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Section 4: Actions -->
        <div class="card">
            <div class="card-body">
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="pos-submit-btn">Create Invoice</button>
                    <button type="button" class="btn btn-outline" id="pos-clear-all-btn">Clear All</button>
                </div>
            </div>
        </div>

    </div><!-- /.pos-entry-col -->

    <!-- RIGHT: Invoice Preview -->
    <div class="pos-preview-card">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Invoice Preview</h3></div>
            <div class="card-body">

                <div id="pos-invoice-lines">
                    <div class="invoice-empty-state" id="pos-empty-state">
                        No items added yet. Select a product and serial number, then click "Add to Invoice".
                    </div>
                </div>

                <div class="invoice-totals" id="pos-totals-block" style="display:none;">
                    <div class="invoice-total-row">
                        <span>Subtotal (excl. VAT)</span>
                        <span id="pos-subtotal">R 0.00</span>
                    </div>
                    <div class="invoice-total-row">
                        <span>VAT (15%)</span>
                        <span id="pos-vat-total">R 0.00</span>
                    </div>
                    <div class="invoice-total-row grand-total">
                        <span>Grand Total (incl. VAT)</span>
                        <span id="pos-grand-total">R 0.00</span>
                    </div>
                </div>

                <div id="pos-customer-preview" style="margin-top:1rem;display:none;">
                    <hr style="margin:.75rem 0;border:none;border-top:1px solid var(--color-border);">
                    <div class="invoice-section-label">Bill To</div>
                    <div id="pos-customer-preview-text" class="invoice-section-value" style="font-size:.85rem;"></div>
                </div>

            </div>
        </div>
    </div><!-- /.pos-preview-card -->

</div><!-- /.pos-layout -->

<!-- Hidden form that actually submits -->
<form method="POST" action="" id="pos-submit-form" style="display:none;">
    <input type="hidden" name="action" value="create_invoice">
    <input type="hidden" name="customer_name"      id="hf-cust-name">
    <input type="hidden" name="customer_email"     id="hf-cust-email">
    <input type="hidden" name="customer_phone"     id="hf-cust-phone">
    <input type="hidden" name="customer_address"   id="hf-cust-address">
    <input type="hidden" name="customer_id_number" id="hf-cust-idnum">
    <input type="hidden" name="contact_type"       id="hf-contact-type" value="individual">
    <input type="hidden" name="company_name"       id="hf-company-name">
    <input type="hidden" name="vat_no"             id="hf-vat-no">
    <input type="hidden" name="channel"            id="hf-channel">
    <input type="hidden" name="payment_method"     id="hf-payment-method">
    <input type="hidden" name="notes"              id="hf-notes">
    <input type="hidden" name="discount_pct"       id="hf-discount-pct">
    <div id="hf-items-container"></div>
</form>

<script>
(function () {
    var BASE_URL = '<?= BASE_URL ?>';
    var VAT_RATE = 0.15;

    // Invoice line state
    var invoiceLines = [];

    // ---- DOM refs ----
    var productSel       = document.getElementById('pos-product-select');
    var qtyInput         = document.getElementById('pos-qty');
    var serialInput      = document.getElementById('pos-serial');
    var serialGroup      = document.getElementById('pos-serial-group');
    var serialsMulti     = document.getElementById('pos-serials-multi');
    var serialsMultiGrp  = document.getElementById('pos-serials-multi-group');
    var serialsMultiFB   = document.getElementById('pos-serials-multi-feedback');
    var priceInput       = document.getElementById('pos-unit-price');
    var serialFB         = document.getElementById('pos-serial-feedback');
    var linesDiv         = document.getElementById('pos-invoice-lines');
    var emptyState   = document.getElementById('pos-empty-state');
    var totalsBlock  = document.getElementById('pos-totals-block');
    var custPreview  = document.getElementById('pos-customer-preview');
    var custPreviewT = document.getElementById('pos-customer-preview-text');

    var custNameIn   = document.getElementById('pos-cust-name');
    var custEmailIn  = document.getElementById('pos-cust-email');
    var custPhoneIn  = document.getElementById('pos-cust-phone');
    var custAddrIn   = document.getElementById('pos-cust-address');

    // Individual / Business type toggle
    window.setCustType = function(type) {
        document.getElementById('hf-contact-type').value = type;
        var bizFields = document.getElementById('pos-business-fields');
        var nameLabel = document.getElementById('pos-name-label');
        var btnInd = document.getElementById('pos-type-individual');
        var btnBiz = document.getElementById('pos-type-business');
        if (type === 'business') {
            bizFields.style.display = 'block';
            nameLabel.innerHTML = 'Contact Person <span class="required">*</span>';
            btnInd.className = btnInd.className.replace('btn-primary','btn-outline');
            btnBiz.className = btnBiz.className.replace('btn-outline','btn-primary');
        } else {
            bizFields.style.display = 'none';
            nameLabel.innerHTML = 'Full Name <span class="required">*</span>';
            btnInd.className = btnInd.className.replace('btn-outline','btn-primary');
            btnBiz.className = btnBiz.className.replace('btn-primary','btn-outline');
        }
    };
    // Init from prefill
    setCustType('<?= $prefillType === 'business' ? 'business' : 'individual' ?>');
    var custIdIn     = document.getElementById('pos-cust-idnum');
    var channelSel   = document.getElementById('pos-channel');
    var notesIn      = document.getElementById('pos-notes');

    var discountInput      = document.getElementById('pos-discount');
    var discountNotice     = document.getElementById('pos-discount-notice');
    var discountPctDisplay = document.getElementById('pos-discount-pct-display');

    var addBtn         = document.getElementById('pos-add-btn');
    var clearItemBtn   = document.getElementById('pos-clear-item-btn');
    var clearAllBtn    = document.getElementById('pos-clear-all-btn');
    var submitBtn      = document.getElementById('pos-submit-btn');
    var submitForm     = document.getElementById('pos-submit-form');
    var paymentGroup   = document.getElementById('pos-payment-group');
    var selectedPayment = ''; // cashier must explicitly choose

    // ---- Payment method toggle ----
    if (paymentGroup) {
        paymentGroup.addEventListener('click', function (e) {
            var btn = e.target.closest('.payment-method-btn');
            if (!btn) return;
            paymentGroup.querySelectorAll('.payment-method-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            selectedPayment = btn.getAttribute('data-method');
            var hint = document.getElementById('pos-payment-required-hint');
            if (hint) hint.style.display = 'none';
        });
    }

    // ---- Discount: cap at 10% and show commission notice ----
    discountInput.addEventListener('input', function () {
        var pct = parseFloat(discountInput.value) || 0;
        if (pct > 10) { discountInput.value = 10; pct = 10; }
        if (pct < 0)  { discountInput.value = 0;  pct = 0; }
        if (pct > 0) {
            discountPctDisplay.textContent = pct % 1 === 0 ? pct : pct.toFixed(1);
            discountNotice.style.display = '';
        } else {
            discountNotice.style.display = 'none';
        }
        renderInvoice();
    });

    // ---- Quick Pick card click ----
    var quickPickCards = document.querySelectorAll('.pos-quickpick-card');
    quickPickCards.forEach(function (card) {
        card.addEventListener('click', function () {
            var pid   = card.getAttribute('data-product-id');
            var price = card.getAttribute('data-product-price');

            // Highlight active card
            quickPickCards.forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');

            // Select product in the dropdown
            for (var i = 0; i < productSel.options.length; i++) {
                if (productSel.options[i].value === pid) {
                    productSel.selectedIndex = i;
                    break;
                }
            }

            // Set price
            priceInput.value = price;

            // Reset serial
            serialInput.value    = '';
            serialsMulti.value   = '';
            serialFB.textContent = '';
            serialFB.style.color = '';

            // Reset qty to 1 and sync serial mode
            qtyInput.value = '1';
            syncSerialMode();

            // Focus serial input
            serialInput.focus();
        });
    });

    // ---- Qty / serialised: switch serial inputs appropriately ----
    function isProductSerialised() {
        var opt = productSel.options[productSel.selectedIndex];
        if (!opt || !opt.value) return true; // default: show serial
        return opt.getAttribute('data-serialised') !== '0';
    }

    function isProductService() {
        var opt = productSel.options[productSel.selectedIndex];
        if (!opt || !opt.value) return false;
        return opt.getAttribute('data-ptype') === 'service';
    }

    function syncSerialMode() {
        var qty        = parseInt(qtyInput.value, 10) || 1;
        var serialised = isProductSerialised();
        var service    = isProductService();

        if (service || !serialised) {
            // Service or non-serialised product: hide all serial inputs
            serialGroup.style.display     = 'none';
            serialsMultiGrp.style.display = 'none';
            serialsMultiFB.textContent    = '';
        } else if (qty > 1) {
            serialGroup.style.display      = 'none';
            serialsMultiGrp.style.display  = '';
            updateMultiSerialFeedback();
        } else {
            serialGroup.style.display      = '';
            serialsMultiGrp.style.display  = 'none';
        }
    }

    function updateMultiSerialFeedback() {
        var lines  = serialsMulti.value.split('\n').map(function(l){ return l.trim(); }).filter(function(l){ return l !== ''; });
        var unique = Array.from(new Set(lines));
        var qty    = parseInt(qtyInput.value, 10) || 1;
        var msg    = '';
        if (unique.length === 0) {
            msg = 'No serials entered — will add as non-serialised (qty ' + qty + ').';
        } else {
            msg = unique.length + ' serial(s) entered';
            if (unique.length !== lines.length) msg += ' (' + (lines.length - unique.length) + ' duplicate(s) will be ignored)';
            msg += '. Each will be added as a separate line.';
        }
        serialsMultiFB.textContent = msg;
    }

    qtyInput.addEventListener('input', syncSerialMode);
    qtyInput.addEventListener('change', syncSerialMode);
    serialsMulti.addEventListener('input', updateMultiSerialFeedback);

    // ---- Product select: pre-fill price + sync serial mode ----
    productSel.addEventListener('change', function () {
        var opt = productSel.options[productSel.selectedIndex];
        var price = opt ? opt.getAttribute('data-price') : null;
        if (price) priceInput.value = price;
        serialInput.value     = '';
        serialsMulti.value    = '';
        serialFB.textContent  = '';
        serialFB.style.color  = '';
        serialsMultiFB.textContent = '';
        qtyInput.value = '1';
        syncSerialMode();
        if (isProductSerialised()) serialInput.focus();
        else priceInput.focus();
    });

    // ---- Serial AJAX validation ----
    var serialTimer = null;
    serialInput.addEventListener('blur', function () { validateSerial(); });
    serialInput.addEventListener('input', function () {
        clearTimeout(serialTimer);
        serialTimer = setTimeout(validateSerial, 600);
    });

    function validateSerial() {
        var sn = serialInput.value.trim();
        if (!sn) return;
        serialFB.textContent = 'Checking…';
        serialFB.style.color = 'var(--color-muted)';
        fetch(BASE_URL + '/pos/index.php?ajax=validate_serial&serial_no=' + encodeURIComponent(sn))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.found) {
                    serialFB.textContent = '✓ ' + data.product_name + ' — ' + data.warehouse_name;
                    serialFB.style.color = '#166534';
                    // Auto-select product
                    for (var i = 0; i < productSel.options.length; i++) {
                        if (parseInt(productSel.options[i].value) === parseInt(data.product_id || 0)) {
                            productSel.selectedIndex = i;
                        }
                    }
                    // Auto-fill price incl. VAT if not already set by user
                    if (parseFloat(priceInput.value) === 0) {
                        priceInput.value = (parseFloat(data.selling_price) * (1 + VAT_RATE)).toFixed(2);
                    }
                } else {
                    serialFB.textContent = '✗ ' + (data.message || 'Serial not found or not in stock.');
                    serialFB.style.color = '#991B1B';
                }
            })
            .catch(function () {
                serialFB.textContent = 'Could not validate serial.';
                serialFB.style.color = '#92400E';
            });
    }

    // ---- Add item to invoice ----
    addBtn.addEventListener('click', function () {
        var opt      = productSel.options[productSel.selectedIndex];
        var prodId   = parseInt(productSel.value);
        var price    = parseFloat(priceInput.value) || 0;
        var qty      = Math.max(1, parseInt(qtyInput.value, 10) || 1);
        var prodName = opt ? (opt.getAttribute('data-name') || opt.text) : '';
        var sku      = opt ? (opt.getAttribute('data-sku') || '') : '';

        if (!prodId) { alert('Please select a product.'); return; }

        if (qty === 1 && isProductSerialised()) {
            // Serialised single item mode
            var sn = serialInput.value.trim();
            if (!sn) { alert('Please enter a serial number for this product.'); serialInput.focus(); return; }

            for (var i = 0; i < invoiceLines.length; i++) {
                if (invoiceLines[i].serial_no === sn) {
                    alert('Serial number "' + sn + '" is already on this invoice.');
                    return;
                }
            }
            invoiceLines.push({ product_id: prodId, product_name: prodName, sku: sku, serial_no: sn, unit_price: price, qty: 1 });

        } else if (qty === 1 && !isProductSerialised()) {
            // Non-serialised single item: add with qty 1, no serial
            invoiceLines.push({ product_id: prodId, product_name: prodName, sku: sku, serial_no: '', unit_price: price, qty: 1 });

        } else {
            // Multi-serial / non-serialised mode
            var rawLines = serialsMulti.value.split('\n').map(function(l){ return l.trim(); }).filter(function(l){ return l !== ''; });
            var uniqueSns = Array.from(new Set(rawLines));

            if (uniqueSns.length > 0) {
                // Add one line per serial
                var added = 0;
                uniqueSns.forEach(function(sn) {
                    for (var i = 0; i < invoiceLines.length; i++) {
                        if (invoiceLines[i].serial_no === sn) {
                            alert('Serial number "' + sn + '" is already on this invoice. Skipped.');
                            return;
                        }
                    }
                    invoiceLines.push({ product_id: prodId, product_name: prodName, sku: sku, serial_no: sn, unit_price: price, qty: 1 });
                    added++;
                });
            } else {
                // Non-serialised: add a single line with qty
                invoiceLines.push({ product_id: prodId, product_name: prodName, sku: sku, serial_no: '', unit_price: price, qty: qty });
            }
        }

        renderInvoice();

        // Reset item entry
        productSel.selectedIndex  = 0;
        qtyInput.value            = '1';
        serialInput.value         = '';
        serialsMulti.value        = '';
        priceInput.value          = '0.00';
        serialFB.textContent      = '';
        serialFB.style.color      = '';
        serialsMultiFB.textContent= '';
        syncSerialMode();
        serialInput.focus();
    });

    clearItemBtn.addEventListener('click', function () {
        productSel.selectedIndex  = 0;
        qtyInput.value            = '1';
        serialInput.value         = '';
        serialsMulti.value        = '';
        priceInput.value          = '0.00';
        serialFB.textContent      = '';
        serialFB.style.color      = '';
        serialsMultiFB.textContent= '';
        syncSerialMode();
    });

    clearAllBtn.addEventListener('click', function () {
        if (invoiceLines.length > 0 && !confirm('Clear all invoice items and customer data?')) return;
        invoiceLines = [];
        renderInvoice();
        productSel.selectedIndex  = 0;
        qtyInput.value            = '1';
        serialInput.value         = '';
        serialsMulti.value        = '';
        priceInput.value          = '0.00';
        serialFB.textContent      = '';
        serialFB.style.color      = '';
        serialsMultiFB.textContent= '';
        syncSerialMode();
        custNameIn.value  = '';
        custEmailIn.value = '';
        custPhoneIn.value = '';
        custAddrIn.value  = '';
        custIdIn.value    = '';
        notesIn.value     = '';
        updateCustomerPreview();
    });

    // ---- Render invoice preview ----
    function fmt(n) {
        return 'R ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function renderInvoice() {
        // Remove old line elements
        var oldLines = linesDiv.querySelectorAll('.invoice-line-item');
        oldLines.forEach(function (el) { el.remove(); });

        if (invoiceLines.length === 0) {
            emptyState.style.display  = '';
            totalsBlock.style.display = 'none';
            return;
        }

        emptyState.style.display  = 'none';
        totalsBlock.style.display = '';

        var subtotal = 0;

        invoiceLines.forEach(function (line, idx) {
            var qty       = line.qty || 1;
            // unit_price is stored incl. VAT — derive excl. for subtotal accumulation
            var lineIncl  = Math.round(line.unit_price * qty * 100) / 100;
            var lineEx    = Math.round(lineIncl / (1 + VAT_RATE) * 100) / 100;
            var vatAmt    = Math.round((lineIncl - lineEx) * 100) / 100;
            subtotal += lineEx; // subtotal tracks excl. VAT for discount + VAT calc

            var subLabel = line.serial_no
                ? 'SN: ' + escHtml(line.serial_no)
                : 'Qty: ' + qty + (qty > 1 ? ' units' : ' unit') + ' (no serial)';
            var priceLabel = qty > 1
                ? fmt(line.unit_price) + ' × ' + qty
                : fmt(line.unit_price);

            var div = document.createElement('div');
            div.className = 'invoice-line-item';
            div.innerHTML =
                '<div style="flex:1;min-width:0;">' +
                    '<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + escHtml(line.product_name) + '">' + escHtml(line.product_name) + '</div>' +
                    '<div style="color:var(--color-muted);font-size:.78rem;">' + subLabel + '</div>' +
                '</div>' +
                '<div style="white-space:nowrap;text-align:right;min-width:90px;">' +
                    '<div>' + priceLabel + '</div>' +
                    '<div style="font-weight:700;">' + fmt(lineIncl) + '</div>' +
                '</div>' +
                '<button type="button" data-idx="' + idx + '" class="btn btn-sm btn-danger pos-remove-line" style="flex-shrink:0;padding:.2rem .45rem;font-size:.75rem;">×</button>';
            linesDiv.appendChild(div);
        });

        var discountPct    = Math.min(10, Math.max(0, parseFloat(discountInput.value) || 0));
        var discountAmount = Math.round(subtotal * discountPct / 100 * 100) / 100;
        var discountedSub  = Math.round((subtotal - discountAmount) * 100) / 100;
        // Derive VAT from (total - excl) so subtotal + vat always equals total exactly
        var grandTotal     = Math.round(discountedSub * (1 + VAT_RATE) * 100) / 100;
        var vatTotal       = Math.round((grandTotal - discountedSub) * 100) / 100;

        document.getElementById('pos-subtotal').textContent   = fmt(subtotal);

        // Discount row (inserted between subtotal and VAT rows)
        var discountRow = document.getElementById('pos-discount-row');
        if (discountAmount > 0) {
            if (!discountRow) {
                discountRow = document.createElement('div');
                discountRow.id = 'pos-discount-row';
                discountRow.className = 'invoice-total-row';
                discountRow.style.color = '#b91c1c';
                var vatRow = document.getElementById('pos-vat-total').parentNode;
                totalsBlock.insertBefore(discountRow, vatRow);
            }
            discountRow.innerHTML = '<span>Discount (' + discountPct + '%)</span><span>- ' + fmt(discountAmount) + '</span>';
        } else if (discountRow) {
            discountRow.remove();
        }

        document.getElementById('pos-vat-total').textContent  = fmt(vatTotal);
        document.getElementById('pos-grand-total').textContent= fmt(grandTotal);
    }

    // ---- Remove line item ----
    linesDiv.addEventListener('click', function (e) {
        var btn = e.target.closest('.pos-remove-line');
        if (!btn) return;
        var idx = parseInt(btn.getAttribute('data-idx'));
        invoiceLines.splice(idx, 1);
        renderInvoice();
    });

    // ---- Customer preview ----
    [custNameIn, custEmailIn, custPhoneIn, custAddrIn, custIdIn].forEach(function (el) {
        el.addEventListener('input', updateCustomerPreview);
    });

    function updateCustomerPreview() {
        var name    = custNameIn.value.trim();
        var email   = custEmailIn.value.trim();
        var phone   = custPhoneIn.value.trim();
        var address = custAddrIn.value.trim();
        var idnum   = custIdIn.value.trim();
        if (!name && !email && !phone) {
            custPreview.style.display = 'none';
            return;
        }
        custPreview.style.display = '';
        var lines = [];
        if (name)    lines.push('<strong>' + escHtml(name) + '</strong>');
        if (email)   lines.push(escHtml(email));
        if (phone)   lines.push(escHtml(phone));
        if (address) lines.push(escHtml(address));
        if (idnum)   lines.push('ID: ' + escHtml(idnum));
        custPreviewT.innerHTML = lines.join('<br>');
    }

    // ---- Submit ----
    submitBtn.addEventListener('click', function () {
        if (invoiceLines.length === 0) { alert('Please add at least one item to the invoice.'); return; }
        if (!custNameIn.value.trim()) { alert('Customer name is required.'); custNameIn.focus(); return; }
        if (!selectedPayment) {
            var hint = document.getElementById('pos-payment-required-hint');
            if (hint) hint.style.display = '';
            alert('Please select a payment method.');
            document.getElementById('pos-payment-group').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        document.getElementById('hf-cust-name').value    = custNameIn.value.trim();
        document.getElementById('hf-cust-email').value   = custEmailIn.value.trim();
        document.getElementById('hf-cust-phone').value   = custPhoneIn.value.trim();
        document.getElementById('hf-cust-address').value = custAddrIn.value.trim();
        document.getElementById('hf-cust-idnum').value   = custIdIn.value.trim();
        document.getElementById('hf-contact-type').value = document.getElementById('hf-contact-type').value; // already set by setCustType
        document.getElementById('hf-company-name').value = (document.getElementById('pos-cust-company') || {value:''}).value.trim();
        document.getElementById('hf-vat-no').value       = (document.getElementById('pos-cust-vat') || {value:''}).value.trim();
        document.getElementById('hf-channel').value          = channelSel.value;
        document.getElementById('hf-payment-method').value   = selectedPayment;
        document.getElementById('hf-notes').value            = notesIn.value.trim();
        document.getElementById('hf-discount-pct').value     = Math.min(10, Math.max(0, parseFloat(discountInput.value) || 0));

        var container = document.getElementById('hf-items-container');
        container.innerHTML = '';
        invoiceLines.forEach(function (line) {
            function hi(n, v) {
                var el = document.createElement('input');
                el.type  = 'hidden';
                el.name  = n;
                el.value = v;
                container.appendChild(el);
            }
            hi('item_product_id[]', line.product_id);
            hi('item_serial_no[]',  line.serial_no || '');
            // unit_price is stored incl. VAT in JS — backend expects excl. VAT
            hi('item_unit_price[]', (line.unit_price / (1 + VAT_RATE)).toFixed(4));
            hi('item_qty[]',        line.qty || 1);
        });

        submitForm.submit();
    });

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Init customer preview
    updateCustomerPreview();

    // ---- Smart scan bar ----
    (function () {
        var scanInput   = document.getElementById('pos-scan-input');
        var scanDrop    = document.getElementById('pos-scan-dropdown');
        var scanSpinner = document.getElementById('pos-scan-spinner');
        if (!scanInput) return;

        var scanDebounce = null;

        function showSpinner(on) {
            scanSpinner.style.display = on ? '' : 'none';
        }

        function hideDropdown() {
            scanDrop.style.display = 'none';
            scanDrop.innerHTML = '';
        }

        function showDropdown(results, emptyMsg) {
            scanDrop.innerHTML = '';
            if (!results || results.length === 0) {
                if (emptyMsg) {
                    var noItem = document.createElement('div');
                    noItem.style.cssText = 'padding:.65rem 1rem;color:var(--color-muted);font-size:.875rem;';
                    noItem.textContent = emptyMsg;
                    scanDrop.appendChild(noItem);
                    scanDrop.style.display = '';
                } else {
                    hideDropdown();
                }
                return;
            }
            results.forEach(function (prod) {
                var item = document.createElement('div');
                item.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid var(--color-border);font-size:.875rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem;transition:background .12s;';
                var typeBadge = '';
                if (prod.product_type === 'service') {
                    typeBadge = ' <span style="font-size:.73rem;background:#EDE9FE;color:#5B21B6;padding:.1rem .35rem;border-radius:4px;margin-left:.25rem;">Service</span>';
                } else if (!prod.is_serialised) {
                    typeBadge = ' <span style="font-size:.73rem;background:#FEF3C7;color:#92400E;padding:.1rem .35rem;border-radius:4px;margin-left:.25rem;">Bulk</span>';
                }
                item.innerHTML =
                    '<span>' +
                        '<strong>' + escHtml(prod.name) + '</strong>' +
                        (prod.sku ? ' <span style="color:var(--color-muted);font-size:.78rem;">(' + escHtml(prod.sku) + ')</span>' : '') +
                        typeBadge +
                    '</span>' +
                    '<span style="white-space:nowrap;font-weight:600;color:var(--color-primary);">R ' + parseFloat(prod.selling_price_incl).toFixed(2) + '</span>';
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // prevent blur-before-click
                    selectProductFromResult(prod);
                    scanInput.value = '';
                    hideDropdown();
                });
                item.addEventListener('mouseover', function () { item.style.background = '#f9fafb'; });
                item.addEventListener('mouseout',  function () { item.style.background = ''; });
                scanDrop.appendChild(item);
            });
            scanDrop.style.display = '';
        }

        function selectProductFromResult(prod) {
            var prodId = parseInt(prod.id || prod.product_id || 0);
            for (var i = 0; i < productSel.options.length; i++) {
                if (parseInt(productSel.options[i].value) === prodId) {
                    productSel.selectedIndex = i;
                    // Ensure data-ptype is set from AJAX result (for products added after page load)
                    if (prod.product_type) {
                        productSel.options[i].setAttribute('data-ptype', prod.product_type);
                    }
                    break;
                }
            }
            priceInput.value = parseFloat(prod.selling_price_incl || 0).toFixed(2);
            serialInput.value         = '';
            serialsMulti.value        = '';
            serialFB.textContent      = '';
            serialFB.style.color      = '';
            serialsMultiFB.textContent= '';
            qtyInput.value = '1';
            syncSerialMode();
            var isService = (prod.product_type === 'service');
            if (!isService && prod.is_serialised) {
                serialInput.focus();
            } else {
                priceInput.focus();
            }
        }

        function searchProducts(q) {
            showSpinner(true);
            fetch(BASE_URL + '/pos/index.php?ajax=search_product&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    showSpinner(false);
                    if (results.length === 1) {
                        // Single match — auto-select silently
                        selectProductFromResult(results[0]);
                        scanInput.value = '';
                        hideDropdown();
                    } else {
                        showDropdown(results, results.length === 0 ? 'No products found for "' + escHtml(q) + '".' : null);
                    }
                })
                .catch(function () { showSpinner(false); hideDropdown(); });
        }

        function doScanSearch(q) {
            if (!q) return;
            showSpinner(true);
            hideDropdown();
            // 1. Try serial lookup (barcode scanner scenario)
            fetch(BASE_URL + '/pos/index.php?ajax=validate_serial&serial_no=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.found) {
                        showSpinner(false);
                        var prod = {
                            id:                 data.product_id,
                            selling_price_incl: data.selling_price_incl,
                            is_serialised:      data.is_serialised
                        };
                        selectProductFromResult(prod);
                        // Fill serial field
                        serialInput.value    = data.serial_no;
                        serialFB.textContent = '✓ ' + data.product_name + ' — ' + data.warehouse_name;
                        serialFB.style.color = '#166534';
                        scanInput.value = '';
                        hideDropdown();
                    } else {
                        // Not a serial — fall back to product search
                        searchProducts(q);
                    }
                })
                .catch(function () { searchProducts(q); });
        }

        // Typing: debounced serial-first search (falls back to product name/SKU)
        scanInput.addEventListener('input', function () {
            var q = scanInput.value.trim();
            clearTimeout(scanDebounce);
            if (!q) { hideDropdown(); showSpinner(false); return; }
            scanDebounce = setTimeout(function () { doScanSearch(q); }, 350);
        });

        // Enter: immediate serial-first lookup (barcode scanner sends Enter)
        scanInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(scanDebounce);
                var q = scanInput.value.trim();
                if (q) doScanSearch(q);
            } else if (e.key === 'Escape') {
                clearTimeout(scanDebounce);
                hideDropdown();
                scanInput.value = '';
            } else if (e.key === 'ArrowDown') {
                // Move focus into the dropdown if open
                var first = scanDrop.querySelector('div');
                if (first) { e.preventDefault(); first.focus(); }
            }
        });

        // Close dropdown when clicking elsewhere
        document.addEventListener('click', function (e) {
            if (!scanInput.contains(e.target) && !scanDrop.contains(e.target)) {
                hideDropdown();
            }
        });
    }());

    // ---- Account No auto-generation ----
    (function () {
        var nameIn  = custNameIn;
        var accIn   = custIdIn;
        if (!nameIn || !accIn) return;
        var _accTimer = null;

        function genAccNo(name) {
            if (!name || name.trim().length < 1) return;
            // Only auto-fill if field is empty or looks auto-generated (matches pattern)
            var current = accIn.value.trim();
            if (current !== '' && !/^[A-Z]{2,4}-\d{6}-\d{3}$/.test(current)) return;
            fetch(BASE_URL + '/pos/index.php?ajax=gen_account_no&name=' + encodeURIComponent(name))
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.account_no) accIn.value = d.account_no; })
                .catch(function () {});
        }

        nameIn.addEventListener('input', function () {
            clearTimeout(_accTimer);
            var n = this.value.trim();
            if (n.length >= 2) {
                _accTimer = setTimeout(function () { genAccNo(n); }, 450);
            }
        });
    }());

    // ---- Customer search autocomplete ----
    (function () {
        var searchIn = document.getElementById('pos-cust-search');
        var dropdown = document.getElementById('pos-cust-dropdown');
        if (!searchIn || !dropdown) return;

        var _searchTimer = null;

        function fillCustomer(c) {
            custNameIn.value  = c.name  || '';
            custEmailIn.value = c.email || '';
            custPhoneIn.value = c.phone || '';
            custAddrIn.value  = c.address || '';
            custIdIn.value    = c.id_number || '';
            // Fill business fields
            var type = c.contact_type || 'individual';
            setCustType(type);
            if (type === 'business') {
                var companyIn = document.getElementById('pos-cust-company');
                var vatIn     = document.getElementById('pos-cust-vat');
                if (companyIn) companyIn.value = c.company_name || '';
                if (vatIn)     vatIn.value     = c.vat_no       || '';
            }
            searchIn.value    = '';
            dropdown.style.display = 'none';
            updateCustomerPreview();
        }

        function addNewCustomerFromSearch(name) {
            custNameIn.value  = name;
            custEmailIn.value = '';
            custPhoneIn.value = '';
            custAddrIn.value  = '';
            custIdIn.value    = '';
            setCustType('individual');
            searchIn.value = '';
            dropdown.style.display = 'none';
            custEmailIn.focus();
            updateCustomerPreview();
        }

        function showCustDropdown(results, q) {
            dropdown.innerHTML = '';
            if (!results.length) {
                var noItem = document.createElement('div');
                noItem.style.cssText = 'padding:.5rem 1rem;color:#9CA3AF;font-size:.85rem;border-bottom:1px solid #F3F4F6;';
                noItem.textContent = 'No customers found for "' + q + '"';
                dropdown.appendChild(noItem);
            } else {
                results.forEach(function (c) {
                    var div = document.createElement('div');
                    div.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:.9rem;';
                    var sub = [];
                    if (c.company_name) sub.push(c.company_name);
                    if (c.email)        sub.push(c.email);
                    if (c.phone)        sub.push(c.phone);
                    div.innerHTML = '<strong>' + c.name.replace(/</g,'&lt;') + '</strong>'
                        + (sub.length ? '<br><span style="color:#6B7280;font-size:.82rem;">' + sub.map(function(s){return s.replace(/</g,'&lt;');}).join(' · ') + '</span>' : '');
                    div.addEventListener('mousedown', function (e) { e.preventDefault(); fillCustomer(c); });
                    div.addEventListener('mouseover',  function () { div.style.background = '#F0F9FF'; });
                    div.addEventListener('mouseout',   function () { div.style.background = ''; });
                    dropdown.appendChild(div);
                });
            }
            // Always show "Add as new customer" at the bottom
            var addDiv = document.createElement('div');
            addDiv.style.cssText = 'padding:.55rem 1rem;cursor:pointer;font-size:.875rem;color:#1A4DB3;font-weight:600;background:#EFF6FF;border-radius:0 0 8px 8px;';
            addDiv.innerHTML = '➕ Add <em>"' + q.replace(/</g,'&lt;') + '"</em> as new customer';
            addDiv.addEventListener('mousedown', function (e) { e.preventDefault(); addNewCustomerFromSearch(q); });
            addDiv.addEventListener('mouseover',  function () { addDiv.style.background = '#DBEAFE'; });
            addDiv.addEventListener('mouseout',   function () { addDiv.style.background = '#EFF6FF'; });
            dropdown.appendChild(addDiv);
            dropdown.style.display = 'block';
        }

        searchIn.addEventListener('input', function () {
            clearTimeout(_searchTimer);
            var q = this.value.trim();
            if (q.length < 2) { dropdown.style.display = 'none'; return; }
            _searchTimer = setTimeout(function () {
                fetch(BASE_URL + '/pos/index.php?ajax=search_customer&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (results) { showCustDropdown(results, q); })
                    .catch(function () { dropdown.style.display = 'none'; });
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (!searchIn.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }());

    // ---- CRM prefill ----
    <?php if ($crmPrefillCustomer): ?>
    (function () {
        updateCustomerPreview();
    }());
    <?php endif; ?>

    // ---- Quote prefill ----
    <?php if ($prefillQuote && !empty($prefillQuoteItems)): ?>
    (function () {
        var prefillItems = <?= json_encode(array_map(function($qi) {
            return [
                'product_id'   => $qi['product_id'],
                'product_name' => $qi['product_name'] ?? ($qi['description'] ?? 'Item'),
                'sku'          => $qi['product_sku'] ?? '',
                'serial_no'    => '',
                'unit_price'   => (float)$qi['unit_price'],
                'qty'          => (int)$qi['qty'],
            ];
        }, $prefillQuoteItems)) ?>;
        prefillItems.forEach(function (item) { invoiceLines.push(item); });
        renderInvoice();

        // Prefill customer fields
        custNameIn.value  = <?= json_encode($prefillQuote['customer_name']) ?>;
        custEmailIn.value = <?= json_encode($prefillQuote['customer_email']) ?>;
        custPhoneIn.value = <?= json_encode($prefillQuote['customer_phone']) ?>;
        custAddrIn.value  = <?= json_encode($prefillQuote['customer_address'] ?? '') ?>;
        custIdIn.value    = <?= json_encode($prefillQuote['customer_id_number'] ?? '') ?>;
        // Prefill channel
        var ch = <?= json_encode($prefillQuote['channel']) ?>;
        for (var i = 0; i < channelSel.options.length; i++) {
            if (channelSel.options[i].value === ch) { channelSel.selectedIndex = i; break; }
        }
        updateCustomerPreview();
    }());
    <?php endif; ?>

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
