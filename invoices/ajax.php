<?php
// ============================================================
// Blackview SA Portal — Invoice AJAX endpoints
// Clean file: no output except JSON
// ============================================================

ob_start(); // Buffer any stray warnings so JSON stays clean

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

ob_end_clean(); // Discard any PHP notices/warnings
header('Content-Type: application/json');

$pdo    = getDB();
$action = $_GET['ajax'] ?? '';

// ---- Customer search ----
if ($action === 'search_customer') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    try {
        $s = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    COALESCE(contact_type,'individual') AS contact_type,
                    COALESCE(company_name,'') AS company_name,
                    COALESCE(vat_no,'') AS vat_no
             FROM customers
             WHERE name LIKE :q OR email LIKE :q2 OR company_name LIKE :q3 OR phone LIKE :q4
             ORDER BY name ASC LIMIT 10"
        );
        $s->execute([':q'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like]);
    } catch (Throwable $e) {
        $s = $pdo->prepare(
            "SELECT id, name, email, phone, address, id_number,
                    'individual' AS contact_type, '' AS company_name, '' AS vat_no
             FROM customers WHERE name LIKE :q OR email LIKE :q2 OR phone LIKE :q3
             ORDER BY name ASC LIMIT 10"
        );
        $s->execute([':q'=>$like,':q2'=>$like,':q3'=>$like]);
    }
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ---- Product search ----
if ($action === 'search_product') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode([]); exit; }
    try {
        // Exact SKU match first
        $exact = $pdo->prepare(
            "SELECT id, name, sku, selling_price,
                    COALESCE(vat_rate,15) AS vat_rate,
                    COALESCE(is_serialised,1) AS is_serialised,
                    COALESCE(product_type,'physical') AS product_type
             FROM products WHERE sku = :q AND is_active = 1 LIMIT 1"
        );
        $exact->execute([':q' => $q]);
        $results = $exact->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            $like = $pdo->prepare(
                "SELECT id, name, sku, selling_price,
                        COALESCE(vat_rate,15) AS vat_rate,
                        COALESCE(is_serialised,1) AS is_serialised,
                        COALESCE(product_type,'physical') AS product_type
                 FROM products
                 WHERE (name LIKE :q OR sku LIKE :q2) AND is_active = 1
                 ORDER BY name ASC LIMIT 10"
            );
            $like->execute([':q' => '%'.$q.'%', ':q2' => '%'.$q.'%']);
            $results = $like->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($results as &$r) {
            $r['selling_price_incl'] = round((float)$r['selling_price'] * (1 + (float)$r['vat_rate'] / 100), 2);
            $r['is_serialised']      = (int)$r['is_serialised'];
        }
        unset($r);
    } catch (Throwable $e) {
        $results = [];
    }
    echo json_encode($results);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
