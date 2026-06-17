<?php
// ============================================================
// Blackview SA Portal — PayFast ITN Handler
// URL: https://b2b.blackview.co.za/payment/payfast_notify.php
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/helpers.php';

$pdo      = getDB();
$settings = getSettings($pdo);

$isSandbox   = !empty($settings['payfast_test_mode']);
$passphrase  = $isSandbox ? 'jt7NOE43FZPn' : ($settings['payfast_passphrase'] ?? '');

// PayFast POSTs all form fields to this URL
$pfData = $_POST;

if (empty($pfData)) {
    http_response_code(400);
    exit('Empty payload.');
}

// --- Step 1: Verify signature ---
$receivedSig = $pfData['signature'] ?? '';
unset($pfData['signature']);
$expectedSig = buildPayFastSignature($pfData, $passphrase);

if (!hash_equals($expectedSig, $receivedSig)) {
    http_response_code(400);
    error_log('PayFast ITN: signature mismatch.');
    exit('Invalid signature.');
}

// --- Step 2: Verify source IP (PayFast valid IPs) ---
$validIPs = ['197.97.145.144','197.97.145.145','197.97.145.146','197.97.145.147'];
if (!$isSandbox) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $validIPs, true)) {
        http_response_code(400);
        error_log("PayFast ITN: invalid IP $remoteIp");
        exit('Invalid source IP.');
    }
}

// --- Step 3: Verify payment status ---
$paymentStatus = $pfData['payment_status'] ?? '';
if ($paymentStatus !== 'COMPLETE') {
    http_response_code(200);
    exit('non-complete status acknowledged');
}

// --- Step 4: Find our payment link by token (m_payment_id) ---
$token    = $pfData['m_payment_id'] ?? '';
$pfAmount = (float)($pfData['amount_gross'] ?? 0);

if ($token === '') {
    http_response_code(400);
    exit('Missing m_payment_id.');
}

$link = $pdo->prepare(
    "SELECT pl.*, inv.invoice_no
     FROM payment_links pl
     JOIN invoices inv ON inv.id = pl.invoice_id
     WHERE pl.token = :token AND pl.provider = 'payfast' AND pl.status = 'pending'
     LIMIT 1"
);
$link->execute([':token' => $token]);
$link = $link->fetch();

if (!$link) {
    http_response_code(200);
    exit('not found or already processed');
}

// --- Step 5: Verify amount matches (within 5 cents tolerance) ---
if (abs($pfAmount - (float)$link['amount']) > 0.05) {
    error_log("PayFast ITN: amount mismatch. Expected {$link['amount']}, got $pfAmount.");
    http_response_code(400);
    exit('Amount mismatch.');
}

// --- Step 6: Record payment ---
try {
    $pdo->beginTransaction();

    $pfPaymentId = $pfData['pf_payment_id'] ?? '';

    $pdo->prepare(
        "INSERT INTO invoice_payments (invoice_id, amount, payment_method, reference, notes, created_by, created_at)
         VALUES (:inv, :amt, 'payfast', :ref, :notes, NULL, NOW())"
    )->execute([
        ':inv'   => $link['invoice_id'],
        ':amt'   => $pfAmount,
        ':ref'   => 'PayFast: ' . $pfPaymentId,
        ':notes' => 'Auto-recorded via PayFast ITN',
    ]);

    $pdo->prepare(
        "UPDATE payment_links SET status='paid', paid_at=NOW(), external_id=:eid WHERE id=:id"
    )->execute([':eid' => $pfPaymentId, ':id' => $link['id']]);

    $pdo->prepare(
        "INSERT INTO audit_log (entity, entity_id, action, details, user_id, created_at)
         VALUES ('invoices', :inv, 'online_payment', :details, NULL, NOW())"
    )->execute([
        ':inv'     => $link['invoice_id'],
        ':details' => "R $pfAmount paid via PayFast (ID: $pfPaymentId) for invoice {$link['invoice_no']}",
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('PayFast ITN DB error: ' . $e->getMessage());
    http_response_code(500);
    exit('DB error');
}

http_response_code(200);
echo 'ok';
