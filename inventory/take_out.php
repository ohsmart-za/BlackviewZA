<?php
// Redirected to unified Stock Operations page
require_once __DIR__ . '/../config/config.php';
header('Location: ' . BASE_URL . '/inventory/stock_operations.php?mode=take_out');
exit;
