<?php
// ============================================================
// Blackview SA Portal — POS: Printable Invoice
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';

requireLogin();

$pdo = getDB();

$invoiceId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId === 0) {
    setFlash('error', 'Invalid invoice ID.');
    header('Location: ' . BASE_URL . '/pos/index.php');
    exit;
}

// Load invoice header
$invStmt = $pdo->prepare(
    "SELECT inv.*, c.name AS customer_name, c.email AS customer_email,
            c.phone AS customer_phone, c.address AS customer_address,
            c.id_number AS customer_id_number,
            u.name AS created_by_name
     FROM invoices inv
     LEFT JOIN customers c ON c.id = inv.customer_id
     LEFT JOIN users u ON u.id = inv.created_by
     WHERE inv.id = :id LIMIT 1"
);
$invStmt->execute([':id' => $invoiceId]);
$invoice = $invStmt->fetch();

if (!$invoice) {
    setFlash('error', 'Invoice not found.');
    header('Location: ' . BASE_URL . '/pos/index.php');
    exit;
}

$pageTitle = 'Invoice';

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
$lineItems = $pdo->prepare(
    "SELECT ii.*, p.name AS product_name, p.sku AS product_sku, w.name AS warehouse_name
     FROM invoice_items ii
     JOIN products p ON p.id = ii.product_id
     LEFT JOIN warehouses w ON w.id = ii.warehouse_id
     WHERE ii.invoice_id = :inv
     ORDER BY ii.id ASC"
);
$lineItems->execute([':inv' => $invoiceId]);
$lineItems = $lineItems->fetchAll();

// Load payments
$paymentsStmt = $pdo->prepare(
    "SELECT ip.*, u.name AS recorded_by, cn.credit_note_no
     FROM invoice_payments ip
     LEFT JOIN users u ON u.id = ip.created_by
     LEFT JOIN credit_notes cn ON cn.id = ip.credit_note_id
     WHERE ip.invoice_id = :inv
     ORDER BY ip.created_at ASC"
);
$paymentsStmt->execute([':inv' => $invoiceId]);
$payments = $paymentsStmt->fetchAll();

$totalPaid  = array_sum(array_column($payments, 'amount'));
$balance    = round((float)$invoice['total'] - $totalPaid, 2);
$isPaid     = $balance <= 0.00;

// Load credit notes
$creditNotes = $pdo->prepare(
    "SELECT * FROM credit_notes WHERE invoice_id = :inv ORDER BY created_at ASC"
);
$creditNotes->execute([':inv' => $invoiceId]);
$creditNotes = $creditNotes->fetchAll();

// Handle record-payment POST
$payErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    $payAmt    = round((float)($_POST['pay_amount']   ?? 0), 2);
    $payMethod = trim($_POST['pay_method']   ?? 'cash');
    $payRef    = trim($_POST['pay_reference'] ?? '');
    $payNotes  = trim($_POST['pay_notes']    ?? '');
    $payCnId   = isset($_POST['credit_note_id']) && is_numeric($_POST['credit_note_id'])
                    ? (int)$_POST['credit_note_id'] : null;

    $validMethods = ['cash', 'eft', 'card', 'credit_note'];
    try {
        $vmFetch = $pdo->query("SELECT code FROM payment_methods WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($vmFetch)) { $validMethods = $vmFetch; $validMethods[] = 'credit_note'; }
    } catch (Throwable $e) { /* payment_methods table not yet created */ }
    if (!in_array($payMethod, $validMethods, true)) $payErrors[] = 'Invalid payment method.';
    if ($payAmt <= 0) $payErrors[] = 'Payment amount must be greater than zero.';
    if ($payAmt > $balance + 0.001) $payErrors[] = 'Payment amount exceeds outstanding balance (R ' . number_format($balance, 2) . ').';
    if ($payMethod === 'credit_note' && !$payCnId) $payErrors[] = 'Missing credit note reference.';

    if (empty($payErrors)) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO invoice_payments (invoice_id, amount, payment_method, reference, credit_note_id, notes, created_by, created_at)
                 VALUES (:inv, :amt, :pm, :ref, :cnid, :notes, :uid, NOW())"
            )->execute([
                ':inv'   => $invoiceId,
                ':amt'   => $payAmt,
                ':pm'    => $payMethod,
                ':ref'   => $payRef,
                ':cnid'  => $payCnId,
                ':notes' => $payNotes,
                ':uid'   => $_SESSION['user_id'],
            ]);

            // If applied via credit note: mark the CN as applied
            if ($payMethod === 'credit_note' && $payCnId) {
                $pdo->prepare("UPDATE credit_notes SET status = 'applied' WHERE id = :id AND status = 'open'")
                    ->execute([':id' => $payCnId]);
            }

            logAudit($pdo, 'record_payment', 'invoices', $invoiceId,
                "Recorded payment R $payAmt via $payMethod for invoice {$invoice['invoice_no']}");

            $pdo->commit();
            setFlash('success', 'Payment of R ' . number_format($payAmt, 2) . ' recorded.');
            header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $payErrors[] = 'Database error: ' . $e->getMessage();
        }
    }
    // Reload payments after failed attempt
    $paymentsStmt->execute([':inv' => $invoiceId]);
    $payments   = $paymentsStmt->fetchAll();
    $totalPaid  = array_sum(array_column($payments, 'amount'));
    $balance    = round((float)$invoice['total'] - $totalPaid, 2);
    $isPaid     = $balance <= 0.00;
}

// Handle send email POST
$emailResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_email') {
    $toEmail     = trim($_POST['email_to']       ?? '');
    $toName      = trim($_POST['email_to_name']  ?? '');
    $personalMsg = trim($_POST['personal_note']  ?? '');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $emailResult = ['ok' => false, 'error' => 'Please enter a valid email address.'];
    } else {
        $personalNoteHtml = $personalMsg !== ''
            ? '<p style="background:#fffbeb;border-left:4px solid #d97706;padding:10px 14px;border-radius:4px;font-style:italic;color:#374151;">'
              . nl2br(htmlspecialchars($personalMsg)) . '</p>'
            : '';

        $vars = [
            'customer_name'  => $invoice['customer_name'] ?? 'Valued Customer',
            'invoice_no'     => $invoice['invoice_no'],
            'invoice_date'   => date('d F Y', strtotime($invoice['created_at'])),
            'total'          => number_format((float)$invoice['total'], 2),
            'balance'        => number_format(max(0, $balance), 2),
            'balance_color'  => $isPaid ? '#16a34a' : '#d97706',
            'payment_method' => $invoice['payment_method'] ?? 'N/A',
            'personal_note'  => $personalNoteHtml,
            'company_name'   => $coName,
            'company_email'  => $coEmail,
            'company_phone'  => $coPhone,
        ];

        $emailResult = sendDocumentEmail($pdo, 'invoice', $vars, $toEmail, $toName);
        if ($emailResult['ok']) {
            logAudit($pdo, 'email_invoice', 'invoices', $invoiceId,
                "Invoice {$invoice['invoice_no']} emailed to $toEmail");
            setFlash('success', "Invoice emailed to $toEmail successfully.");
            header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
            exit;
        }
    }
}

// VAT display mode toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_vat_display') {
    $newMode = ($_POST['vat_mode'] ?? 'incl') === 'excl' ? 'excl' : 'incl';
    try {
        $pdo->prepare("UPDATE invoices SET vat_display_mode = :m WHERE id = :id")
            ->execute([':m' => $newMode, ':id' => $invoiceId]);
    } catch (Throwable $e) { /* column may not exist yet if migration 006 not run */ }
    header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
    exit;
}

$vatMode = $invoice['vat_display_mode'] ?? 'incl';

// Can this user edit this invoice?
$_invUser = currentUser();
// Fetch can_edit_invoices from DB (not stored in session)
$_invUserPerms = false;
try {
    $permRow = $pdo->prepare("SELECT can_edit_invoices FROM users WHERE id=:id LIMIT 1");
    $permRow->execute([':id' => $_SESSION['user_id']]);
    $_invUserPerms = (bool)$permRow->fetchColumn();
} catch (Throwable $e) { /* column not added yet */ }
$canEditInv = $_invUserPerms || isAdmin() || !empty($invoice['edit_unlocked']);

// Handle unlock request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_invoice') {
    if (isAdmin()) {
        try {
            $pdo->prepare("UPDATE invoices SET edit_unlocked=1, edit_unlocked_by=:uid, edit_unlocked_at=NOW() WHERE id=:id")
                ->execute([':uid' => $_SESSION['user_id'], ':id' => $invoiceId]);
            logAudit($pdo, 'unlock_invoice', 'invoices', $invoiceId,
                "Invoice {$invoice['invoice_no']} unlocked for editing by {$_invUser['name']}");
            setFlash('success', 'Invoice unlocked — any user can now edit it once.');
        } catch (Throwable $e) {
            setFlash('error', 'Could not unlock invoice: ' . $e->getMessage());
        }
        header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
        exit;
    }
}

// Reload edit_unlocked after potential POST
try {
    $unlockRow = $pdo->prepare("SELECT edit_unlocked FROM invoices WHERE id = :id LIMIT 1");
    $unlockRow->execute([':id' => $invoiceId]);
    $unlockRow = $unlockRow->fetchColumn();
    $canEditInv = !empty($_invUser['can_edit_invoices']) || isAdmin() || !empty($unlockRow);
} catch (Throwable $e) { /* column not yet added */ }

$channelLabels = [
    'takealot' => 'Takealot',
    'makro'    => 'Makro',
    'instore'  => 'In-Store',
    'email'    => 'Email',
    'other'    => 'Other',
];

// ============================================================
// Handle finalise draft invoice
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalise_invoice') {
    require_once __DIR__ . '/../config/invoice_helpers.php';

    // Reload status (may not be loaded yet at this point in execution)
    $curStatusRow = $pdo->prepare("SELECT COALESCE(status,'active') AS status FROM invoices WHERE id=:id LIMIT 1");
    $curStatusRow->execute([':id' => $invoiceId]);
    $curStatus = $curStatusRow->fetchColumn();

    if ($curStatus !== 'draft') {
        setFlash('error', 'Only draft invoices can be finalised.');
        header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
        exit;
    }
    try {
        $pdo->beginTransaction();

        // Generate real INV number
        $mth = date('Ym');
        $nQ  = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE :p");
        $nQ->execute([':p' => "INV-{$mth}-%"]);
        $seq      = (int)$nQ->fetchColumn() + 1;
        $newInvNo = sprintf('INV-%s-%04d', $mth, $seq);

        // Update invoice header
        try {
            $pdo->prepare(
                "UPDATE invoices SET status='active', invoice_no=:no, finalised_at=NOW(), finalised_by=:uid WHERE id=:id"
            )->execute([':no' => $newInvNo, ':uid' => $_SESSION['user_id'] ?? null, ':id' => $invoiceId]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE invoices SET status='active', invoice_no=:no WHERE id=:id")
                ->execute([':no' => $newInvNo, ':id' => $invoiceId]);
        }

        // Deduct stock for all physical line items
        finaliseDraftStock($pdo, $invoiceId, $newInvNo, $invoice['channel'] ?? 'instore');

        $pdo->commit();
        logAudit($pdo, 'finalise_invoice', 'invoices', $invoiceId,
            "Draft {$invoice['invoice_no']} finalised as $newInvNo");
        setFlash('success', "Invoice $newInvNo has been finalised.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        setFlash('error', 'Failed to finalise: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
    exit;
}

// ============================================================
// Handle delete draft (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_draft') {
    if (!isAdmin()) {
        setFlash('error', 'Only admins can delete drafts.');
        header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
        exit;
    }
    $pdo->prepare("DELETE FROM invoices WHERE id=:id AND COALESCE(status,'active')='draft'")
        ->execute([':id' => $invoiceId]);
    setFlash('success', "Draft invoice deleted.");
    header('Location: ' . BASE_URL . '/invoices/create.php');
    exit;
}

// Handle void invoice (superuser only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_invoice') {
    if (!isSuperuser()) {
        setFlash('error', 'Only superusers can void invoices.');
        header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
        exit;
    }
    $voidReason = trim($_POST['void_reason'] ?? '');
    if ($voidReason === '') {
        setFlash('error', 'A reason is required to void an invoice.');
        header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
        exit;
    }
    try {
        $pdo->beginTransaction();
        // Mark invoice as voided
        $pdo->prepare(
            "UPDATE invoices SET status='voided', voided_by=:uid, voided_at=NOW(), void_reason=:reason WHERE id=:id"
        )->execute([':uid' => $_SESSION['user_id'], ':reason' => $voidReason, ':id' => $invoiceId]);

        // Fetch every line item: product, warehouse, qty, serial, product_type
        $lineItems = $pdo->prepare(
            "SELECT ii.product_id, ii.warehouse_id, ii.qty,
                    COALESCE(ii.serial_no,'') AS serial_no,
                    COALESCE(si.warehouse_id, ii.warehouse_id) AS resolved_wh,
                    COALESCE(p.product_type, 'physical') AS product_type
             FROM invoice_items ii
             LEFT JOIN stock_items si ON si.serial_no = ii.serial_no
                                     AND ii.serial_no IS NOT NULL AND ii.serial_no != ''
             LEFT JOIN products p ON p.id = ii.product_id
             WHERE ii.invoice_id = :id"
        );
        $lineItems->execute([':id' => $invoiceId]);

        $stmtRestoreSerial = $pdo->prepare(
            "UPDATE stock_items SET status = 'in_stock' WHERE serial_no = :sn"
        );
        $stmtRestoreQty = $pdo->prepare(
            "UPDATE inventory_stock SET qty = qty + :qty
             WHERE product_id = :pid AND warehouse_id = :wh"
        );
        $stmtInsMov = $pdo->prepare(
            "INSERT INTO stock_movements
                 (product_id, from_warehouse_id, to_warehouse_id, qty, moved_by, invoice_no, channel, notes, moved_at)
             VALUES (:prod, NULL, :wh, :qty, :uid, :inv_no, 'received', :notes, NOW())"
        );
        $stmtInsMovSerial = $pdo->prepare(
            "INSERT INTO movement_serials (movement_id, serial_no) VALUES (:mid, :sn)"
        );

        $uid   = $_SESSION['user_id'] ?? null;
        $invNo = $invoice['invoice_no'];

        foreach ($lineItems->fetchAll() as $li) {
            $pid       = $li['product_id'];
            $wid       = $li['resolved_wh'];     // warehouse resolved from stock_items if serial, else invoice_items
            $qty       = max(1, (int)$li['qty']);
            $sn        = $li['serial_no'];
            $isService = ($li['product_type'] === 'service');

            // Service products have no stock — nothing to restore
            if ($isService) continue;

            // 1. Restore serialised stock_items row
            if ($sn !== '') {
                $stmtRestoreSerial->execute([':sn' => $sn]);
            }

            // 2. Restore inventory aggregate + write a stock movement (shows in report)
            if ($pid && $wid) {
                $stmtRestoreQty->execute([':qty' => $qty, ':pid' => $pid, ':wh' => $wid]);

                $stmtInsMov->execute([
                    ':prod'   => $pid,
                    ':wh'     => $wid,
                    ':qty'    => $qty,
                    ':uid'    => $uid,
                    ':inv_no' => $invNo,
                    ':notes'  => "Void return — Invoice $invNo",
                ]);

                // 3. Link serial to the movement so the report shows the serial number
                if ($sn !== '') {
                    $stmtInsMovSerial->execute([
                        ':mid' => (int)$pdo->lastInsertId(),
                        ':sn'  => $sn,
                    ]);
                }
            }
        }

        $pdo->commit();
        logAudit($pdo, 'void_invoice', 'invoices', $invoiceId,
            "Invoice {$invoice['invoice_no']} voided by {$_SESSION['user_name']}. Reason: $voidReason");
        setFlash('success', "Invoice {$invoice['invoice_no']} has been voided.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        setFlash('error', 'Could not void invoice: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/pos/invoice.php?id=' . $invoiceId);
    exit;
}

// Handle delete_payment_link AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payment_link') {
    header('Content-Type: application/json');
    try {
        $linkId = isset($_POST['link_id']) && is_numeric($_POST['link_id']) ? (int)$_POST['link_id'] : 0;
        if ($linkId === 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid link ID.']);
            exit;
        }
        // Confirm link belongs to this invoice and is not already paid
        $chk = $pdo->prepare("SELECT id, status FROM payment_links WHERE id=:lid AND invoice_id=:inv LIMIT 1");
        $chk->execute([':lid' => $linkId, ':inv' => $invoiceId]);
        $chkRow = $chk->fetch();
        if (!$chkRow) {
            echo json_encode(['ok' => false, 'error' => 'Link not found.']);
            exit;
        }
        if ($chkRow['status'] === 'paid') {
            echo json_encode(['ok' => false, 'error' => 'Cannot delete a paid link.']);
            exit;
        }
        // Soft-cancel rather than DELETE (avoids needing DELETE privilege on shared hosting)
        $pdo->prepare("UPDATE payment_links SET status='cancelled' WHERE id=:id")->execute([':id' => $linkId]);
        logAudit($pdo, 'delete_payment_link', 'invoices', $invoiceId,
            "Payment link #$linkId cancelled by {$_SESSION['user_name']}");
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle log-print AJAX (called silently by JS print function)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_print') {
    logAudit($pdo, 'print_invoice', 'invoices', $invoiceId,
        "Invoice {$invoice['invoice_no']} printed / saved as PDF");
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

// Handle generate_payment_link AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_payment_link') {
    header('Content-Type: application/json');
    try {
        if (!file_exists(__DIR__ . '/../payment/helpers.php')) {
            echo json_encode(['ok' => false, 'error' => 'payment/helpers.php not found — please upload the payment/ folder to the server.']);
            exit;
        }
        require_once __DIR__ . '/../payment/helpers.php';

        // Check invoice status directly (isVoided is not yet defined at this point in the file)
        // Wrapped in try/catch in case the status column hasn't been added yet on this server
        try {
            $statusCheck = $pdo->prepare("SELECT COALESCE(status,'active') FROM invoices WHERE id=:id LIMIT 1");
            $statusCheck->execute([':id' => $invoiceId]);
            if ($statusCheck->fetchColumn() === 'voided') {
                echo json_encode(['ok' => false, 'error' => 'Cannot generate a link for a voided invoice.']);
                exit;
            }
        } catch (Throwable $e) {
            // status column not yet on this server — no voided invoices possible, continue
        }

        // Reload latest balance
        $freshPayments = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=:id");
        $freshPayments->execute([':id' => $invoiceId]);
        $freshBalance = round((float)$invoice['total'] - (float)$freshPayments->fetchColumn(), 2);

        if ($freshBalance <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invoice is already fully paid.']);
            exit;
        }

        $provider = trim($_POST['provider'] ?? '');
        if (!in_array($provider, ['yoco', 'payfast'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid payment provider.']);
            exit;
        }

        $settings = getSettings($pdo);

        // Check provider is enabled
        if ($provider === 'yoco' && empty($settings['yoco_enabled'])) {
            echo json_encode(['ok' => false, 'error' => 'Yoco is not enabled. Configure it in Admin → Settings → Payment Gateways.']);
            exit;
        }
        if ($provider === 'payfast' && empty($settings['payfast_enabled'])) {
            echo json_encode(['ok' => false, 'error' => 'PayFast is not enabled. Configure it in Admin → Settings → Payment Gateways.']);
            exit;
        }

        $token      = generatePaymentToken();
        $expiresAt  = date('Y-m-d H:i:s', strtotime('+7 days'));
        $paymentUrl = '';
        $externalId = null;

        if ($provider === 'yoco') {
            $successUrl = rtrim($settings['payment_success_url'] ?? BASE_URL . '/payment/success.php', '/');
            $cancelUrl  = rtrim($settings['payment_cancel_url']  ?? BASE_URL . '/payment/cancel.php', '/');
            $failureUrl = $cancelUrl;

            $result = createYocoCheckout(
                $settings,
                $freshBalance,
                $successUrl . '?token=' . $token,
                $cancelUrl  . '?token=' . $token,
                $failureUrl . '?token=' . $token,
                ['invoiceId' => $invoiceId, 'invoiceNo' => $invoice['invoice_no'], 'token' => $token]
            );

            if (!$result['ok']) {
                echo json_encode(['ok' => false, 'error' => $result['error']]);
                exit;
            }

            $paymentUrl = $result['redirect_url'];
            $externalId = $result['external_id'] ?? null;

        } elseif ($provider === 'payfast') {
            $paymentUrl = BASE_URL . '/payment/pay.php?token=' . $token;
            $externalId = null;
        }

        // Check migration_018 has been run
        try {
            $pdo->query("SELECT 1 FROM payment_links LIMIT 1");
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'The payment_links table does not exist. Please run migration_018 in phpMyAdmin first.']);
            exit;
        }

        $pdo->prepare(
            "INSERT INTO payment_links (invoice_id, provider, external_id, amount, payment_url, token, created_by, expires_at)
             VALUES (:inv, :prov, :eid, :amt, :url, :token, :uid, :exp)"
        )->execute([
            ':inv'   => $invoiceId,
            ':prov'  => $provider,
            ':eid'   => $externalId,
            ':amt'   => $freshBalance,
            ':url'   => $paymentUrl,
            ':token' => $token,
            ':uid'   => $_SESSION['user_id'],
            ':exp'   => $expiresAt,
        ]);

        logAudit($pdo, 'generate_payment_link', 'invoices', $invoiceId,
            "Payment link generated via $provider for R $freshBalance on invoice {$invoice['invoice_no']} by {$_SESSION['user_name']}");

        echo json_encode([
            'ok'          => true,
            'payment_url' => $paymentUrl,
            'amount'      => $freshBalance,
            'provider'   => $provider,
            'expires_at' => $expiresAt,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Load document history from audit_log
$_docHistoryActions = ['email_invoice','print_invoice','edit_invoice','unlock_invoice'];
$_dhi = implode(',', array_fill(0, count($_docHistoryActions), '?'));
$_dhStmt = $pdo->prepare(
    "SELECT al.action, al.details, al.created_at, u.name AS user_name
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.entity = 'invoices' AND al.entity_id = ? AND al.action IN ($_dhi)
     ORDER BY al.created_at ASC"
);
$_dhStmt->execute(array_merge([$invoiceId], $_docHistoryActions));
$_docHistory = $_dhStmt->fetchAll();

$_docActionMeta = [
    'email_invoice'          => ['label' => 'Emailed',              'icon' => '✉️',  'color' => '#0369a1'],
    'print_invoice'          => ['label' => 'Printed',              'icon' => '🖨️', 'color' => '#374151'],
    'edit_invoice'           => ['label' => 'Edited',               'icon' => '✏️',  'color' => '#7c3aed'],
    'unlock_invoice'         => ['label' => 'Unlocked for editing', 'icon' => '🔓',  'color' => '#d97706'],
    'void_invoice'           => ['label' => 'Voided',               'icon' => '🚫',  'color' => '#dc2626'],
    'generate_payment_link'  => ['label' => 'Payment link sent',    'icon' => '🔗',  'color' => '#0891b2'],
    'online_payment'         => ['label' => 'Online payment',       'icon' => '💳',  'color' => '#059669'],
];

// Reload invoice status after potential void/finalise
try {
    $refreshRow = $pdo->prepare(
        "SELECT status, voided_by, voided_at, void_reason,
                invoice_no, due_date, invoice_date, finalised_at, finalised_by
         FROM invoices WHERE id=:id LIMIT 1"
    );
    $refreshRow->execute([':id' => $invoiceId]);
    $invStatus = $refreshRow->fetch(PDO::FETCH_ASSOC);
    // Refresh invoice_no if finalised (DRF → INV)
    if (!empty($invStatus['invoice_no'])) { $invoice['invoice_no'] = $invStatus['invoice_no']; }
    $isVoided = ($invStatus['status'] ?? 'active') === 'voided';
    $isDraft  = ($invStatus['status'] ?? 'active') === 'draft';
} catch (Throwable $e) { $invStatus = []; $isVoided = false; $isDraft = false; }

// Also reload the history actions list to include void + payment events
$_docHistoryActions = ['email_invoice','print_invoice','edit_invoice','unlock_invoice','void_invoice','generate_payment_link','online_payment'];

// Payment gateway availability
$_gwYocoEnabled    = !empty($_appSettings['yoco_enabled']);
$_gwPayFastEnabled = !empty($_appSettings['payfast_enabled']);
$_gwAnyEnabled     = $_gwYocoEnabled || $_gwPayFastEnabled;

// Load active payment methods for the Record Payment dropdown
$_invoicePayMethods = [
    ['code' => 'cash', 'name' => 'Cash'],
    ['code' => 'eft',  'name' => 'EFT'],
    ['code' => 'card', 'name' => 'Card'],
];
try {
    $_pmRows = $pdo->query(
        "SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
    )->fetchAll();
    if (!empty($_pmRows)) $_invoicePayMethods = $_pmRows;
} catch (Throwable $e) { /* payment_methods table not yet created */ }

// Load existing active payment links for this invoice
$_existingLinks = [];
try {
    $elStmt = $pdo->prepare(
        "SELECT id, provider, amount, status, payment_url, created_at, expires_at, paid_at
         FROM payment_links WHERE invoice_id = :id AND status != 'cancelled' ORDER BY created_at DESC LIMIT 10"
    );
    $elStmt->execute([':id' => $invoiceId]);
    $_existingLinks = $elStmt->fetchAll();
} catch (Throwable $e) { /* table not yet created */ }

require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($payErrors as $pe): ?>
<div class="alert alert-error"><?= htmlspecialchars($pe) ?></div>
<?php endforeach; ?>

<!-- Balance banner -->
<?php if ($isPaid): ?>
<div class="alert alert-success" style="display:flex;align-items:center;gap:.75rem;font-size:1rem;margin-bottom:1rem;">
    <span style="font-size:1.4rem;">✓</span>
    <div><strong>Fully Paid</strong> — Balance: <strong>R 0.00</strong></div>
</div>
<?php elseif ($isVoided): ?>
<div class="alert alert-error" style="font-size:1rem;margin-bottom:1rem;">
    <span style="font-size:1.3rem;">🚫</span> <strong>Invoice Voided</strong> — no payments can be recorded.
</div>
<?php else: ?>
<div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;font-size:1rem;margin-bottom:1rem;">
    <div><span style="font-size:1.3rem;">⚠</span> <strong>Outstanding Balance: R <?= number_format($balance, 2) ?></strong></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('paymentPanel').style.display='block';this.style.display='none'">
            Record Payment
        </button>
        <?php if ($_gwAnyEnabled): ?>
        <button type="button" class="btn btn-sm" id="plTriggerBtn"
                style="background:#0891b2;color:#fff;border:none;cursor:pointer;border-radius:6px;padding:.35rem .85rem;font-size:.82rem;font-weight:600;"
                onclick="openPaymentLinkModal()">
            🔗 Send Payment Link
        </button>
        <?php endif; ?>
    </div>
</div>
<!-- Inline payment form -->
<div id="paymentPanel" style="display:none;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:1rem;margin-bottom:1rem;">
    <strong style="display:block;margin-bottom:.75rem;">Record Payment</strong>
    <form method="POST" action="">
        <input type="hidden" name="action" value="record_payment">
        <div class="form-row">
            <div class="form-group form-group--quarter">
                <label class="form-label">Amount (R) <span class="required">*</span></label>
                <input type="number" name="pay_amount" class="form-control" step="0.01" min="0.01"
                    max="<?= $balance ?>" value="<?= $balance ?>" required>
            </div>
            <div class="form-group form-group--quarter">
                <label class="form-label">Method <span class="required">*</span></label>
                <select name="pay_method" class="form-control form-select" required>
                    <?php foreach ($_invoicePayMethods as $_pm): ?>
                    <option value="<?= htmlspecialchars($_pm['code']) ?>">
                        <?= htmlspecialchars($_pm['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--half">
                <label class="form-label">Reference</label>
                <input type="text" name="pay_reference" class="form-control" placeholder="EFT ref, receipt no…">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="pay_notes" class="form-control" placeholder="Optional">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Payment</button>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('paymentPanel').style.display='none'">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ============================================================
     PAYMENT LINKS — existing links card (shown when any exist)
     ============================================================ -->
<?php if (!empty($_existingLinks)): ?>
<div class="card" id="paymentLinksCard" style="margin-bottom:1rem;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title" style="margin:0;">🔗 Payment Links</h3>
        <?php if (!$isPaid && !$isVoided && $_gwAnyEnabled): ?>
        <button type="button" class="btn btn-sm"
                style="background:#0891b2;color:#fff;border:none;cursor:pointer;border-radius:6px;padding:.35rem .85rem;font-size:.82rem;font-weight:600;"
                onclick="openPaymentLinkModal()">+ New Link</button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table" style="font-size:.875rem;">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Expires</th>
                    <th>Link</th>
                </tr>
            </thead>
            <tbody class="payment-links-tbody">
            <?php foreach ($_existingLinks as $_pl):
                $_plStatus = $_pl['status'];
                $_plColors = ['pending'=>['#d97706','#fffbeb'],'paid'=>['#16a34a','#f0fdf4'],'cancelled'=>['#9ca3af','#f9fafb'],'expired'=>['#9ca3af','#f9fafb']];
                [$_plFg, $_plBg] = $_plColors[$_plStatus] ?? ['#374151','#f9fafb'];
            ?>
            <tr>
                <td><strong><?= ucfirst(htmlspecialchars($_pl['provider'])) ?></strong></td>
                <td>R <?= number_format((float)$_pl['amount'], 2) ?></td>
                <td>
                    <span style="padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;color:<?= $_plFg ?>;background:<?= $_plBg ?>;">
                        <?= ucfirst($_plStatus) ?>
                    </span>
                </td>
                <td style="color:var(--color-muted);white-space:nowrap;"><?= date('d M Y H:i', strtotime($_pl['created_at'])) ?></td>
                <td style="color:var(--color-muted);font-size:.8rem;">
                    <?= $_pl['expires_at'] ? date('d M Y', strtotime($_pl['expires_at'])) : '—' ?>
                </td>
                <td style="white-space:nowrap;display:flex;gap:6px;align-items:center;border:none;">
                    <?php if ($_plStatus === 'pending'): ?>
                    <button type="button" class="btn btn-sm btn-outline"
                            style="font-size:.75rem;padding:.25rem .6rem;"
                            data-copy-url="<?= htmlspecialchars($_pl['payment_url'], ENT_QUOTES) ?>"
                            onclick="copyToClipboard(this.dataset.copyUrl, this)">Copy</button>
                    <button type="button" class="btn btn-sm"
                            style="font-size:.75rem;padding:.25rem .6rem;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;"
                            onclick="deletePaymentLink(<?= (int)$_pl['id'] ?>, this)">Delete</button>
                    <?php elseif ($_plStatus === 'paid'): ?>
                    <span style="font-size:.8rem;color:#16a34a;font-weight:600;">✓ Paid <?= $_pl['paid_at'] ? date('d M Y', strtotime($_pl['paid_at'])) : '' ?></span>
                    <?php else: ?>
                    <span style="font-size:.8rem;color:#9ca3af;">—</span>
                    <button type="button" class="btn btn-sm"
                            style="font-size:.75rem;padding:.25rem .6rem;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;"
                            onclick="deletePaymentLink(<?= (int)$_pl['id'] ?>, this)">Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     PAYMENT LINK MODAL
     ============================================================ -->
<?php if (!$isPaid && !$isVoided && $_gwAnyEnabled): ?>
<div id="plOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;
            align-items:center;justify-content:center;padding:1rem;"
     onclick="if(event.target===this)closePLModal()">
    <div style="background:#fff;border-radius:14px;width:100%;max-width:500px;
                box-shadow:0 12px 48px rgba(0,0,0,.25);overflow:hidden;animation:fb-slide-in .2s ease-out;">

        <!-- Header -->
        <div style="background:#0c4a6e;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#fff;font-weight:700;font-size:.95rem;">🔗 Generate Payment Link</span>
            <button onclick="closePLModal()" style="background:none;border:none;color:#7dd3fc;cursor:pointer;font-size:1.2rem;padding:.2rem .4rem;border-radius:4px;">&#x2715;</button>
        </div>

        <!-- Body -->
        <div style="padding:1.25rem;">
            <p style="font-size:.875rem;color:#4b5563;margin-bottom:1rem;">
                Generate a link to send to your customer. They can click it to pay
                <strong>R <?= number_format($balance, 2) ?></strong> online.
                The link expires in 7 days.
            </p>

            <!-- Provider selection -->
            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.5rem;">
                    Payment Gateway
                </label>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;" id="plProviderPills">
                    <?php if ($_gwYocoEnabled): ?>
                    <label id="plPillYoco" style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                  border:2px solid #0891b2;background:#e0f2fe;color:#0369a1;
                                  padding:.45rem .9rem;border-radius:20px;font-size:.875rem;font-weight:600;">
                        <input type="radio" name="pl_provider" value="yoco" style="display:none;" checked>
                        Yoco
                        <span style="font-size:.7rem;background:#0891b2;color:#fff;padding:1px 6px;border-radius:8px;">Recommended</span>
                    </label>
                    <?php endif; ?>
                    <?php if ($_gwPayFastEnabled): ?>
                    <label id="plPillPayfast" style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                  border:2px solid #d1d5db;background:#f9fafb;color:#374151;
                                  padding:.45rem .9rem;border-radius:20px;font-size:.875rem;font-weight:600;">
                        <input type="radio" name="pl_provider" value="payfast" style="display:none;">
                        PayFast
                    </label>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Result box (hidden until link generated) -->
            <div id="plResult" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;
                                      border-radius:8px;padding:.85rem 1rem;margin-bottom:1rem;">
                <p style="font-size:.8rem;color:#0369a1;font-weight:600;margin-bottom:.4rem;">Payment Link Ready</p>
                <div style="display:flex;gap:.5rem;align-items:center;">
                    <input id="plResultUrl" type="text" readonly
                           style="flex:1;font-size:.78rem;padding:.45rem .6rem;border:1px solid #bae6fd;
                                  border-radius:6px;background:#fff;color:#0c4a6e;outline:none;"
                           value="">
                    <button type="button" class="btn btn-sm"
                            style="background:#0891b2;color:#fff;border:none;cursor:pointer;
                                   border-radius:6px;padding:.4rem .75rem;white-space:nowrap;font-size:.82rem;"
                            onclick="copyLinkResult()">Copy</button>
                </div>
                <p id="plEmailBtn" style="margin-top:.6rem;">
                    <button type="button" class="btn btn-sm btn-outline"
                            style="font-size:.8rem;width:100%;"
                            onclick="emailPaymentLink()">✉️ Email this link to customer</button>
                </p>
            </div>

            <!-- Error box -->
            <div id="plError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;
                                     border-radius:7px;padding:.65rem .9rem;font-size:.85rem;color:#dc2626;margin-bottom:1rem;"></div>

            <!-- Actions -->
            <div style="display:flex;gap:.75rem;">
                <button type="button" id="plGenerateBtn"
                        style="flex:1;padding:.6rem 1rem;background:#0891b2;color:#fff;border:none;
                               border-radius:7px;font-weight:600;cursor:pointer;font-size:.9rem;transition:background .12s;"
                        onmouseover="this.style.background='#0e7490'" onmouseout="this.style.background='#0891b2'"
                        onclick="generatePaymentLink()">
                    Generate Link
                </button>
                <button type="button" onclick="closePLModal()"
                        style="padding:.6rem 1rem;background:transparent;color:#6b7280;
                               border:1px solid #e5e7eb;border-radius:7px;font-size:.9rem;cursor:pointer;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var _plInvoiceId = <?= $invoiceId ?>;
var _plCustomerEmail = <?= json_encode($invoice['customer_email'] ?? '') ?>;

function openPaymentLinkModal() {
    document.getElementById('plOverlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Reset
    document.getElementById('plResult').style.display = 'none';
    document.getElementById('plError').style.display  = 'none';
    document.getElementById('plGenerateBtn').disabled = false;
    document.getElementById('plGenerateBtn').textContent = 'Generate Link';

    // Provider pill styling
    var pills = document.querySelectorAll('#plProviderPills label');
    pills.forEach(function(pill) {
        var radio = pill.querySelector('input[type=radio]');
        pill.addEventListener('click', function() {
            pills.forEach(function(p) {
                p.style.borderColor = '#d1d5db';
                p.style.background  = '#f9fafb';
                p.style.color       = '#374151';
            });
            pill.style.borderColor = '#0891b2';
            pill.style.background  = '#e0f2fe';
            pill.style.color       = '#0369a1';
        });
    });
}

function closePLModal() {
    document.getElementById('plOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

function generatePaymentLink() {
    var btn = document.getElementById('plGenerateBtn');
    var errEl = document.getElementById('plError');
    var resEl = document.getElementById('plResult');
    errEl.style.display = 'none';
    resEl.style.display = 'none';

    var providerRadio = document.querySelector('input[name="pl_provider"]:checked');
    var provider = providerRadio ? providerRadio.value : 'yoco';

    btn.disabled = true;
    btn.textContent = 'Generating…';

    var fd = new FormData();
    fd.append('action', 'generate_payment_link');
    fd.append('provider', provider);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                document.getElementById('plResultUrl').value = data.payment_url;
                resEl.style.display = 'block';
                btn.textContent = '✓ Generated';
            } else {
                errEl.textContent = data.error || 'Something went wrong.';
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Generate Link';
            }
        })
        .catch(function() {
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Generate Link';
        });
}

function copyLinkResult() {
    var url = document.getElementById('plResultUrl').value;
    copyToClipboard(url, event.target);
}

function copyToClipboard(text, btn) {
    var orig = btn.textContent;
    navigator.clipboard.writeText(text).then(function() {
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = orig; }, 2000);
    }).catch(function() {
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = orig; }, 2000);
    });
}

function deletePaymentLink(linkId, btn) {
    if (!confirm('Delete this payment link? The customer will no longer be able to use it.')) return;
    btn.disabled = true;
    btn.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'delete_payment_link');
    fd.append('link_id', linkId);
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                // Remove the whole table row
                var row = btn.closest('tr');
                if (row) {
                    row.style.transition = 'opacity .3s';
                    row.style.opacity = '0';
                    setTimeout(function() {
                        row.remove();
                        // Hide card if no rows left
                        var tbody = document.querySelector('.payment-links-tbody');
                        if (tbody && tbody.querySelectorAll('tr').length === 0) {
                            var card = document.getElementById('paymentLinksCard');
                            if (card) card.style.display = 'none';
                        }
                    }, 300);
                } else {
                    location.reload();
                }
            } else {
                alert(data.error || 'Could not delete link.');
                btn.disabled = false;
                btn.textContent = 'Delete';
            }
        })
        .catch(function() {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Delete';
        });
}

function emailPaymentLink() {
    var url = document.getElementById('plResultUrl').value;
    if (!url) return;
    var subject = encodeURIComponent('Payment Link — Invoice <?= htmlspecialchars($invoice['invoice_no']) ?>');
    var body    = encodeURIComponent(
        'Hi <?= htmlspecialchars($invoice['customer_name'] ?? 'there') ?>,\n\n' +
        'Please use the link below to pay your outstanding invoice:\n\n' +
        url + '\n\n' +
        'Amount due: R <?= number_format($balance, 2) ?>\n\n' +
        'Regards,\n<?= htmlspecialchars($coName) ?>'
    );
    var email = _plCustomerEmail || '';
    window.location.href = 'mailto:' + email + '?subject=' + subject + '&body=' + body;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePLModal();
});
</script>
<?php endif; ?>

<!-- DRAFT banner -->
<?php if ($isDraft): ?>
<div style="background:#FEF3C7;border:2px solid #F59E0B;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
    <span style="font-size:1.5rem;line-height:1;">📝</span>
    <div style="flex:1;">
        <strong style="color:#92400E;font-size:1rem;">This invoice is a DRAFT — not yet finalised</strong><br>
        <span style="font-size:.88rem;color:#78350F;">Stock has not been deducted. Finalise to lock the invoice and allocate inventory.</span>
    </div>
    <div style="display:flex;gap:.6rem;flex-shrink:0;flex-wrap:wrap;">
        <form method="POST" action="" style="display:inline;">
            <input type="hidden" name="action" value="finalise_invoice">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Finalise this invoice? Stock will be deducted and the invoice number changed to INV-…')">
                ✅ Finalise Invoice
            </button>
        </form>
        <?php if (isAdmin()): ?>
        <form method="POST" action="" style="display:inline;">
            <input type="hidden" name="action" value="delete_draft">
            <button type="submit" class="btn btn-outline" style="color:#dc2626;border-color:#dc2626;"
                    onclick="return confirm('Permanently delete this draft? This cannot be undone.')">
                🗑️ Delete Draft
            </button>
        </form>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/invoices/create.php" class="btn btn-outline">← New Invoice</a>
    </div>
</div>
<?php endif; ?>

<!-- VOIDED banner -->
<?php if ($isVoided): ?>
<div style="background:#fee2e2;border:2px solid #dc2626;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
    <span style="font-size:1.5rem;line-height:1;">🚫</span>
    <div>
        <strong style="color:#dc2626;font-size:1rem;">This invoice has been VOIDED</strong><br>
        <span style="font-size:.88rem;color:#7f1d1d;">
            Voided on <?= date('d M Y H:i', strtotime($invStatus['voided_at'])) ?>
            <?php
            if (!empty($invStatus['voided_by'])) {
                $voidUserRow = $pdo->prepare("SELECT name FROM users WHERE id=:id LIMIT 1");
                $voidUserRow->execute([':id' => $invStatus['voided_by']]);
                $voidUserName = $voidUserRow->fetchColumn();
                echo ' by <strong>' . htmlspecialchars($voidUserName ?: '—') . '</strong>';
            }
            ?>
            <?php if (!empty($invStatus['void_reason'])): ?>
                &mdash; <em><?= htmlspecialchars($invStatus['void_reason']) ?></em>
            <?php endif; ?>
        </span>
    </div>
</div>
<?php endif; ?>

<!-- Actions bar (hidden during print) -->
<div class="invoice-footer-bar" style="margin-bottom:1.5rem;">
    <button type="button" class="btn btn-primary" onclick="printInvoice()">Print / Save PDF</button>

    <!-- VAT display mode toggle -->
    <form method="POST" action="" style="display:inline;">
        <input type="hidden" name="action"   value="set_vat_display">
        <input type="hidden" name="vat_mode" value="<?= $vatMode === 'incl' ? 'excl' : 'incl' ?>">
        <button type="submit" class="btn btn-outline"
                title="<?= $vatMode === 'incl' ? 'Switch to Excl. VAT format (corporate)' : 'Switch to Incl. VAT format (retail)' ?>">
            <?= $vatMode === 'incl' ? '📋 Show Excl. VAT' : '📋 Show Incl. VAT' ?>
        </button>
    </form>

    <?php if ($canEditInv): ?>
    <a href="<?= BASE_URL ?>/pos/invoice_edit.php?id=<?= $invoiceId ?>"
       class="btn btn-outline" style="color:#2563EB;border-color:#2563EB;">
        ✏️ Edit Invoice
    </a>
    <?php endif; ?>

    <?php if (isAdmin() && empty($unlockRow ?? $invoice['edit_unlocked'])): ?>
    <form method="POST" action="" style="display:inline;">
        <input type="hidden" name="action" value="unlock_invoice">
        <button type="submit" class="btn btn-outline" style="color:#D97706;border-color:#D97706;"
                onclick="return confirm('Unlock this invoice so any user can edit it once?')"
                title="Allow a one-time edit by any logged-in user">
            🔓 Unlock for Editing
        </button>
    </form>
    <?php elseif (!empty($unlockRow ?? $invoice['edit_unlocked'])): ?>
    <span class="btn btn-outline" style="color:#D97706;border-color:#D97706;cursor:default;opacity:.75;">
        🔓 Unlocked (edit pending)
    </span>
    <?php endif; ?>

    <?php if (!$isDraft): ?>
    <button type="button" class="btn btn-outline" style="color:#0369a1;border-color:#0369a1;"
            onclick="document.getElementById('emailPanel').style.display='block';this.style.display='none'">
        ✉️ Email to Client
    </button>
    <?php if (!$isVoided): ?>
    <a href="<?= BASE_URL ?>/pos/credit_note.php?invoice_id=<?= $invoiceId ?>" class="btn btn-outline" style="color:#DC2626;border-color:#DC2626;">
        Issue Credit Note
    </a>
    <?php endif; ?>
    <?php if (isSuperuser()): ?>
        <?php if (!$isVoided): ?>
        <button type="button" class="btn btn-outline" style="color:#dc2626;border-color:#dc2626;"
                onclick="document.getElementById('voidPanel').style.display='block';this.style.display='none'">
            🚫 Void Invoice
        </button>
        <?php else: ?>
        <span class="btn btn-outline" style="color:#dc2626;border-color:#dc2626;opacity:.5;cursor:default;">
            🚫 Already Voided
        </span>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; /* !$isDraft */ ?>

    <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-outline">← Back to POS</a>
    <a href="<?= BASE_URL ?>/invoices/create.php" class="btn btn-success">+ New Invoice</a>
</div>

<!-- Void Invoice panel -->
<?php if (isSuperuser() && !$isVoided): ?>
<div id="voidPanel" style="display:none;background:#fff5f5;border:1px solid #fca5a5;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem;">
    <strong style="display:block;margin-bottom:.5rem;color:#dc2626;">🚫 Void Invoice <?= htmlspecialchars($invoice['invoice_no']) ?></strong>
    <p style="font-size:.875rem;color:#374151;margin-bottom:.75rem;">
        This is irreversible. The invoice will be marked as voided and all serialised stock items will be returned to available inventory.
    </p>
    <form method="POST" action="" onsubmit="return confirm('Are you absolutely sure you want to void invoice <?= addslashes(htmlspecialchars($invoice['invoice_no'])) ?>? This cannot be undone.')">
        <input type="hidden" name="action" value="void_invoice">
        <div class="form-group">
            <label class="form-label">Reason for voiding <span class="required">*</span></label>
            <textarea name="void_reason" class="form-control" rows="2" required
                      placeholder="e.g. Duplicate invoice, customer cancelled order…"></textarea>
        </div>
        <div style="display:flex;gap:.75rem;">
            <button type="submit" class="btn btn-danger">Confirm Void</button>
            <button type="button" class="btn btn-outline"
                    onclick="document.getElementById('voidPanel').style.display='none';document.querySelector('button[onclick*=voidPanel]').style.display=''">
                Cancel
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Email to Client panel -->
<?php if ($emailResult && !$emailResult['ok']): ?>
<div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($emailResult['error']) ?></div>
<?php endif; ?>
<div id="emailPanel" style="display:<?= ($emailResult && !$emailResult['ok']) ? 'block' : 'none' ?>;
     background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem;">
    <strong style="display:block;margin-bottom:.75rem;color:#0369a1;">✉️ Email Invoice to Client</strong>
    <form method="POST" action="">
        <input type="hidden" name="action" value="send_email">
        <div class="form-row">
            <div class="form-group form-group--half">
                <label class="form-label">Recipient Email <span class="required">*</span></label>
                <input type="email" name="email_to" class="form-control" required
                       value="<?= htmlspecialchars($_POST['email_to'] ?? $invoice['customer_email'] ?? '') ?>"
                       placeholder="client@example.com">
            </div>
            <div class="form-group form-group--half">
                <label class="form-label">Recipient Name</label>
                <input type="text" name="email_to_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['email_to_name'] ?? $invoice['customer_name'] ?? '') ?>"
                       placeholder="Customer Name">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Personal Note <small style="color:var(--color-muted);">(optional — shown in email)</small></label>
            <textarea name="personal_note" class="form-control" rows="3"
                      placeholder="e.g. Hi John, please find your invoice attached. Thanks for your business!"><?= htmlspecialchars($_POST['personal_note'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Send Email</button>
            <button type="button" class="btn btn-outline"
                    onclick="document.getElementById('emailPanel').style.display='none';
                             document.querySelector('button[onclick*=emailPanel]').style.display=''">Cancel</button>
        </div>
    </form>
</div>

<!-- Printable Invoice Card -->
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
            <div class="invoice-title">TAX INVOICE</div>
            <div class="invoice-meta">
                <div><strong>Invoice No:</strong> <?= htmlspecialchars($invoice['invoice_no']) ?></div>
                <div><strong>Date:</strong> <?= date('d F Y', strtotime($invoice['created_at'])) ?></div>
                <div><strong>Time:</strong> <?= date('H:i', strtotime($invoice['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Bill To / Invoice Details -->
    <div class="invoice-bill-section">
        <div>
            <div class="invoice-section-label">Bill To</div>
            <div class="invoice-section-value">
                <strong><?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer') ?></strong><br>
                <?php if (!empty($invoice['customer_email'])): ?>
                    <?= htmlspecialchars($invoice['customer_email']) ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['customer_phone'])): ?>
                    <?= htmlspecialchars($invoice['customer_phone']) ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['customer_address'])): ?>
                    <?= nl2br(htmlspecialchars($invoice['customer_address'])) ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['customer_id_number'])): ?>
                    ID: <?= htmlspecialchars($invoice['customer_id_number']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div class="invoice-section-label">Invoice Details</div>
            <div class="invoice-section-value">
                <strong>Invoice #:</strong> <?= htmlspecialchars($invoice['invoice_no']) ?><br>
                <strong>Date:</strong> <?= date('d M Y', strtotime($invoice['created_at'])) ?><br>
                <strong>Channel:</strong> <?= htmlspecialchars($channelLabels[$invoice['channel']] ?? ucfirst($invoice['channel'])) ?><br>
                <strong>Payment:</strong> <?= htmlspecialchars(ucfirst($invoice['payment_method'] ?? 'Cash')) ?><br>
                <strong>Processed by:</strong> <?= htmlspecialchars($invoice['created_by_name'] ?? '—') ?>
            </div>
        </div>
    </div>

    <!-- Line Items Table -->
    <?php
    // VAT mode label for invoice header
    if ($vatMode === 'excl'): ?>
    <div style="font-size:.78rem;color:#6B7280;margin-bottom:.5rem;font-style:italic;">
        Prices shown <strong>exclusive of VAT</strong>. VAT of 15% is calculated in the totals below.
    </div>
    <?php else: ?>
    <div style="font-size:.78rem;color:#6B7280;margin-bottom:.5rem;font-style:italic;">
        Prices shown <strong>inclusive of 15% VAT</strong>.
    </div>
    <?php endif; ?>
    <table class="table" style="margin-bottom:1.5rem;width:100%;table-layout:fixed;">
        <colgroup>
            <col style="width:3%;">   <!-- # -->
            <col style="width:52%;">  <!-- Product + serial -->
            <col style="width:7%;">   <!-- Qty -->
            <col style="width:19%;">  <!-- Unit price -->
            <col style="width:19%;">  <!-- Total -->
        </colgroup>
        <thead>
            <tr>
                <th style="text-align:left;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">#</th>
                <th style="text-align:left;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Product</th>
                <th style="text-align:center;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Qty</th>
                <?php if ($vatMode === 'excl'): ?>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Unit Price (excl. VAT)</th>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Subtotal (excl. VAT)</th>
                <?php else: ?>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Unit Price (incl. VAT)</th>
                <th style="text-align:right;padding:.5rem;background:#F8FAFC;border-bottom:2px solid var(--color-border);">Total (incl. VAT)</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lineItems as $i => $li):
                $liQty   = isset($li['qty']) ? (int)$li['qty'] : 1;
                $liVatR  = (float)($li['vat_rate'] ?? 15);
                $liExcl  = (float)$li['unit_price'];
                $liIncl  = round($liExcl * (1 + $liVatR / 100), 2);
                $liSubEx = round($liExcl * $liQty, 2);
                $liTotIn = (float)$li['line_total'];
            ?>
            <tr style="border-bottom:1px solid var(--color-border);">
                <td style="padding:.5rem;vertical-align:top;"><?= $i + 1 ?></td>
                <td style="padding:.5rem;vertical-align:top;word-break:break-word;">
                    <strong><?= htmlspecialchars($li['product_name']) ?></strong><br>
                    <small style="color:var(--color-muted);"><?= htmlspecialchars($li['product_sku']) ?></small>
                    <?php if (!empty($li['serial_no'])): ?>
                    <br><small style="color:#4B5563;font-family:monospace;font-size:.8rem;">
                        S/N: <?= htmlspecialchars($li['serial_no']) ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td style="padding:.5rem;text-align:center;vertical-align:top;"><?= $liQty ?></td>
                <?php if ($vatMode === 'excl'): ?>
                <td style="padding:.5rem;text-align:right;vertical-align:top;">R <?= number_format($liExcl, 2) ?></td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;font-weight:600;">R <?= number_format($liSubEx, 2) ?></td>
                <?php else: ?>
                <td style="padding:.5rem;text-align:right;vertical-align:top;">R <?= number_format($liIncl, 2) ?></td>
                <td style="padding:.5rem;text-align:right;vertical-align:top;font-weight:600;">R <?= number_format($liTotIn, 2) ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($lineItems)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--color-muted);padding:1rem;">No items found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totals Block -->
    <div class="invoice-totals-block">
        <table class="invoice-totals-table">
            <tbody>
                <tr>
                    <td>Subtotal (excl. VAT)</td>
                    <?php
                        $origSub = (float)$invoice['subtotal'];
                        $discAmt = (float)($invoice['discount_amount'] ?? 0);
                        $discPct = (float)($invoice['discount_pct'] ?? 0);
                        if ($discAmt > 0) $origSub = $origSub + $discAmt;
                    ?>
                    <td>R <?= number_format($origSub, 2) ?></td>
                </tr>
                <?php if ($discPct > 0 && $discAmt > 0): ?>
                <tr style="color:#b91c1c;">
                    <td>Discount (<?= number_format($discPct, $discPct == floor($discPct) ? 0 : 1) ?>%)</td>
                    <td>- R <?= number_format($discAmt, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>VAT Amount (15%)</td>
                    <td>R <?= number_format((float)$invoice['vat_amount'], 2) ?></td>
                </tr>
                <tr class="invoice-grand-total">
                    <td><strong>Grand Total (incl. VAT)</strong></td>
                    <td><strong>R <?= number_format((float)$invoice['total'], 2) ?></strong></td>
                </tr>
                <?php if (!empty($payments)): ?>
                <tr style="border-top:1px solid #E5E7EB;">
                    <td style="color:#6B7280;padding-top:.5rem;">Amount Paid</td>
                    <td style="color:#16A34A;font-weight:700;padding-top:.5rem;">R <?= number_format($totalPaid, 2) ?></td>
                </tr>
                <tr style="background:<?= $isPaid ? '#F0FDF4' : '#FFFBEB' ?>;border-radius:4px;">
                    <td style="font-weight:800;font-size:1rem;padding:.4rem .5rem;">
                        <?= $isPaid ? '✓ PAID IN FULL' : 'Amount Due' ?>
                    </td>
                    <td style="font-weight:800;font-size:1rem;padding:.4rem .5rem;
                                color:<?= $isPaid ? '#15803D' : '#D97706' ?>;">
                        R <?= number_format(max(0, $balance), 2) ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer: notes + channel + thank-you -->
    <?php if (!empty($invoice['notes'])): ?>
    <div style="margin-top:1.5rem;">
        <div class="invoice-section-label">Notes</div>
        <div class="invoice-section-value" style="font-size:.875rem;"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <div class="invoice-thank-you">
        Thank you for your purchase! For support, please keep this invoice as proof of purchase.<br>
        <strong><?= htmlspecialchars($coName) ?></strong> &mdash; <?= htmlspecialchars($coTagline ?: 'Your trusted Blackview distributor.') ?>
    </div>

</div><!-- /.invoice-print-card -->

<!-- ============================================================
     PAYMENT HISTORY (shown below invoice, hidden on print)
     ============================================================ -->
<div class="no-print" style="margin-top:1.5rem;">

<?php if (!empty($payments)): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h3 class="card-title">Payment History</h3></div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th><th>Method</th><th>Reference</th><th>Recorded By</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td><?= date('d M Y H:i', strtotime($pay['created_at'])) ?></td>
                    <td>
                        <?php if ($pay['payment_method'] === 'credit_note'): ?>
                            <span style="color:#DC2626;font-weight:600;">Credit Note</span>
                            <?php if ($pay['credit_note_no']): ?>
                                — <a href="<?= BASE_URL ?>/pos/credit_note_view.php?id=<?= $pay['credit_note_id'] ?>">
                                    <?= htmlspecialchars($pay['credit_note_no']) ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= ucfirst(htmlspecialchars($pay['payment_method'])) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($pay['reference'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($pay['recorded_by'] ?? '—') ?></td>
                    <td style="text-align:right;font-weight:600;">R <?= number_format((float)$pay['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#F8FAFC;">
                    <td colspan="4" style="text-align:right;font-weight:700;">Total Paid</td>
                    <td style="text-align:right;font-weight:700;">R <?= number_format($totalPaid, 2) ?></td>
                </tr>
                <tr style="background:<?= $isPaid ? '#F0FDF4' : '#FFFBEB' ?>;">
                    <td colspan="4" style="text-align:right;font-weight:700;">Outstanding Balance</td>
                    <td style="text-align:right;font-weight:700;color:<?= $isPaid ? '#15803D' : '#D97706' ?>;">
                        R <?= number_format(max(0, $balance), 2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($creditNotes)): ?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Credit Notes</h3></div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Credit Note No</th><th>Date</th><th>Reason</th><th>Status</th><th style="text-align:right">Total</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($creditNotes as $cnRow): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/pos/credit_note_view.php?id=<?= $cnRow['id'] ?>"><?= htmlspecialchars($cnRow['credit_note_no']) ?></a></td>
                    <td><?= date('d M Y', strtotime($cnRow['created_at'])) ?></td>
                    <td><?= htmlspecialchars(mb_substr($cnRow['reason'] ?? '', 0, 60)) ?><?= strlen($cnRow['reason'] ?? '') > 60 ? '…' : '' ?></td>
                    <td>
                        <span style="text-transform:capitalize;font-weight:600;color:<?= $cnRow['status']==='voided' ? '#DC2626' : ($cnRow['status']==='applied' ? '#16A34A' : '#D97706') ?>">
                            <?= htmlspecialchars($cnRow['status']) ?>
                        </span>
                    </td>
                    <td style="text-align:right;color:#DC2626;font-weight:600;">R <?= number_format((float)$cnRow['total'], 2) ?></td>
                    <td>
                        <?php if ($cnRow['status'] === 'open' && $balance > 0): ?>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="pay_amount" value="<?= min((float)$cnRow['total'], $balance) ?>">
                            <input type="hidden" name="pay_method" value="credit_note">
                            <input type="hidden" name="pay_reference" value="<?= htmlspecialchars($cnRow['credit_note_no']) ?>">
                            <input type="hidden" name="credit_note_id" value="<?= (int)$cnRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" style="color:#DC2626;border-color:#DC2626;"
                                onclick="return confirm('Apply credit note <?= htmlspecialchars($cnRow['credit_note_no']) ?> (R <?= number_format((float)$cnRow['total'],2) ?>) to this invoice?')">
                                Apply CN
                            </button>
                        </form>
                        <?php elseif ($cnRow['status'] === 'open' && $balance <= 0): ?>
                            <span style="font-size:.78rem;color:var(--color-muted);">Invoice paid</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     DOCUMENT HISTORY
     ============================================================ -->
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header">
        <h3 class="card-title">Document History</h3>
    </div>
    <?php if (empty($_docHistory)): ?>
    <div class="card-body" style="color:var(--color-muted);font-size:.9rem;">No activity recorded yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:155px;">Date &amp; Time</th>
                    <th style="width:160px;">Action</th>
                    <th style="width:160px;">By</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($_docHistory as $_dh):
                    $_m = $_docActionMeta[$_dh['action']] ?? ['label' => ucwords(str_replace('_', ' ', $_dh['action'])), 'icon' => '•', 'color' => '#6b7280'];
                ?>
                <tr>
                    <td style="font-size:.82rem;color:var(--color-muted);white-space:nowrap;">
                        <?= date('d M Y H:i', strtotime($_dh['created_at'])) ?>
                    </td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600;color:<?= $_m['color'] ?>;">
                            <?= $_m['icon'] ?> <?= htmlspecialchars($_m['label']) ?>
                        </span>
                    </td>
                    <td style="font-size:.875rem;"><?= htmlspecialchars($_dh['user_name'] ?? 'System') ?></td>
                    <td style="font-size:.82rem;color:var(--color-muted);"><?= htmlspecialchars($_dh['details']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.no-print -->

<script>
function printInvoice() {
    // Log the print action silently
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=log_print' }).catch(function(){});

    var card = document.querySelector('.invoice-print-card');
    if (!card) { alert('Invoice card not found.'); return; }

    // All CSS is embedded — no external stylesheet needed, so print fires instantly
    var css = [
        '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }',
        'body { font-family: "Segoe UI", Arial, sans-serif; background: #fff; color: #1a202c; padding: 24px; font-size: 14px; line-height: 1.5; }',

        /* Invoice card */
        '.invoice-print-card { background: #fff; padding: 2rem; max-width: 780px; margin: 0 auto; }',
        '.invoice-print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; border-bottom: 2px solid #1e3a5f; padding-bottom: 1.25rem; }',
        '.invoice-co-name { font-size: 1.4rem; font-weight: 800; color: #1e3a5f; }',
        '.invoice-co-sub { font-size: .8rem; color: #6b7280; margin-top: .15rem; }',
        '.invoice-title { font-size: 1.6rem; font-weight: 900; color: #1e3a5f; letter-spacing: .05em; text-align: right; }',
        '.invoice-meta { font-size: .875rem; text-align: right; color: #6b7280; margin-top: .4rem; line-height: 1.8; }',

        /* Bill to / invoice details */
        '.invoice-bill-section { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1.5rem; }',
        '.invoice-section-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin-bottom: .35rem; }',
        '.invoice-section-value { font-size: .9rem; color: #1a202c; line-height: 1.7; }',

        /* Table */
        'table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }',
        'table { table-layout: fixed; }',
        'thead th { text-align: left; padding: .5rem; background: #f8fafc; border-bottom: 2px solid #e5e7eb; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }',
        'thead th:nth-child(3) { text-align: center; }',
        'thead th:nth-child(4), thead th:nth-child(5) { text-align: right; }',
        'tbody td { padding: .5rem; border-bottom: 1px solid #e5e7eb; font-size: .875rem; vertical-align: top; word-break: break-word; }',
        'tbody td:nth-child(3) { text-align: center; }',
        'tbody td:nth-child(4) { text-align: right; }',
        'tbody td:nth-child(5) { text-align: right; font-weight: 600; }',
        'tbody tr:last-child td { border-bottom: none; }',
        'small { font-size: .8rem; color: #6b7280; }',
        'small.serial { font-family: monospace; color: #4b5563; }',

        /* Totals */
        '.invoice-totals-block { display: flex; justify-content: flex-end; margin-bottom: 1.5rem; }',
        '.invoice-totals-table { width: 360px; border-collapse: collapse; }',
        '.invoice-totals-table td { padding: .35rem .5rem; font-size: .9rem; }',
        '.invoice-totals-table td:last-child { text-align: right; font-weight: 600; }',
        '.invoice-grand-total td { border-top: 2px solid #1e3a5f; font-weight: 800; font-size: 1rem; padding-top: .5rem; }',

        /* Thank-you footer */
        '.invoice-thank-you { text-align: center; color: #6b7280; font-size: .8rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }',

        /* Print overrides */
        '@media print {',
        '  body { padding: 0; }',
        '  .invoice-print-card { max-width: 100%; padding: 1rem; }',
        '}'
    ].join('\n');

    var html = '<!DOCTYPE html><html><head>' +
        '<meta charset="UTF-8">' +
        '<title>Invoice <?= addslashes(htmlspecialchars($invoice['invoice_no'])) ?></title>' +
        '<style>' + css + '</style>' +
        '</head><body>' +
        card.outerHTML +
        '</body></html>';

    var win = window.open('', '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    win.document.open();
    win.document.write(html);
    win.document.close();

    // Print after DOM is ready — no stylesheet to wait for
    win.focus();
    setTimeout(function() { win.print(); }, 300);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
