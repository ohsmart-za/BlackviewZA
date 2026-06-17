<?php
// ============================================================
// Blackview SA Portal — SMTP Mailer (no Composer required)
// ============================================================

class Mailer {
    private string $host;
    private int    $port;
    private string $encryption; // 'none' | 'starttls' | 'ssl'
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    public  string $lastError = '';

    /** @var resource|null */
    private $socket = null;

    public function __construct(array $cfg) {
        $this->host       = $cfg['smtp_host']       ?? '';
        $this->port       = (int)($cfg['smtp_port'] ?? 587);
        $this->encryption = strtolower($cfg['smtp_encryption'] ?? 'starttls');
        $this->username   = $cfg['smtp_username']   ?? '';
        $this->password   = $cfg['smtp_password']   ?? '';
        $this->fromEmail  = $cfg['smtp_from_email'] ?? '';
        $this->fromName   = $cfg['smtp_from_name']  ?? '';
    }

    // ---- Public: send one email -----------------------------------

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
        try {
            $this->connect();
            $this->ehlo();
            if ($this->encryption === 'starttls') {
                $this->startTls();
                $this->ehlo(); // re-EHLO after TLS upgrade
            }
            if ($this->username !== '') {
                $this->authenticate();
            }
            $this->mailFrom($this->fromEmail);
            $this->rcptTo($toEmail);
            $this->sendData($toEmail, $toName, $subject, $htmlBody);
            $this->quit();
            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            if ($this->socket) {
                @fclose($this->socket);
                $this->socket = null;
            }
            return false;
        }
    }

    // ---- Private SMTP steps --------------------------------------

    private function connect(): void {
        $timeout = 15;
        if ($this->encryption === 'ssl') {
            $addr = 'ssl://' . $this->host . ':' . $this->port;
            $ctx  = stream_context_create(['ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ]]);
            $this->socket = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        } else {
            $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $timeout);
        }
        if (!$this->socket) {
            throw new Exception("SMTP connect failed ({$this->host}:{$this->port}): $errstr ($errno)");
        }
        stream_set_timeout($this->socket, $timeout);
        $resp = $this->read();
        if (!str_starts_with($resp, '220')) {
            throw new Exception("SMTP greeting error: $resp");
        }
    }

    private function ehlo(): void {
        $domain = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
        $resp   = $this->cmd("EHLO $domain");
        if (!str_starts_with($resp, '250')) {
            throw new Exception("EHLO failed: $resp");
        }
    }

    private function startTls(): void {
        $resp = $this->cmd('STARTTLS');
        if (!str_starts_with($resp, '220')) {
            throw new Exception("STARTTLS command failed: $resp");
        }
        if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('STARTTLS crypto handshake failed.');
        }
    }

    private function authenticate(): void {
        $resp = $this->cmd('AUTH LOGIN');
        if (!str_starts_with($resp, '334')) {
            throw new Exception("AUTH LOGIN failed: $resp");
        }
        $resp = $this->cmd(base64_encode($this->username));
        if (!str_starts_with($resp, '334')) {
            throw new Exception("AUTH username step failed: $resp");
        }
        $resp = $this->cmd(base64_encode($this->password));
        if (!str_starts_with($resp, '235')) {
            throw new Exception("AUTH password step failed: $resp");
        }
    }

    private function mailFrom(string $email): void {
        $resp = $this->cmd("MAIL FROM:<$email>");
        if (!str_starts_with($resp, '250')) {
            throw new Exception("MAIL FROM failed: $resp");
        }
    }

    private function rcptTo(string $email): void {
        $resp = $this->cmd("RCPT TO:<$email>");
        if (!str_starts_with($resp, '250') && !str_starts_with($resp, '251')) {
            throw new Exception("RCPT TO failed: $resp");
        }
    }

    private function sendData(string $toEmail, string $toName, string $subject, string $htmlBody): void {
        $resp = $this->cmd('DATA');
        if (!str_starts_with($resp, '354')) {
            throw new Exception("DATA command failed: $resp");
        }

        $boundary  = '----=_MimePart_' . md5(uniqid('', true));
        $plainText = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</h1>', '</h2>', '</h3>', '</li>'],
            "\n", $htmlBody
        ));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');

        $toHeader   = $toName !== '' ? '"' . str_replace('"', '', $toName) . '" <' . $toEmail . '>' : $toEmail;
        $fromHeader = $this->fromName !== '' ? '"' . str_replace('"', '', $this->fromName) . '" <' . $this->fromEmail . '>' : $this->fromEmail;

        $headers  = "From: $fromHeader\r\n";
        $headers .= "To: $toHeader\r\n";
        $headers .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $headers .= 'Date: ' . date('r') . "\r\n";
        $headers .= 'Message-ID: <' . microtime(true) . '.' . md5($toEmail . uniqid()) . '@' . ($this->host ?: 'localhost') . ">\r\n";
        $headers .= "X-Mailer: BlackviewZA-Portal\r\n";

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode(wordwrap($plainText, 76, "\n", true)) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n";
        $body .= "--$boundary--\r\n";

        $message = $headers . "\r\n" . $body;
        // Dot-stuffing: RFC 5321 — lines starting with '.' must be doubled
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($this->socket, $message . "\r\n.\r\n");
        $resp = $this->read();
        if (!str_starts_with($resp, '250')) {
            throw new Exception("Message rejected: $resp");
        }
    }

    private function quit(): void {
        $this->cmd('QUIT');
        fclose($this->socket);
        $this->socket = null;
    }

    // ---- Socket helpers ------------------------------------------

    private function cmd(string $command): string {
        fwrite($this->socket, $command . "\r\n");
        return $this->read();
    }

    private function read(): string {
        $data = '';
        while ($line = fgets($this->socket, 512)) {
            $data .= $line;
            // Continuation lines: "250-..." vs final "250 ..."
            if (strlen($line) >= 4 && $line[3] === ' ') break;
            if (strlen($line) < 4) break;
        }
        return $data;
    }

    private function encodeHeader(string $value): string {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}

// ============================================================
// Internal cURL helpers for Gmail API calls
// ============================================================
function _gmailApiPost(string $url, array $params): ?array {
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
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function _gmailApiPostJson(string $url, array $payload, string $accessToken): ?array {
    $body = json_encode($payload);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $accessToken",
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!$raw) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// ============================================================
// Helper: render an email template with {{var}} substitution
// ============================================================
function renderEmailTemplate(PDO $pdo, string $templateKey, array $vars): array {
    $stmt = $pdo->prepare("SELECT subject, body_html FROM email_templates WHERE template_key = :k LIMIT 1");
    $stmt->execute([':k' => $templateKey]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) {
        return ['ok' => false, 'error' => "Email template '$templateKey' not found in database."];
    }

    $subject = $tpl['subject'];
    $body    = $tpl['body_html'];

    // Replace {{var}} placeholders with supplied values
    foreach ($vars as $key => $value) {
        $subject = str_replace('{{' . $key . '}}', (string)$value, $subject);
        $body    = str_replace('{{' . $key . '}}', (string)$value, $body);
    }

    // Remove any remaining unreplaced {{...}} tokens
    $subject = preg_replace('/\{\{[^}]+\}\}/', '', $subject);
    $body    = preg_replace('/\{\{[^}]+\}\}/', '', $body);

    return ['ok' => true, 'subject' => $subject, 'body' => $body];
}

// ============================================================
// Helper: send email via Gmail REST API (OAuth 2.0)
// ============================================================
function sendViaGmailApi(array $settings, string $toEmail, string $toName, string $subject, string $htmlBody): array {
    $clientId     = $settings['google_client_id']           ?? '';
    $clientSecret = $settings['google_client_secret']       ?? '';
    $refreshToken = $settings['gmail_mailer_refresh_token'] ?? '';
    $fromEmail    = $settings['gmail_mailer_email']         ?? '';
    $fromName     = $settings['smtp_from_name']             ?? '';

    if (!$clientId || !$clientSecret || !$refreshToken) {
        return ['ok' => false, 'error' => 'Gmail not connected. Go to Admin → Settings → Email and connect your Gmail account.'];
    }

    // Step 1: Exchange refresh token for a fresh access token
    $tokenData = _gmailApiPost('https://oauth2.googleapis.com/token', [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token',
    ]);

    if (empty($tokenData['access_token'])) {
        $err = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown token error.';
        return ['ok' => false, 'error' => "Gmail token refresh failed: $err. Try reconnecting your Gmail account in Admin → Settings."];
    }
    $accessToken = $tokenData['access_token'];

    // Step 2: Build RFC 2822 message
    $boundary  = '----=_MimePart_' . md5(uniqid('', true));
    $plainText = html_entity_decode(strip_tags(str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $htmlBody
    )), ENT_QUOTES, 'UTF-8');

    $toHeader   = $toName   !== '' ? '"' . str_replace('"', '', $toName)   . '" <' . $toEmail   . '>' : $toEmail;
    $fromHeader = $fromName !== '' ? '"' . str_replace('"', '', $fromName) . '" <' . $fromEmail . '>' : $fromEmail;

    $msg  = "From: $fromHeader\r\n";
    $msg .= "To: $toHeader\r\n";
    $msg .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $msg .= 'Date: ' . date('r') . "\r\n\r\n";
    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $msg .= quoted_printable_encode(wordwrap($plainText, 76, "\n", true)) . "\r\n";
    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $msg .= quoted_printable_encode($htmlBody) . "\r\n";
    $msg .= "--$boundary--\r\n";

    // Base64url encode (Gmail API requires base64url, no padding)
    $encoded = rtrim(strtr(base64_encode($msg), '+/', '-_'), '=');

    // Step 3: POST to Gmail API
    $sendData = _gmailApiPostJson(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        ['raw' => $encoded],
        $accessToken
    );

    if (!empty($sendData['id'])) {
        return ['ok' => true];
    }
    $err = $sendData['error']['message'] ?? $sendData['error']['status'] ?? 'Unknown Gmail API error.';
    return ['ok' => false, 'error' => "Gmail API error: $err"];
}

// ============================================================
// Helper: render template + send via configured email method
// Returns ['ok'=>bool, 'error'=>string, 'subject'=>string]
// ============================================================
function sendDocumentEmail(PDO $pdo, string $templateKey, array $vars, string $toEmail, string $toName): array {
    require_once __DIR__ . '/settings.php';
    $settings = getSettings($pdo);

    if (empty($settings['smtp_enabled'])) {
        return ['ok' => false, 'error' => 'Email is not enabled. Configure it in Admin → Settings → Email.'];
    }

    $rendered = renderEmailTemplate($pdo, $templateKey, $vars);
    if (!$rendered['ok']) {
        return $rendered;
    }

    $provider = $settings['smtp_provider'] ?? 'custom';

    if ($provider === 'gmail') {
        $result = sendViaGmailApi($settings, $toEmail, $toName, $rendered['subject'], $rendered['body']);
        return array_merge($result, ['subject' => $rendered['subject']]);
    }

    // Custom SMTP
    if (empty($settings['smtp_host'])) {
        return ['ok' => false, 'error' => 'SMTP host is not configured.'];
    }
    $mailer = new Mailer($settings);
    if (!$mailer->send($toEmail, $toName, $rendered['subject'], $rendered['body'])) {
        return ['ok' => false, 'error' => $mailer->lastError ?: 'Unknown SMTP error.'];
    }
    return ['ok' => true, 'subject' => $rendered['subject']];
}

// ============================================================
// Send a raw email (no template lookup) — subject + HTML body
// passed directly. Used for feedback reports, system alerts, etc.
// ============================================================
function sendDirectEmail(PDO $pdo, string $toEmail, string $toName, string $subject, string $htmlBody): array {
    require_once __DIR__ . '/settings.php';
    $settings = getSettings($pdo);

    if (empty($settings['smtp_enabled'])) {
        return ['ok' => false, 'error' => 'Email is not enabled.'];
    }

    $provider = $settings['smtp_provider'] ?? 'custom';

    if ($provider === 'gmail') {
        $result = sendViaGmailApi($settings, $toEmail, $toName, $subject, $htmlBody);
        return array_merge($result, ['subject' => $subject]);
    }

    if (empty($settings['smtp_host'])) {
        return ['ok' => false, 'error' => 'SMTP host is not configured.'];
    }
    $mailer = new Mailer($settings);
    if (!$mailer->send($toEmail, $toName, $subject, $htmlBody)) {
        return ['ok' => false, 'error' => $mailer->lastError ?: 'Unknown SMTP error.'];
    }
    return ['ok' => true, 'subject' => $subject];
}

// ============================================================
// Helper: send welcome email to a new user
// ============================================================
function sendWelcomeEmail(PDO $pdo, string $toEmail, string $toName, string $tempPassword): bool {
    require_once __DIR__ . '/settings.php';
    $settings = getSettings($pdo);

    if (empty($settings['smtp_enabled'])) return false;
    if (empty($settings['smtp_host']))    return false;

    $mailer      = new Mailer($settings);
    $companyName = htmlspecialchars($settings['company_name'] ?? APP_NAME);
    $loginUrl    = BASE_URL . '/index.php';
    $safeEmail   = htmlspecialchars($toEmail);
    $safeName    = htmlspecialchars($toName);
    $safePass    = htmlspecialchars($tempPassword);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><style>
  body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0;}
  .wrap{max-width:560px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);}
  .hdr{background:#1e40af;padding:28px 32px;}
  .hdr h1{color:#fff;margin:0;font-size:1.4rem;}
  .body{padding:32px;}
  .body p{color:#374151;line-height:1.6;margin:0 0 16px;}
  .cred{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:16px 20px;margin:20px 0;}
  .cred p{margin:4px 0;color:#111827;}
  .cred strong{color:#1e40af;}
  .btn{display:inline-block;background:#1e40af;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:8px;}
  .ftr{padding:20px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:.8rem;}
</style></head>
<body>
<div class="wrap">
  <div class="hdr"><h1>Welcome to {$companyName}</h1></div>
  <div class="body">
    <p>Hi {$safeName},</p>
    <p>An account has been created for you on the {$companyName} portal. Here are your login details:</p>
    <div class="cred">
      <p><strong>Portal URL:</strong> <a href="{$loginUrl}">{$loginUrl}</a></p>
      <p><strong>Email:</strong> {$safeEmail}</p>
      <p><strong>Password:</strong> {$safePass}</p>
    </div>
    <p>Please sign in and change your password as soon as possible.</p>
    <a href="{$loginUrl}" class="btn">Sign In Now</a>
    <p style="margin-top:24px;color:#6b7280;font-size:.85rem;">If you did not expect this email, please contact your system administrator.</p>
  </div>
  <div class="ftr">&copy; {$companyName} &mdash; This is an automated message, please do not reply.</div>
</div>
</body></html>
HTML;

    return $mailer->send($toEmail, $toName, "Welcome to $companyName — Your Account Details", $html);
}
