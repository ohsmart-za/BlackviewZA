<?php
// ============================================================
// Blackview SA Portal — Login Page
// ============================================================

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/settings.php';

// Handle logout first, before the "already logged in" check
if (isset($_GET['logout'])) {
    $pdo = getDB();
    $isTimeout = !empty($_GET['timeout']);
    logAudit($pdo, $isTimeout ? 'session_timeout' : 'logout', 'users',
        (int)($_SESSION['user_id'] ?? 0),
        $isTimeout ? 'Session timed out due to inactivity' : 'User logged out');
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . ($isTimeout ? '/index.php?timeout=1' : '/index.php?msg=logged_out'));
    exit;
}

// Already logged in? Redirect to dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error   = '';
$email   = '';

// Load branding + SSO settings for login page
$_loginSettings = [];
try {
    $_db = getDB();
    $_loginSettings = getSettings($_db);
} catch (Throwable $_e) { /* settings table may not exist yet */ }
$_loginLogo      = $_loginSettings['logo_path']         ?? '';
$_loginName      = $_loginSettings['company_name']      ?? APP_NAME;
$_loginTagline   = $_loginSettings['company_tagline']   ?? 'Stock & Inventory Portal';
$_googleEnabled  = !empty($_loginSettings['google_sso_enabled']);
$_googleClientId = $_loginSettings['google_client_id']  ?? '';

// Build Google OAuth URL (if SSO is configured)
$_googleOauthUrl = '';
if ($_googleEnabled && $_googleClientId !== '') {
    $_oauthState = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $_oauthState;
    $_redirectUri = BASE_URL . '/auth/google_callback.php';
    $_googleOauthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $_googleClientId,
        'redirect_uri'  => $_redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $_oauthState,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
}

// Map auth error codes to friendly messages
$_authErrors = [
    'state_mismatch' => 'Security check failed. Please try again.',
    'google_denied'  => 'Google sign-in was cancelled.',
    'token_exchange' => 'Could not exchange Google token. Please try again.',
    'userinfo'       => 'Could not retrieve your Google account details.',
    'local_only'     => 'This account uses a local password. Please sign in below.',
    'no_account'     => 'No portal account found for your Google address. Contact your administrator.',
    'no_code'        => 'Google did not return an authorisation code. Please try again.',
];
$_authError = $_authErrors[$_GET['auth_error'] ?? ''] ?? '';

// Handle POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo      = getDB();
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);

            $_SESSION['user_id']       = $user['id'];
            $_SESSION['user_name']     = $user['name'];
            $_SESSION['user_email']    = $user['email'];
            $_SESSION['user_role']     = $user['role'];
            $_SESSION['last_activity'] = time();
            // Store configurable inactivity timeout (minutes → seconds)
            $_toMins = (int)(getSettings($pdo)['session_timeout_minutes'] ?? 0);
            $_SESSION['session_timeout'] = $_toMins > 0 ? $_toMins * 60 : SESSION_TIMEOUT;

            // Update last_login
            $upd = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
            $upd->execute([':id' => $user['id']]);

            // Audit
            logAudit($pdo, 'login', 'users', (int)$user['id'], 'Successful login');

            // Remember me cookie (30 days)
            if (!empty($_POST['remember_me'])) {
                $token = bin2hex(random_bytes(32));
                setcookie('bvza_remember', $token, time() + (86400 * 30), '/', '', false, true);
                // NOTE: In production, store hashed token in DB linked to user
            }

            // Send mobile users to the mobile app (root-relative so LAN IPs work too)
            $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $dest     = preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)
                ? $basePath . '/mobile/index.php'
                : $basePath . '/dashboard.php';
            header('Location: ' . $dest);
            exit;
        } else {
            logAudit($pdo, 'login_failed', 'users', null, 'Failed login attempt for: ' . $email);
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-body">

<div class="login-wrapper">
    <div class="login-card">
        <!-- Logo / Brand -->
        <div class="login-brand">
            <?php if ($_loginLogo !== ''): ?>
                <img src="<?= BASE_URL . '/' . htmlspecialchars($_loginLogo) ?>"
                     alt="<?= htmlspecialchars($_loginName) ?>"
                     class="login-logo-img">
            <?php else: ?>
                <div class="login-logo">BV</div>
            <?php endif; ?>
            <?php if ($_loginLogo === ''): ?>
                <h1 class="login-title"><?= htmlspecialchars($_loginName) ?></h1>
                <p class="login-subtitle"><?= htmlspecialchars($_loginTagline) ?></p>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['timeout'])): ?>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;
                        padding:1rem 1.25rem;margin-bottom:1rem;text-align:center;">
                <div style="font-size:1.6rem;margin-bottom:.35rem;">🔒</div>
                <div style="font-weight:700;color:#dc2626;font-size:.95rem;">Session Expired</div>
                <div style="color:#7f1d1d;font-size:.82rem;margin-top:.2rem;">You were signed out due to inactivity. Please sign in again.</div>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out'): ?>
            <div class="alert alert-info">You have been signed out successfully.</div>
        <?php endif; ?>
        <?php if ($_authError !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($_authError) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form class="login-form" method="POST" action="<?= BASE_URL ?>/index.php" novalidate>

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    value="<?= $email !== '' ? htmlspecialchars($email) : '' ?>"
                    placeholder="you@blackview.co.za"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    value=""
                    placeholder="••••••••"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="form-group form-check-group">
                <label class="form-check">
                    <input type="checkbox" name="remember_me" value="1">
                    <span>Remember me for 30 days</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Sign In</button>

        </form>

        <!-- Divider + Google Sign-In -->
        <?php if ($_googleEnabled && $_googleOauthUrl !== ''): ?>
        <div class="login-divider"><span>or</span></div>
        <a href="<?= htmlspecialchars($_googleOauthUrl) ?>" class="btn btn-google btn-block">
            <svg class="google-icon" viewBox="0 0 48 48" width="20" height="20">
                <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.6H24v8.7h12.7c-.5 2.8-2.1 5.2-4.5 6.8v5.6h7.3c4.3-3.9 6.7-9.7 6.7-16.5z"/>
                <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.3-5.6c-2.1 1.4-4.8 2.2-8.6 2.2-6.6 0-12.2-4.4-14.2-10.4H2.3v5.8C6.3 42.6 14.6 48 24 48z"/>
                <path fill="#FBBC05" d="M9.8 28.4c-.5-1.4-.8-2.9-.8-4.4s.3-3 .8-4.4v-5.8H2.3C.8 16.9 0 20.3 0 24s.8 7.1 2.3 10.2l7.5-5.8z"/>
                <path fill="#EA4335" d="M24 9.6c3.7 0 7 1.3 9.6 3.8l7.2-7.2C36.9 2.1 31.5 0 24 0 14.6 0 6.3 5.4 2.3 13.8l7.5 5.8C11.8 14 17.4 9.6 24 9.6z"/>
            </svg>
            Sign in with Google
        </a>
        <?php endif; ?>

    </div><!-- /.login-card -->
</div><!-- /.login-wrapper -->

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
