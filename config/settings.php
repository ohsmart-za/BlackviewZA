<?php
// ============================================================
// Blackview SA Portal — Settings Helpers
// ============================================================

/**
 * Fetch all settings as a key => value array.
 */
function getSettings(PDO $pdo) {
    $rows = $pdo->query("SELECT `key`, `value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

/**
 * Fetch a single setting value, returning $default if not found.
 */
function getSetting(PDO $pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = :k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? $val : $default;
}

/**
 * Upsert an associative array of settings into the settings table.
 */
function saveSettings(PDO $pdo, $data) {
    $stmt = $pdo->prepare(
        "INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE `value` = :v2"
    );
    foreach ($data as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
    }
}
