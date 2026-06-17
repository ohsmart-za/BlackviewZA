<?php
// ============================================================
// Blackview SA Portal — Yoco Webhook Handler
// Configure in Yoco Business Portal → Developers → Webhooks
// URL: https://b2b.blackview.co.za/payment/yoco_webhook.php
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';

// Yoco sends JSON payload
$rawBody = file_get_contents('php://input');
$event   = json_decode($rawBody, true);

if (!is_array($event)) {
    http_response_code(400);
    exit('Invalid payload.');
}

// We only care about successful payment events
$eventType = $event['type'] ?? '';
if ($eventType !== 'payment.succeeded' && $eventType !== 'checkout.complete') {
    http_response_code(200); // acknowledge but ignore
    exit('ok');
}

$pdo      = getDB();
$settings = getSettings($pdo);

// Verify webhook secret (optional but recommended)
// Yoco signs webhooks with a secret in X-Yoco-Signature header.
// For now we rely on matching the checkout ID.
$checkoutId = $event['payload']['checkoutId'] ?? ($event['payload']['id'] ?? '');
$amountPaid = isset($event['payload']['amount'])
    ? round((float)$event['payload']['amount'] / 100, 2) // cents → rands
    : null;

if ($checkoutId === '') {
    http_response_code(200);
    exit('no checkout id');
}

// Find the payment link by external_id
$link = $pdo->prepare(
    "SELECT pl.*, inv.invoice_no
     FROM payment_links pl
     JOIN invoices inv ON inv.id = pl.invoice_id
     WHERE pl.external_id = :eid AND pl.provider = 'yoco' AND pl.status = 'pending'
     LIMIT 1"
);
$link->execute([':eid' => $checkoutId]);
$link = $link->fetch();

if (!$link) {
    // Already processed or unknown link — acknowledge to prevent retries
    http_response_code(200);
    exit('not found or already processed');
}

try {
    $pdo->beginTransaction();

    $recordedAmount = $amountPaid ?? (float)$link['amount'];

    // Record the payment on the invoice
    $pdo->prepare(
        "INSERT INTO invoice_payments (invoice_id, amount, payment_method, reference, notes, created_by, created_at)
         VALUES (:inv, :amt, 'yoco', :ref, :notes, NULL, NOW())"
    )->execute([
        ':inv'   => $link['invoice_id'],
        ':amt'   => $recordedAmount,
        ':ref'   => 'Yoco checkout: ' . $checkoutId,
        ':notes' => 'Auto-recorded via Yoco webhook',
    ]);

    // Mark link as paid
    $pdo->prepare(
        "UPDATE payment_links SET status='paid', paid_at=NOW() WHERE id=:id"
    )->execute([':id' => $link['id']]);

    // Audit log (user_id = null, system action)
    $pdo->prepare(
        "INSERT INTO audit_log (entity, entity_id, action, details, user_id, created_at)
         VALUES ('invoices', :inv, 'online_payment', :details, NULL, NOW())"
    )->execute([
        ':inv'     => $link['invoice_id'],
        ':details' => "R $recordedAmount paid via Yoco (checkout: $checkoutId) for invoice {$link['invoice_no']}",
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Yoco webhook DB error: ' . $e->getMessage());
    http_response_code(500);
    exit('DB error');
}

http_response_code(200);
echo 'ok';
