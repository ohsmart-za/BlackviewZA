<?php
// ============================================================
// Blackview SA Portal — Admin: Company Settings
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';

requireSuperuser();

$pdo       = getDB();
$pageTitle = 'Settings';
$errors    = [];
$testResult = null; // null | ['ok'|'err', message]

// ---- Handle logo delete ----
if (isset($_GET['delete_logo'])) {
    $currentLogo = getSetting($pdo, 'logo_path');
    if ($currentLogo !== '') {
        $absPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $currentLogo);
        if (file_exists($absPath)) unlink($absPath);
    }
    saveSettings($pdo, ['logo_path' => '']);
    setFlash('success', 'Logo deleted.');
    header('Location: ' . BASE_URL . '/admin/settings.php');
    exit;
}

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    // ---- Disconnect Gmail ----
    if ($action === 'disconnect_gmail') {
        saveSettings($pdo, [
            'smtp_provider'              => 'custom',
            'gmail_mailer_refresh_token' => '',
            'gmail_mailer_email'         => '',
        ]);
        logAudit($pdo, 'gmail_oauth_disconnect', 'settings', 0, 'Gmail mailer disconnected');
        setFlash('success', 'Gmail account disconnected.');
        header('Location: ' . BASE_URL . '/admin/settings.php#smtp');
        exit;
    }

    // ---- Test SMTP email ----
    if ($action === 'test_smtp') {
        $settings = getSettings($pdo);
        // Merge any just-posted SMTP values (so you can test before saving)
        $testSettings = array_merge($settings, [
            'smtp_enabled'    => 1,
            'smtp_host'       => trim($_POST['smtp_host']       ?? $settings['smtp_host']       ?? ''),
            'smtp_port'       => trim($_POST['smtp_port']       ?? $settings['smtp_port']       ?? '587'),
            'smtp_encryption' => trim($_POST['smtp_encryption'] ?? $settings['smtp_encryption'] ?? 'starttls'),
            'smtp_username'   => trim($_POST['smtp_username']   ?? $settings['smtp_username']   ?? ''),
            'smtp_from_email' => trim($_POST['smtp_from_email'] ?? $settings['smtp_from_email'] ?? ''),
            'smtp_from_name'  => trim($_POST['smtp_from_name']  ?? $settings['smtp_from_name']  ?? ''),
        ]);
        // Only update password if a new one was typed
        $newPass = trim($_POST['smtp_password'] ?? '');
        if ($newPass !== '') $testSettings['smtp_password'] = $newPass;

        $toEmail = $_SESSION['user_email'] ?? '';
        $toName  = $_SESSION['user_name']  ?? 'Test';
        $mailer  = new Mailer($testSettings);
        $ok = $mailer->send($toEmail, $toName, 'SMTP Test — ' . ($testSettings['smtp_from_name'] ?: APP_NAME), '<p>This is a test email from the ' . htmlspecialchars(APP_NAME) . ' portal. If you received this, your SMTP settings are working correctly.</p>');
        if ($ok) {
            $testResult = ['ok', "Test email sent to $toEmail — check your inbox."];
        } else {
            $testResult = ['err', 'Failed: ' . $mailer->lastError];
        }
        // Fall through to re-display the form (don't redirect)

    } else {
        // ---- Save all settings ----
        $textFields = [
            'company_name'     => trim($_POST['company_name']     ?? ''),
            'company_tagline'  => trim($_POST['company_tagline']  ?? ''),
            'company_vat_no'   => trim($_POST['company_vat_no']   ?? ''),
            'company_address'  => trim($_POST['company_address']  ?? ''),
            'company_email'    => trim($_POST['company_email']    ?? ''),
            'company_phone'    => trim($_POST['company_phone']    ?? ''),
            // SMTP
            'smtp_enabled'     => isset($_POST['smtp_enabled'])    ? '1' : '0',
            'smtp_host'        => trim($_POST['smtp_host']        ?? ''),
            'smtp_port'        => trim($_POST['smtp_port']        ?? '587'),
            'smtp_encryption'  => trim($_POST['smtp_encryption']  ?? 'starttls'),
            'smtp_username'    => trim($_POST['smtp_username']    ?? ''),
            'smtp_from_email'  => trim($_POST['smtp_from_email']  ?? ''),
            'smtp_from_name'   => trim($_POST['smtp_from_name']   ?? ''),
                // SMTP provider
            'smtp_provider'        => in_array($_POST['smtp_provider'] ?? '', ['custom','gmail']) ? $_POST['smtp_provider'] : 'custom',
            // Google SSO
            'google_sso_enabled'         => isset($_POST['google_sso_enabled'])    ? '1' : '0',
            'google_client_id'           => trim($_POST['google_client_id']      ?? ''),
            'google_client_secret'       => trim($_POST['google_client_secret']  ?? ''),
            // Security
            'session_timeout_minutes'    => max(1, (int)($_POST['session_timeout_minutes'] ?? 30)),
            // Payment gateways
            'yoco_enabled'               => isset($_POST['yoco_enabled'])    ? '1' : '0',
            'yoco_test_mode'             => isset($_POST['yoco_test_mode'])  ? '1' : '0',
            'payfast_enabled'            => isset($_POST['payfast_enabled']) ? '1' : '0',
            'payfast_test_mode'          => isset($_POST['payfast_test_mode']) ? '1' : '0',
            'payfast_merchant_id'        => trim($_POST['payfast_merchant_id']  ?? ''),
            'payfast_merchant_key'       => trim($_POST['payfast_merchant_key'] ?? ''),
            'payment_success_url'        => trim($_POST['payment_success_url']  ?? ''),
            'payment_cancel_url'         => trim($_POST['payment_cancel_url']   ?? ''),
        ];

        // Only update SMTP password if a new value was typed
        $newSmtpPass = trim($_POST['smtp_password'] ?? '');
        if ($newSmtpPass !== '') {
            $textFields['smtp_password'] = $newSmtpPass;
        }
        // Only update Google secret if a new value was typed
        $newGoogleSecret = trim($_POST['google_client_secret'] ?? '');
        if ($newGoogleSecret !== '') {
            $textFields['google_client_secret'] = $newGoogleSecret;
        }
        // Only update Yoco secret key if a new value was typed
        $newYocoKey = trim($_POST['yoco_secret_key'] ?? '');
        if ($newYocoKey !== '') {
            $textFields['yoco_secret_key'] = $newYocoKey;
        }
        // Only update PayFast passphrase if a new value was typed, or explicitly cleared
        $newPfPass = trim($_POST['payfast_passphrase'] ?? '');
        if ($newPfPass !== '') {
            $textFields['payfast_passphrase'] = $newPfPass;
        } elseif (!empty($_POST['payfast_passphrase_clear'])) {
            $textFields['payfast_passphrase'] = '';
        }

        if ($textFields['company_name'] === '') {
            $errors[] = 'Company name is required.';
        }

        // Logo upload
        $newLogoPath = null;
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['logo_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Logo upload error (code ' . $file['error'] . ').';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Logo file exceeds 2 MB limit.';
            } else {
                $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/avif', 'image/webp'];
                $mimeToExt    = ['image/png'=>'png','image/jpeg'=>'jpg','image/svg+xml'=>'svg','image/avif'=>'avif','image/webp'=>'webp'];
                if (!in_array($mimeType, $allowedMimes, true)) {
                    $errors[] = 'Logo must be PNG, JPG, or SVG.';
                } else {
                    $ext     = $mimeToExt[$mimeType];
                    $logoDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logo';
                    if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);
                    $oldLogo = getSetting($pdo, 'logo_path');
                    if ($oldLogo !== '') {
                        $oldAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldLogo);
                        if (file_exists($oldAbs)) unlink($oldAbs);
                    }
                    $destPath = $logoDir . DIRECTORY_SEPARATOR . 'logo.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        $errors[] = 'Failed to save logo file.';
                    } else {
                        $newLogoPath = 'assets/uploads/logo/logo.' . $ext;
                    }
                }
            }
        }

        if (empty($errors)) {
            saveSettings($pdo, $textFields);
            if ($newLogoPath !== null) saveSettings($pdo, ['logo_path' => $newLogoPath]);
            logAudit($pdo, 'save_settings', 'settings', null, 'Settings saved');
            setFlash('success', 'Settings saved successfully.');
            header('Location: ' . BASE_URL . '/admin/settings.php');
            exit;
        }
    }
}

// ---- Load current settings ----
$settings = getSettings($pdo);
$googleRedirectUri = BASE_URL . '/auth/google_callback.php';

// ---- Gmail Mailer OAuth URL ----
$_gmailConnected      = !empty($settings['gmail_mailer_refresh_token']);
$_gmailConnectedEmail = $settings['gmail_mailer_email'] ?? '';
$_smtpProvider        = $settings['smtp_provider'] ?? 'custom';
$_gmailOAuthUrl       = '';
if (!empty($settings['google_client_id'])) {
    $_gmailState = bin2hex(random_bytes(16));
    $_SESSION['gmail_mailer_oauth_state'] = $_gmailState;
    $_gmailOAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $settings['google_client_id'],
        'redirect_uri'  => BASE_URL . '/auth/gmail_mailer_callback.php',
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $_gmailState,
    ]);
}
// Also register this redirect URI for display
$_gmailCallbackUri = BASE_URL . '/auth/gmail_mailer_callback.php';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Admin Settings</h2>
    <p class="page-subtitle">Company information, SMTP email, and authentication configuration.</p>
</div>

<?php if ($testResult): ?>
    <div class="alert alert-<?= $testResult[0] === 'ok' ? 'success' : 'error' ?>">
        <?= htmlspecialchars($testResult[1]) ?>
    </div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="" enctype="multipart/form-data" novalidate id="settings-form">
<input type="hidden" name="action" value="save" id="form-action">

<!-- ========================================================
     CARD 1: Company Information
     ======================================================== -->
<div id="company" class="card" style="max-width:680px;margin-bottom:1.5rem;">
    <div class="card-header"><h3 class="card-title">Company Settings</h3></div>
    <div class="card-body">

        <div class="form-group">
            <label class="form-label">Company Name <span class="required">*</span></label>
            <input type="text" name="company_name" class="form-control"
                   value="<?= htmlspecialchars($_POST['company_name'] ?? $settings['company_name'] ?? '') ?>"
                   placeholder="e.g. Blackview SA" required>
        </div>

        <div class="form-group">
            <label class="form-label">Tagline</label>
            <input type="text" name="company_tagline" class="form-control"
                   value="<?= htmlspecialchars($_POST['company_tagline'] ?? $settings['company_tagline'] ?? '') ?>"
                   placeholder="e.g. Authorised Blackview Distributor">
        </div>

        <div class="form-group">
            <label class="form-label">VAT Registration No</label>
            <input type="text" name="company_vat_no" class="form-control"
                   value="<?= htmlspecialchars($_POST['company_vat_no'] ?? $settings['company_vat_no'] ?? '') ?>"
                   placeholder="e.g. 4123456789">
        </div>

        <div class="form-group">
            <label class="form-label">Address</label>
            <textarea name="company_address" class="form-control" rows="3"><?= htmlspecialchars($_POST['company_address'] ?? $settings['company_address'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="company_email" class="form-control"
                   value="<?= htmlspecialchars($_POST['company_email'] ?? $settings['company_email'] ?? '') ?>"
                   placeholder="info@example.co.za">
        </div>

        <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="company_phone" class="form-control"
                   value="<?= htmlspecialchars($_POST['company_phone'] ?? $settings['company_phone'] ?? '') ?>"
                   placeholder="+27 10 000 0000">
        </div>

        <!-- Logo -->
        <div class="form-group">
            <label class="form-label">Company Logo</label>
            <?php $logoPath = $settings['logo_path'] ?? ''; ?>
            <?php if ($logoPath !== ''): ?>
                <div style="margin-bottom:.75rem;">
                    <img src="<?= BASE_URL . '/' . htmlspecialchars($logoPath) ?>"
                         alt="Current Logo" class="settings-logo-preview">
                    <a href="?delete_logo=1" class="btn btn-sm btn-danger"
                       onclick="return confirm('Delete the current logo?')">Delete Logo</a>
                </div>
            <?php endif; ?>
            <input type="file" name="logo_file" class="form-control" accept=".png,.jpg,.jpeg,.svg,.avif,.webp">
            <small class="form-hint">Accepted: PNG, JPG, SVG — max 2 MB.</small>
        </div>

    </div>
</div>

<!-- ========================================================
     CARD 2: Email Settings
     ======================================================== -->
<div id="smtp" class="card" style="max-width:680px;margin-bottom:1.5rem;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title" style="margin:0;">Email Settings</h3>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;font-weight:500;color:#374151;">
            <input type="checkbox" name="smtp_enabled" value="1" id="smtp-toggle"
                   <?= !empty($settings['smtp_enabled']) ? 'checked' : '' ?>
                   style="width:16px;height:16px;cursor:pointer;">
            Enable Outgoing Email
        </label>
    </div>
    <div class="card-body" id="smtp-body">

        <!-- Provider toggle -->
        <div style="display:flex;gap:.5rem;margin-bottom:1.5rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:.35rem;">
            <label id="lbl-custom" style="flex:1;text-align:center;cursor:pointer;">
                <input type="radio" name="smtp_provider" value="custom" id="provider-custom"
                       <?= $_smtpProvider !== 'gmail' ? 'checked' : '' ?>
                       onchange="switchProvider('custom')"
                       style="display:none;">
                <span id="tab-custom" style="display:block;padding:.45rem .75rem;border-radius:6px;font-size:.875rem;font-weight:600;
                    background:<?= $_smtpProvider !== 'gmail' ? '#1e40af' : 'transparent' ?>;
                    color:<?= $_smtpProvider !== 'gmail' ? '#fff' : '#6b7280' ?>;">
                    Custom SMTP
                </span>
            </label>
            <label id="lbl-gmail" style="flex:1;text-align:center;cursor:pointer;">
                <input type="radio" name="smtp_provider" value="gmail" id="provider-gmail"
                       <?= $_smtpProvider === 'gmail' ? 'checked' : '' ?>
                       onchange="switchProvider('gmail')"
                       style="display:none;">
                <span id="tab-gmail" style="display:block;padding:.45rem .75rem;border-radius:6px;font-size:.875rem;font-weight:600;
                    background:<?= $_smtpProvider === 'gmail' ? '#1e40af' : 'transparent' ?>;
                    color:<?= $_smtpProvider === 'gmail' ? '#fff' : '#6b7280' ?>;">
                    ✦ Gmail OAuth 2.0
                </span>
            </label>
        </div>

        <!-- ================================================
             PANEL A: Custom SMTP
             ================================================ -->
        <div id="panel-custom" style="display:<?= $_smtpProvider !== 'gmail' ? 'block' : 'none' ?>;">

            <div style="display:grid;grid-template-columns:1fr 160px;gap:1rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>"
                           placeholder="smtp.gmail.com">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Port</label>
                    <input type="number" name="smtp_port" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>"
                           placeholder="587">
                </div>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Encryption</label>
                <select name="smtp_encryption" class="form-control form-select">
                    <?php foreach (['none'=>'None (plain)','starttls'=>'STARTTLS (port 587)','ssl'=>'SSL / TLS (port 465)'] as $val=>$lbl): ?>
                        <option value="<?= $val ?>" <?= ($settings['smtp_encryption'] ?? 'starttls') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">SMTP Username</label>
                <input type="text" name="smtp_username" class="form-control" autocomplete="off"
                       value="<?= htmlspecialchars($settings['smtp_username'] ?? '') ?>"
                       placeholder="you@yourdomain.com">
            </div>

            <div class="form-group">
                <label class="form-label">SMTP Password</label>
                <input type="password" name="smtp_password" class="form-control" autocomplete="new-password"
                       placeholder="<?= !empty($settings['smtp_password']) ? '(saved — leave blank to keep)' : 'Password or App Password' ?>">
                <small class="form-hint">For Gmail with App Password: <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a></small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">From Name</label>
                    <input type="text" name="smtp_from_name" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>"
                           placeholder="<?= htmlspecialchars($settings['company_name'] ?? APP_NAME) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">From Email</label>
                    <input type="email" name="smtp_from_email" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>"
                           placeholder="noreply@example.co.za">
                </div>
            </div>

            <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" onclick="document.getElementById('form-action').value='save'">Save Settings</button>
                <button type="submit" class="btn btn-outline"
                        onclick="document.getElementById('form-action').value='test_smtp'"
                        title="Sends a test to <?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>">
                    Send Test Email
                </button>
            </div>
            <p style="color:#9CA3AF;font-size:.78rem;margin-top:.5rem;">Test uses the values currently entered (even before saving).</p>

        </div><!-- /panel-custom -->

        <!-- ================================================
             PANEL B: Gmail OAuth 2.0
             ================================================ -->
        <div id="panel-gmail" style="display:<?= $_smtpProvider === 'gmail' ? 'block' : 'none' ?>;">

            <?php if ($_gmailConnected): ?>
            <!-- Connected state -->
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
                <div>
                    <div style="font-weight:700;color:#15803d;font-size:.95rem;">✓ Gmail Connected</div>
                    <div style="font-size:.85rem;color:#166534;margin-top:.2rem;">Sending as <strong><?= htmlspecialchars($_gmailConnectedEmail) ?></strong></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline"
                        style="color:#dc2626;border-color:#dc2626;"
                        onclick="disconnectGmail()">
                    Disconnect
                </button>
            </div>

            <div class="form-group">
                <label class="form-label">Display Name (From Name)</label>
                <input type="text" name="smtp_from_name" class="form-control"
                       value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>"
                       placeholder="<?= htmlspecialchars($settings['company_name'] ?? APP_NAME) ?>">
                <small class="form-hint">The name shown to recipients. The From address will always be <strong><?= htmlspecialchars($_gmailConnectedEmail) ?></strong>.</small>
            </div>

            <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" onclick="document.getElementById('form-action').value='save'">Save</button>
                <button type="submit" class="btn btn-outline"
                        onclick="document.getElementById('form-action').value='test_smtp'"
                        title="Sends a test to <?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>">
                    Send Test Email
                </button>
                <?php if ($_gmailOAuthUrl): ?>
                <a href="<?= htmlspecialchars($_gmailOAuthUrl) ?>" class="btn btn-outline" style="color:#0369a1;border-color:#0369a1;">
                    ↻ Reconnect / Switch Account
                </a>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Not connected state -->
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:1.25rem;margin-bottom:1.25rem;">
                <p style="margin:0 0 .5rem;font-weight:600;color:#0369a1;">Send emails directly from your Gmail account</p>
                <p style="margin:0;font-size:.85rem;color:#374151;line-height:1.6;">
                    No App Passwords needed. OAuth 2.0 is the secure, modern way to authorise Gmail sending.
                    Emails appear from your Gmail address and won't be blocked by Google.
                </p>
            </div>

            <?php if (!empty($settings['google_client_id'])): ?>

                <!-- Show callback URI to add in Google Cloud Console -->
                <div class="form-group" style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin-bottom:1rem;">
                    <label class="form-label" style="font-size:.8rem;color:#92400e;margin-bottom:.25rem;">
                        ⚠ Add this Redirect URI in Google Cloud Console → OAuth 2.0 credentials:
                    </label>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <code id="gmail-callback-uri" style="flex:1;font-size:.82rem;color:#92400e;word-break:break-all;"><?= htmlspecialchars($_gmailCallbackUri) ?></code>
                        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('gmail-callback-uri').textContent).then(()=>this.textContent='Copied!')" class="btn btn-sm btn-outline" style="flex-shrink:0;">Copy</button>
                    </div>
                </div>

                <a href="<?= htmlspecialchars($_gmailOAuthUrl) ?>"
                   class="btn btn-primary"
                   style="background:#1a73e8;border-color:#1a73e8;display:inline-flex;align-items:center;gap:.5rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                    Connect Gmail Account
                </a>

            <?php else: ?>
                <div class="alert alert-warning" style="margin:0;">
                    You need to add your <strong>Google Client ID</strong> in the Google Sign-In section below before connecting Gmail OAuth.
                </div>
            <?php endif; ?>
            <?php endif; ?>

        </div><!-- /panel-gmail -->

    </div>
</div>

<!-- ========================================================
     CARD 3: Google SSO
     ======================================================== -->
<div class="card" style="max-width:680px;margin-bottom:1.5rem;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title" style="margin:0;">Google Sign-In (SSO)</h3>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;font-weight:500;color:#374151;">
            <input type="checkbox" name="google_sso_enabled" value="1" id="sso-toggle"
                   <?= !empty($settings['google_sso_enabled']) ? 'checked' : '' ?>
                   style="width:16px;height:16px;cursor:pointer;">
            Enable Google SSO
        </label>
    </div>
    <div class="card-body" id="sso-body">

        <p style="color:#6B7280;font-size:.85rem;margin-top:0;margin-bottom:1rem;">
            Allow specific users to sign in using their Google account instead of a local password.
            Set up your OAuth credentials at
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="color:#2563EB;">Google Cloud Console</a>.
        </p>

        <div class="form-group" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;">
            <label class="form-label" style="font-size:.8rem;color:#166534;margin-bottom:.25rem;">Authorised Redirect URI — copy this into your Google OAuth app:</label>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <code id="redirect-uri-val" style="flex:1;font-size:.85rem;color:#166534;word-break:break-all;"><?= htmlspecialchars($googleRedirectUri) ?></code>
                <button type="button" onclick="copyRedirectUri()" class="btn btn-sm btn-outline" style="flex-shrink:0;">Copy</button>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Google Client ID</label>
            <input type="text" name="google_client_id" class="form-control" autocomplete="off"
                   value="<?= htmlspecialchars($settings['google_client_id'] ?? '') ?>"
                   placeholder="1234567890-abcdef.apps.googleusercontent.com">
        </div>

        <div class="form-group">
            <label class="form-label">Google Client Secret</label>
            <input type="password" name="google_client_secret" class="form-control" autocomplete="new-password"
                   placeholder="<?= !empty($settings['google_client_secret']) ? '(saved — leave blank to keep)' : 'GOCSPX-...' ?>">
        </div>

        <div style="background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin-top:.5rem;">
            <p style="margin:0;font-size:.83rem;color:#92400e;">
                <strong>How to enable for a user:</strong> Go to
                <a href="<?= BASE_URL ?>/admin/users.php" style="color:#b45309;">User Management</a>,
                edit the user, and set their <em>Login Method</em> to <strong>Google SSO</strong>.
                They will no longer be able to use a local password.
            </p>
        </div>

    </div>
</div>

<!-- ========================================================
     CARD 4: Security
     ======================================================== -->
<div id="security" class="card" style="max-width:680px;margin-bottom:1.5rem;">
    <div class="card-header"><h3 class="card-title">Security</h3></div>
    <div class="card-body">

        <div class="form-group">
            <label class="form-label">Inactivity Auto-Logout</label>
            <div style="display:flex;align-items:center;gap:.75rem;">
                <input type="number" name="session_timeout_minutes" class="form-control"
                       min="1" max="480" style="max-width:120px;"
                       value="<?= (int)($settings['session_timeout_minutes'] ?? 30) ?>">
                <span style="font-size:.9rem;color:#6b7280;">minutes of inactivity</span>
            </div>
            <small class="form-hint">Users are automatically signed out after this many minutes without any page activity. Minimum 1 minute, maximum 480 (8 hours). Takes effect on next login.</small>
        </div>

    </div>
</div>

<!-- ========================================================
     CARD 5: Payment Gateways
     ======================================================== -->
<div id="payment-gateways" class="card" style="max-width:680px;margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 class="card-title">Payment Gateways</h3>
    </div>
    <div class="card-body">
        <p style="font-size:.875rem;color:#6b7280;margin-bottom:1.25rem;">
            Configure payment providers to generate clickable payment links on unpaid invoices.
            Customers receive a link they can use to pay online.
        </p>

        <!-- ---- Yoco ---- -->
        <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:1.25rem;">
            <div style="background:#f8fafc;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e7eb;">
                <div style="display:flex;align-items:center;gap:.6rem;">
                    <span style="font-weight:700;font-size:.95rem;">Yoco</span>
                    <span style="font-size:.75rem;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-weight:600;">Recommended</span>
                </div>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;">
                    <input type="checkbox" name="yoco_enabled" id="yoco-toggle"
                           value="1" <?= !empty($settings['yoco_enabled']) ? 'checked' : '' ?>
                           onchange="document.getElementById('yoco-body').style.opacity=this.checked?'1':'.45'">
                    Enable Yoco
                </label>
            </div>
            <div id="yoco-body" style="padding:1rem;opacity:<?= !empty($settings['yoco_enabled']) ? '1' : '.45' ?>;">
                <div class="form-group">
                    <label class="form-label">Secret Key</label>
                    <input type="password" name="yoco_secret_key" class="form-control" autocomplete="new-password"
                           placeholder="<?= !empty($settings['yoco_secret_key']) ? '(saved — leave blank to keep)' : 'sk_live_... or sk_test_...' ?>">
                    <small class="form-hint">Found in your Yoco Business Portal → Developers → API Keys.</small>
                </div>
                <div class="form-group" style="margin-top:.75rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;">
                        <input type="checkbox" name="yoco_test_mode" value="1"
                               <?= !empty($settings['yoco_test_mode']) ? 'checked' : '' ?>>
                        Test mode (use test secret key; no real charges)
                    </label>
                </div>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:.65rem .85rem;margin-top:.5rem;font-size:.8rem;color:#1e40af;">
                    <strong>Webhook URL</strong> — add this in Yoco Business Portal → Developers → Webhooks:<br>
                    <code style="font-size:.78rem;"><?= BASE_URL ?>/payment/yoco_webhook.php</code>
                </div>
            </div>
        </div>

        <!-- ---- PayFast ---- -->
        <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:1.25rem;">
            <div style="background:#f8fafc;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e7eb;">
                <span style="font-weight:700;font-size:.95rem;">PayFast</span>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;">
                    <input type="checkbox" name="payfast_enabled" id="pf-toggle"
                           value="1" <?= !empty($settings['payfast_enabled']) ? 'checked' : '' ?>
                           onchange="document.getElementById('pf-body').style.opacity=this.checked?'1':'.45'">
                    Enable PayFast
                </label>
            </div>
            <div id="pf-body" style="padding:1rem;opacity:<?= !empty($settings['payfast_enabled']) ? '1' : '.45' ?>;">
                <div class="form-row">
                    <div class="form-group form-group--half">
                        <label class="form-label">Merchant ID</label>
                        <input type="text" name="payfast_merchant_id" class="form-control"
                               value="<?= htmlspecialchars($settings['payfast_merchant_id'] ?? '') ?>"
                               placeholder="10000100">
                    </div>
                    <div class="form-group form-group--half">
                        <label class="form-label">Merchant Key</label>
                        <input type="text" name="payfast_merchant_key" class="form-control"
                               value="<?= htmlspecialchars($settings['payfast_merchant_key'] ?? '') ?>"
                               placeholder="46f0cd694581a">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Passphrase</label>
                    <input type="password" name="payfast_passphrase" class="form-control" autocomplete="new-password"
                           placeholder="<?= !empty($settings['payfast_passphrase']) ? '(saved — leave blank to keep)' : 'Your PayFast passphrase (leave blank if none set)' ?>">
                    <small class="form-hint">Set in PayFast Merchant Dashboard → Settings → Security. Leave blank if your account has no passphrase.</small>
                    <?php if (!empty($settings['payfast_passphrase'])): ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:.82rem;color:#dc2626;cursor:pointer;">
                        <input type="checkbox" name="payfast_passphrase_clear" value="1">
                        Clear saved passphrase (no passphrase)
                    </label>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin-top:.75rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;">
                        <input type="checkbox" name="payfast_test_mode" value="1"
                               <?= !empty($settings['payfast_test_mode']) ? 'checked' : '' ?>>
                        Sandbox mode (test merchant credentials)
                    </label>
                </div>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:.65rem .85rem;margin-top:.5rem;font-size:.8rem;color:#1e40af;">
                    <strong>ITN Notify URL</strong> — PayFast will POST to this after payment:<br>
                    <code style="font-size:.78rem;"><?= BASE_URL ?>/payment/payfast_notify.php</code>
                </div>
            </div>
        </div>

        <!-- Shared redirect URLs -->
        <div style="border:1px solid #e5e7eb;border-radius:10px;padding:1rem;">
            <p style="font-weight:600;font-size:.875rem;margin-bottom:.75rem;">Redirect URLs (shared by all gateways)</p>
            <div class="form-group">
                <label class="form-label">Success URL</label>
                <input type="text" name="payment_success_url" class="form-control"
                       value="<?= htmlspecialchars($settings['payment_success_url'] ?? BASE_URL . '/payment/success.php') ?>"
                       placeholder="<?= BASE_URL ?>/payment/success.php">
                <small class="form-hint">Customer is sent here after a successful payment.</small>
            </div>
            <div class="form-group" style="margin-top:.75rem;">
                <label class="form-label">Cancel URL</label>
                <input type="text" name="payment_cancel_url" class="form-control"
                       value="<?= htmlspecialchars($settings['payment_cancel_url'] ?? BASE_URL . '/payment/cancel.php') ?>"
                       placeholder="<?= BASE_URL ?>/payment/cancel.php">
                <small class="form-hint">Customer is sent here if they cancel the payment.</small>
            </div>
        </div>

    </div>
</div>

<!-- Save button (bottom) -->
<div style="max-width:680px;margin-bottom:2rem;">
    <button type="submit" class="btn btn-primary btn-lg"
            onclick="document.getElementById('form-action').value='save'">
        Save All Settings
    </button>
</div>

</form>

<script>
// Switch between Custom SMTP and Gmail OAuth panels
function switchProvider(p) {
    document.getElementById('panel-custom').style.display = p === 'custom' ? 'block' : 'none';
    document.getElementById('panel-gmail').style.display  = p === 'gmail'  ? 'block' : 'none';
    // Tab highlight
    var tCustom = document.getElementById('tab-custom');
    var tGmail  = document.getElementById('tab-gmail');
    if (tCustom) { tCustom.style.background = p === 'custom' ? '#1e40af' : 'transparent'; tCustom.style.color = p === 'custom' ? '#fff' : '#6b7280'; }
    if (tGmail)  { tGmail.style.background  = p === 'gmail'  ? '#1e40af' : 'transparent'; tGmail.style.color  = p === 'gmail'  ? '#fff' : '#6b7280'; }
}

// Toggle SMTP card dim when disabled
(function(){
    const tog  = document.getElementById('smtp-toggle');
    const body = document.getElementById('smtp-body');
    function upd(){ body.style.opacity = tog.checked ? '1' : '.45'; }
    tog.addEventListener('change', upd); upd();
})();

// Toggle SSO fields dim when disabled
(function(){
    const tog  = document.getElementById('sso-toggle');
    const body = document.getElementById('sso-body');
    function upd(){ body.style.opacity = tog.checked ? '1' : '.45'; }
    tog.addEventListener('change', upd); upd();
})();

// Disconnect Gmail without a nested form (nested forms close the outer form in the DOM)
function disconnectGmail() {
    if (!confirm('Disconnect Gmail? Emails will stop sending until you reconnect or configure Custom SMTP.')) return;
    var fd = new FormData();
    fd.append('action', 'disconnect_gmail');
    fetch('', { method: 'POST', body: fd })
        .then(function() { window.location.href = window.location.pathname + '#smtp'; })
        .catch(function() { alert('Failed to disconnect. Please try again.'); });
}

// Copy redirect URI to clipboard
function copyRedirectUri(){
    const val = document.getElementById('redirect-uri-val').textContent.trim();
    navigator.clipboard.writeText(val).then(function(){
        const btn = event.target;
        btn.textContent = 'Copied!';
        setTimeout(()=>{ btn.textContent='Copy'; }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
