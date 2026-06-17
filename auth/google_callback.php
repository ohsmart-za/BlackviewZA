<?php
// ============================================================
// Blackview SA Portal — Google OAuth 2.0 Callback
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

$pdo      = getDB();
$settings = getSettings($pdo);

// Google SSO must be enabled
if (empty($settings['google_sso_enabled'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$clientId     = $settings['google_client_id']     ?? '';
$clientSecret = $settings['google_client_secret'] ?? '';
$redirectUri  = BASE_URL . '/auth/google_callback.php';

// ---- Validate OAuth state (CSRF protection) ----
if (empty($_GET['state']) || empty($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    unset($_SESSION['oauth_state']);
    header('Location: ' . BASE_URL . '/index.php?auth_error=state_mismatch');
    exit;
}
unset($_SESSION['oauth_state']);

// ---- User denied access ----
if (!empty($_GET['error'])) {
    header('Location: ' . BASE_URL . '/index.php?auth_error=google_denied');
    exit;
}

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    header('Location: ' . BASE_URL . '/index.php?auth_error=no_code');
    exit;
}

// ---- Exchange code for access token ----
$tokenResponse = googleHttpPost('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

if (!$tokenResponse || empty($tokenResponse['access_token'])) {
    header('Location: ' . BASE_URL . '/index.php?auth_error=token_exchange');
    exit;
}

// ---- Fetch Google user info ----
$googleUser = googleHttpGet('https://www.googleapis.com/oauth2/v2/userinfo', $tokenResponse['access_token']);

if (!$googleUser || empty($googleUser['email'])) {
    header('Location: ' . BASE_URL . '/index.php?auth_error=userinfo');
    exit;
}

$googleId    = $googleUser['id']    ?? '';
$googleEmail = $googleUser['email'] ?? '';

// ---- Find matching portal user ----
// 1. Match by google_id
$user = null;
if ($googleId !== '') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = :gid AND is_active = 1 LIMIT 1');
    $stmt->execute([':gid' => $googleId]);
    $user = $stmt->fetch();
}

// 2. Fall back to email match
if (!$user) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
    $stmt->execute([':email' => $googleEmail]);
    $user = $stmt->fetch();

    if ($user) {
        // Must have auth_method = 'google'
        if (($user['auth_method'] ?? 'local') !== 'google') {
            header('Location: ' . BASE_URL . '/index.php?auth_error=local_only');
            exit;
        }
        // Save google_id for faster future lookups
        if ($googleId !== '') {
            $upd = $pdo->prepare('UPDATE users SET google_id = :gid WHERE id = :id');
            $upd->execute([':gid' => $googleId, ':id' => $user['id']]);
        }
    }
}

if (!$user) {
    header('Location: ' . BASE_URL . '/index.php?auth_error=no_account');
    exit;
}

// Double-check: user must be set to Google auth
if (($user['auth_method'] ?? 'local') !== 'google') {
    header('Location: ' . BASE_URL . '/index.php?auth_error=local_only');
    exit;
}

// ---- Create session ----
session_regenerate_id(true);
$_SESSION['user_id']         = $user['id'];
$_SESSION['user_name']       = $user['name'];
$_SESSION['user_email']      = $user['email'];
$_SESSION['user_role']       = $user['role'];
$_SESSION['last_activity']   = time();
// Store configurable timeout (minutes → seconds), fall back to SESSION_TIMEOUT constant
$_timeoutMins = (int)($settings['session_timeout_minutes'] ?? 0);
$_SESSION['session_timeout'] = $_timeoutMins > 0 ? $_timeoutMins * 60 : SESSION_TIMEOUT;

$upd = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
$upd->execute([':id' => $user['id']]);

logAudit($pdo, 'login_google', 'users', (int)$user['id'], 'Signed in via Google SSO');

// Mobile vs desktop redirect
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$dest     = preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)
    ? $basePath . '/mobile/index.php'
    : $basePath . '/dashboard.php';
header('Location: ' . $dest);
exit;

// ============================================================
// HTTP helpers — cURL preferred, file_get_contents fallback
// ============================================================

function googleHttpPost(string $url, array $params): ?array {
    $body = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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

function googleHttpGet(string $url, string $accessToken): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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
