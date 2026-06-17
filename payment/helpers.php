<?php
// ============================================================
// Payment Gateway Helpers
// Shared by invoice.php (link generation) and webhook handlers
// ============================================================

/**
 * Create a Yoco hosted-checkout session.
 * Returns ['ok'=>true,'redirect_url'=>'...','external_id'=>'...'] on success.
 * Returns ['ok'=>false,'error'=>'...'] on failure.
 */
function createYocoCheckout(array $settings, float $amount, string $successUrl, string $cancelUrl, string $failureUrl, array $metadata = []): array
{
    $secretKey = $settings['yoco_secret_key'] ?? '';
    if ($secretKey === '') {
        return ['ok' => false, 'error' => 'Yoco secret key not configured.'];
    }

    $payload = json_encode([
        'amount'     => (int)round($amount * 100), // convert Rands to cents
        'currency'   => 'ZAR',
        'successUrl' => $successUrl,
        'cancelUrl'  => $cancelUrl,
        'failureUrl' => $failureUrl,
        'metadata'   => $metadata,
    ]);

    $endpoint = 'https://payments.yoco.com/api/checkouts';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'error' => 'cURL error: ' . $curlErr];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => "Yoco returned invalid JSON (HTTP $httpCode)."];
    }

    if ($httpCode !== 200 || empty($data['redirectUrl'])) {
        $msg = $data['errorCode'] ?? ($data['message'] ?? 'Unknown Yoco error');
        return ['ok' => false, 'error' => "Yoco error: $msg (HTTP $httpCode)"];
    }

    return [
        'ok'          => true,
        'redirect_url' => $data['redirectUrl'],
        'external_id'  => $data['id'] ?? '',
    ];
}

/**
 * Build a PayFast signature string for the given data array.
 * Pass an associative array of all form fields (except 'signature').
 * Passphrase is appended at the end if provided.
 */
function buildPayFastSignature(array $data, string $passphrase = ''): string
{
    // Sort keys alphabetically
    ksort($data);

    $parts = [];
    foreach ($data as $key => $val) {
        if ($val !== '' && $val !== null) {
            $parts[] = $key . '=' . urlencode(trim((string)$val));
        }
    }
    $queryString = implode('&', $parts);

    if ($passphrase !== '') {
        $queryString .= '&passphrase=' . urlencode(trim($passphrase));
    }

    return md5($queryString);
}

/**
 * Generate a secure random token.
 */
function generatePaymentToken(): string
{
    return bin2hex(random_bytes(32)); // 64 hex chars
}
