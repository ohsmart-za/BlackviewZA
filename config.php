<?php
// ============================================================
// Blackview SA Portal — Application Configuration
// ============================================================

// --- Database credentials ---
define('DB_HOST', 'sql12.cpt4.host-h.net');
define('DB_PORT', '3306');
define('DB_NAME', 'blackytykm_db6');
define('DB_USER', 'blackytykm_6_w');
define('DB_PASS', '9B9jWY6tv23k20rO97g1');
define('DB_CHARSET', 'utf8mb4');

// --- Application constants ---
define('APP_NAME',    'Blackview SA Portal');
define('APP_VERSION', '1.0.0');

// Auto-detect BASE_URL — works whether site is at domain root or in a subdirectory
$_scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$_host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($_basePath === '.' || $_basePath === '') $_basePath = '';
define('BASE_URL', $_scheme . '://' . $_host . $_basePath);
unset($_scheme, $_host, $_basePath);

// --- Session ---
define('SESSION_NAME',    'bvza_session');
define('SESSION_TIMEOUT', 7200); // 2 hours in seconds

// --- Pagination ---
define('AUDIT_PER_PAGE', 50);

// --- Timezone ---
date_default_timezone_set('Africa/Johannesburg');

// --- Error reporting (enable temporarily to diagnose, then set to 0) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
