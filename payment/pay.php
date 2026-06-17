<?php
// ============================================================
// Blackview SA Portal — Public Payment Page
// Accessed by customers via: /payment/pay.php?token=xxx
// - Yoco: redirects straight to Yoco's hosted checkout
// - PayFast: shows a self-submitting form → redirects to PayFast
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    die('Invalid payment link.');
}

// Load the payment link
$linkRow = $pdo->prepare(
    "SELECT pl.*, inv.invoice_no, inv.total AS inv_total,
            c.name AS customer_name, c.email AS customer_email
     FROM payment_links pl
     JOIN invoices inv ON inv.id = pl.invoice_id
     LEFT JOIN customers c ON c.id = inv.customer_id
     WHERE pl.token = :token LIMIT 1"
);
$linkRow->execute([':token' => $token]);
$link = $linkRow->fetch();

if (!$link) {
    http_response_code(404);
    die('Payment link not found.');
}

if ($link['status'] === 'paid') {
    // Show a nice "already paid" page
    ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Already Paid</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:'Segoe UI',sans-serif;background:#f0fdf4;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.box{background:#fff;border-radius:16px;padding:2.5rem;max-width:440px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.icon{font-size:3.5rem;margin-bottom:1rem}.h{font-size:1.3rem;font-weight:700;color:#166534;margin-bottom:.5rem}
.sub{color:#4b5563;font-size:.9rem}</style>
</head>
<body>
<div class="box">
    <div class="icon">✅</div>
    <div class="h">Payment Already Received</div>
    <div class="sub">
        Invoice <strong><?= htmlspecialchars($link['invoice_no']) ?></strong>
        has already been paid. Thank you!
    </div>
</div>
</body>
</html>
<?php
    exit;
}

if ($link['status'] === 'cancelled' || $link['status'] === 'expired') {
    http_response_code(410);
    die('This payment link has expired or been cancelled.');
}

// Check expiry
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
    $pdo->prepare("UPDATE payment_links SET status='expired' WHERE id=:id")->execute([':id' => $link['id']]);
    http_response_code(410);
    die('This payment link has expired.');
}

$settings = getSettings($pdo);

// ---- YOCO: redirect straight to Yoco's hosted checkout ----
if ($link['provider'] === 'yoco') {
    // The redirect URL was already generated when the link was created.
    // Just redirect the customer there.
    header('Location: ' . $link['payment_url']);
    exit;
}

// ---- PAYFAST: build form and auto-submit ----
if ($link['provider'] === 'payfast') {

    $isSandbox   = !empty($settings['payfast_test_mode']);
    $pfAction    = $isSandbox
        ? 'https://sandbox.payfast.co.za/eng/process'
        : 'https://www.payfast.co.za/eng/process';

    // Trim all credentials to remove accidental whitespace from DB
    $merchantId  = $isSandbox ? '10000100'      : trim($settings['payfast_merchant_id']  ?? '');
    $merchantKey = $isSandbox ? '46f0cd694581a' : trim($settings['payfast_merchant_key'] ?? '');
    $passphrase  = $isSandbox ? 'jt7NOE43FZPn'  : trim($settings['payfast_passphrase']   ?? '');

    $notifyUrl  = BASE_URL . '/payment/payfast_notify.php';
    $returnUrl  = trim($settings['payment_success_url'] ?? '') ?: BASE_URL . '/payment/success.php';
    $cancelUrl  = trim($settings['payment_cancel_url']  ?? '') ?: BASE_URL . '/payment/cancel.php';

    // Only include the minimum required fields — fewer fields = fewer encoding surprises.
    // Name/email are optional; omitting them avoids empty-field signature mismatches.
    $pfData = [
        'merchant_id'  => $merchantId,
        'merchant_key' => $merchantKey,
        'return_url'   => $returnUrl,
        'cancel_url'   => $cancelUrl,
        'notify_url'   => $notifyUrl,
        'm_payment_id' => $link['token'],
        'amount'       => number_format((float)$link['amount'], 2, '.', ''),
        'item_name'    => 'Invoice ' . $link['invoice_no'],
    ];

    // Build pre-hash string exactly as PayFast expects:
    // ksort → key=urlencode(trim(val))& → remove trailing & → append passphrase if set → md5
    ksort($pfData);
    $pfParamString = '';
    foreach ($pfData as $key => $val) {
        if ($val !== '' && $val !== null) {
            $pfParamString .= $key . '=' . urlencode(trim((string)$val)) . '&';
        }
    }
    $pfParamString = rtrim($pfParamString, '&');
    if ($passphrase !== '') {
        $pfParamString .= '&passphrase=' . urlencode($passphrase);
    }
    $signature = md5($pfParamString);

    // Add signature to data for form output (signature is NOT part of the above hash)
    $pfData['signature'] = $signature;

    // Debug mode: append ?debug=1 to the payment URL to inspect values without auto-submitting
    $isDebug = isset($_GET['debug']) && $_GET['debug'] === '1';

    ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Redirecting to PayFast…</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:'Segoe UI',sans-serif;background:#f8fafc;display:flex;align-items:center;
     justify-content:center;min-height:100vh;margin:0;padding:1rem;text-align:center}
p{color:#374151;font-size:.95rem}
pre{text-align:left;background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;
    font-size:.78rem;overflow-x:auto;margin-top:1rem;max-width:640px}
</style>
</head>
<body>
<div>
<?php if ($isDebug): ?>
    <h2 style="color:#0f172a;margin-bottom:.5rem;">PayFast Debug</h2>
    <p style="color:#64748b;font-size:.85rem;">The form will NOT auto-submit in debug mode. Check values below then click Pay to test manually.</p>
    <pre><?php
        echo "=== Credentials ===\n";
        echo "merchant_id:  " . htmlspecialchars($merchantId)  . "\n";
        echo "merchant_key: " . htmlspecialchars($merchantKey) . "\n";
        echo "passphrase:   " . ($passphrase ? str_repeat('*', strlen($passphrase)) : '(empty — no passphrase)') . "\n";
        echo "sandbox:      " . ($isSandbox ? 'YES' : 'NO') . "\n\n";
        echo "=== Pre-hash string ===\n";
        echo htmlspecialchars($pfParamString) . "\n\n";
        echo "=== Signature (md5) ===\n";
        echo $signature . "\n\n";
        echo "=== All form fields ===\n";
        foreach ($pfData as $k => $v) echo "$k = " . htmlspecialchars((string)$v) . "\n";
    ?></pre>
<?php else: ?>
    <p>Redirecting you to PayFast to complete payment…</p>
    <p style="font-size:.8rem;color:#9ca3af;margin-top:.5rem;">If nothing happens, click the button below.</p>
<?php endif; ?>
    <form id="pfForm" method="POST" action="<?= htmlspecialchars($pfAction) ?>">
        <?php foreach ($pfData as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
        <?php endforeach; ?>
        <button type="submit" style="margin-top:1rem;padding:.6rem 1.4rem;background:#2563eb;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:.9rem;">
            Pay R <?= number_format((float)$link['amount'], 2) ?> via PayFast
        </button>
    </form>
</div>
<?php if (!$isDebug): ?>
<script>document.getElementById('pfForm').submit();</script>
<?php endif; ?>
</body>
</html>
<?php
    exit;
}

// Unknown provider
http_response_code(400);
die('Unsupported payment provider.');
