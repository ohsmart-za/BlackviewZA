<?php
// ============================================================
// Blackview SA Portal — PDO Database Connection
// ============================================================

require_once __DIR__ . '/config.php';

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '3306';
        $dsn  = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, $port, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In production, log this and show a friendly error
            die('<div style="font-family:sans-serif;padding:2rem;color:#c00;">
                <h2>Database connection failed</h2>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
                <p>Please check your <code>config/config.php</code> settings.</p>
            </div>');
        }
    }
    return $pdo;
}
