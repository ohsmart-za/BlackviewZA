<?php
// ============================================================
// Blackview SA Portal — Gmail Mailer OAuth 2.0 Callback
// Exchanges authorization code → stores refresh token for email sending
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

requireLogin();
requireAdmin();

$pdo      = getDB();
$settings = getSettings($pdo);

// Validate CSRF state
$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['gmail_mailer_oauth_state'] ?? '')) {
    setFlash('error', 'Security check failed (state mismatch). Please try connecting again.');
    header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
    exit;
}
unset($_SESSION['gmail_mailer_oauth_state']);

// Handle user denial
if (!empty($_GET['error'])) {
    setFlash('error', 'Gmail access was denied: ' . htmlspecialchars($_GET['error']));
    header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
    exit;
}

$code = trim($_GET['code'] ?? '');
if (!$code) {
    setFlash('error', 'No authorisation code received from Google.');
    header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
    exit;
}

$clientId     = $settings['google_client_id']     ?? '';
$clientSecret = $settings['google_client_secret'] ?? '';
$redirectUri  = BASE_URL . '/auth/gmail_mailer_callback.php';

if (!$clientId || !$clientSecret) {
    setFlash('error', 'Google Client ID / Secret not configured. Set them in the Google Sign-In section first.');
    header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
    exit;
}

// Exchange code for tokens — request offline access to get refresh_token
$data = gmailHttpPost('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

if (empty($data['refresh_token'])) {
    if ($data === null) {
        $msg = 'Could not reach Google token endpoint (network/firewall issue on the server). '
             . 'allow_url_fopen=' . (ini_get('allow_url_fopen') ? 'ON' : 'OFF');
    } else {
        $err = $data['error_description'] ?? $data['error'] ?? '';
        $msg = $err
            ? "Gmail OAuth failed: $err"
            : 'Google did not return a refresh token. Keys returned: ' . implode(', ', array_keys($data));
    }
    setFlash('error', $msg);
    header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
    exit;
}

// Get the Gmail address from userinfo
$accessToken = $data['access_token'] ?? '';
$gmailEmail  = '';
if ($accessToken) {
    $userInfo   = gmailHttpGet('https://www.googleapis.com/oauth2/v3/userinfo', $accessToken);
    $gmailEmail = $userInfo['email'] ?? '';
}

// Save to settings
$saveStmt = $pdo->prepare(
    "INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
);
foreach ([
    'smtp_provider'              => 'gmail',
    'smtp_enabled'               => '1',
    'gmail_mailer_refresh_token' => $data['refresh_token'],
    'gmail_mailer_email'         => $gmailEmail,
] as $k => $v) {
    $saveStmt->execute([':k' => $k, ':v' => $v]);
}

logAudit($pdo, 'gmail_oauth_connect', 'settings', 0,
    "Gmail mailer OAuth connected — sending as $gmailEmail");

setFlash('success', "✓ Gmail connected — emails will be sent from $gmailEmail");
header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
exit;

// ============================================================
// HTTP helpers — identical pattern to google_callback.php
// ============================================================
function gmailHttpPost(string $url, array $params): ?array {
    $body = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function gmailHttpGet(string $url, string $accessToken): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_HTTPHEADER      => ["Authorization: Bearer $accessToken"],
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer $accessToken\r\n",
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}
