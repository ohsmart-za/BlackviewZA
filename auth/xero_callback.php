<?php
// ============================================================
// Blackview SA Portal — Xero OAuth2 callback
// Redirect URI registered in the Xero developer app must be:
//   <BASE_URL>/auth/xero_callback.php
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/xero_client.php';

requireAdmin();

try {
    if (isset($_GET['error'])) {
        throw new RuntimeException('Xero returned: ' . $_GET['error'] . ' — ' . ($_GET['error_description'] ?? ''));
    }
    if (empty($_GET['code']) || empty($_GET['state'])) {
        throw new RuntimeException('Missing code/state in Xero callback.');
    }
    XeroClient::exchangeCode($_GET['code'], $_GET['state']);
    logAudit(getDB(), 'xero_connect', 'settings', null, 'Connected to Xero');
    setFlash('success', 'Connected to Xero successfully.');
} catch (Throwable $e) {
    setFlash('error', 'Xero connection failed: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/admin/xero.php');
exit;
