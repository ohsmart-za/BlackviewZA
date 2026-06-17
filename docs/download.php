<?php
// ============================================================
// Blackview SA Portal — Secure Document Download
// Requires login — never exposes the real file path
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$pdo  = getDB();
$docId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($docId === 0) {
    http_response_code(400);
    exit('Invalid document ID.');
}

// Load document — must be active
$stmt = $pdo->prepare(
    "SELECT * FROM company_documents WHERE id = :id AND is_active = 1 LIMIT 1"
);
$stmt->execute([':id' => $docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Document not found or is no longer available.');
}

// Build absolute file path
$absPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
         . str_replace('/', DIRECTORY_SEPARATOR, $doc['file_path']);

if (!file_exists($absPath) || !is_readable($absPath)) {
    http_response_code(404);
    exit('File not found on server. Please contact an administrator.');
}

// Log the download
logAudit($pdo, 'download_company_doc', 'company_documents', $docId,
    "Downloaded: {$doc['title']}");

// Serve the file
$mime     = $doc['mime_type'] ?: 'application/octet-stream';
$fileName = $doc['file_name'] ?: basename($doc['file_path']);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
header('Content-Length: ' . filesize($absPath));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

ob_clean();
flush();
readfile($absPath);
exit;
