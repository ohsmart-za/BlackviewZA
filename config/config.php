<?php
// ============================================================
// Blackview SA Portal — Application Configuration
// ============================================================

// --- Database credentials ---
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'blackview_portal');  // ← update to your cPanel DB name
define('DB_USER', 'root');              // ← update to your cPanel DB user
define('DB_PASS', '');                  // ← update to your cPanel DB password
define('DB_CHARSET', 'utf8mb4');

// --- Application constants ---
define('APP_NAME',    'Blackview SA Portal');
define('APP_VERSION', '1.0.0');

// Base URL — set this to match your environment
// Local:  'http://localhost/BlackviewZA'
// Server: 'https://b2b.blackview.co.za'
define('BASE_URL', 'http://localhost/BlackviewZA');

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
