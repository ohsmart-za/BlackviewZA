<?php
// ============================================================
// Blackview SA Portal — Issue / Feedback Report endpoint
// Called via AJAX from the floating report widget
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/mailer.php';

header('Content-Type: application/json');

// Must be logged in
if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$pdo = getDB();

$description = trim($_POST['description'] ?? '');
$pageUrl     = trim($_POST['page_url']    ?? '');
$pageTitle   = trim($_POST['page_title']  ?? '');

if ($description === '') {
    echo json_encode(['ok' => false, 'error' => 'Please enter a description.']);
    exit;
}

$reporterName  = $_SESSION['user_name']  ?? 'Unknown User';
$reporterEmail = $_SESSION['user_email'] ?? '';
$reporterRole  = ucfirst($_SESSION['user_role'] ?? 'user');
$reportedAt    = date('d M Y H:i:s');

// ---- Handle optional screenshot upload ----
$screenshotHtml = '';
$screenshotPath = '';

if (!empty($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['screenshot'];

    // Validate — images only, max 5 MB
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (in_array($mimeType, $allowedMimes, true) && $file['size'] <= 5 * 1024 * 1024) {
        $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
                   . 'uploads' . DIRECTORY_SEPARATOR . 'feedback';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mimeType];
        $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $screenshotPath = BASE_URL . '/assets/uploads/feedback/' . $safeName;
            $screenshotHtml = '
            <div style="margin-top:1.5rem;">
                <p style="font-weight:700;font-size:.85rem;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
                    Screenshot
                </p>
                <img src="' . $screenshotPath . '"
                     alt="Screenshot"
                     style="max-width:100%;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);">
                <p style="font-size:.75rem;color:#9ca3af;margin-top:.35rem;">' . htmlspecialchars($screenshotPath) . '</p>
            </div>';
        }
    }
}

// ---- Build HTML email ----
$descHtml = nl2br(htmlspecialchars($description));

$emailBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body
    style="font-family:\'Segoe UI\',Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;">

<div style="max-width:620px;margin:0 auto;background:#fff;border-radius:12px;
            border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">

    <!-- Header -->
    <div style="background:#1e3a5f;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:.75rem;">
        <span style="font-size:1.5rem;">🐛</span>
        <div>
            <div style="color:#fff;font-size:1.1rem;font-weight:700;">Issue Report</div>
            <div style="color:#93c5fd;font-size:.82rem;">Blackview SA Portal</div>
        </div>
    </div>

    <!-- Body -->
    <div style="padding:1.5rem;">

        <!-- Meta grid -->
        <table style="width:100%;border-collapse:collapse;margin-bottom:1.25rem;font-size:.875rem;">
            <tr>
                <td style="padding:.45rem .6rem;background:#f8fafc;border:1px solid #e5e7eb;
                           font-weight:600;color:#6b7280;width:130px;border-radius:4px 0 0 0;">
                    Reported by
                </td>
                <td style="padding:.45rem .6rem;border:1px solid #e5e7eb;border-left:none;">
                    <strong>' . htmlspecialchars($reporterName) . '</strong>
                    &lt;' . htmlspecialchars($reporterEmail) . '&gt;
                    &nbsp;<span style="background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:4px;
                                font-size:.75rem;font-weight:700;">' . $reporterRole . '</span>
                </td>
            </tr>
            <tr>
                <td style="padding:.45rem .6rem;background:#f8fafc;border:1px solid #e5e7eb;border-top:none;font-weight:600;color:#6b7280;">
                    Time
                </td>
                <td style="padding:.45rem .6rem;border:1px solid #e5e7eb;border-left:none;border-top:none;">
                    ' . $reportedAt . '
                </td>
            </tr>
            <tr>
                <td style="padding:.45rem .6rem;background:#f8fafc;border:1px solid #e5e7eb;border-top:none;font-weight:600;color:#6b7280;border-radius:0 0 0 4px;">
                    Page
                </td>
                <td style="padding:.45rem .6rem;border:1px solid #e5e7eb;border-left:none;border-top:none;font-size:.8rem;word-break:break-all;">
                    ' . ($pageTitle ? '<strong>' . htmlspecialchars($pageTitle) . '</strong><br>' : '') . '
                    <a href="' . htmlspecialchars($pageUrl) . '" style="color:#2563eb;">' . htmlspecialchars($pageUrl) . '</a>
                </td>
            </tr>
        </table>

        <!-- Description -->
        <p style="font-weight:700;font-size:.85rem;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
            Description
        </p>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;
                    border-radius:6px;padding:.85rem 1rem;font-size:.9rem;line-height:1.65;color:#1e293b;">
            ' . $descHtml . '
        </div>

        ' . $screenshotHtml . '

    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;border-top:1px solid #e5e7eb;padding:.85rem 1.5rem;
                font-size:.75rem;color:#9ca3af;text-align:center;">
        Sent from Blackview SA Portal feedback widget &mdash; ' . BASE_URL . '
    </div>
</div>
</body></html>';

// ---- Send ----
$subject = '[Issue Report] ' . ($pageTitle ?: parse_url($pageUrl, PHP_URL_PATH)) . ' — ' . $reporterName;
$result  = sendDirectEmail($pdo, 'ryno@ohsmart.co.za', 'Ryno', $subject, $emailBody);

// ---- Audit log ----
logAudit($pdo, 'feedback_report', 'users', (int)$_SESSION['user_id'],
    "Issue reported on: $pageUrl" . ($screenshotPath ? ' (with screenshot)' : ''));

if ($result['ok']) {
    echo json_encode(['ok' => true]);
} else {
    // Still log that the report was submitted, just email failed
    echo json_encode(['ok' => true, 'warning' => 'Report saved but email delivery failed: ' . ($result['error'] ?? '')]);
}
exit;
