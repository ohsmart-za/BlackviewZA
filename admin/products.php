<?php
// ============================================================
// Blackview SA Portal — Admin: Product Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/csv_helpers.php';

requireAdmin();

// ---- CSV template download — must be before any output ----
if (isset($_GET['dl_template']) && $_GET['dl_template'] === 'products') {
    csvTemplateDownload(
        'products_template.csv',
        ['sku', 'barcode', 'name', 'brand', 'category', 'description'],
        [['BV-NEW-001', '6001234567890', 'Blackview New Model', 'Blackview', 'Rugged Phone', 'Optional description']]
    );
    exit;
}

$pdo       = getDB();
$pageTitle = 'Product Management';
$errors    = [];
$editProduct = null;

// ---- Helper: get absolute project root ----
$projectRoot = dirname(__DIR__);
$productImgDir = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
if (!is_dir($productImgDir)) {
    mkdir($productImgDir, 0755, true);
}

// --- Toggle active ---
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tid  = (int)$_GET['toggle'];
    $stmt = $pdo->prepare('UPDATE products SET is_active = NOT is_active WHERE id = :id');
    $stmt->execute([':id' => $tid]);
    logAudit($pdo, 'toggle_product', 'products', $tid, 'Toggled product active status');
    setFlash('success', 'Product status updated.');
    header('Location: ' . BASE_URL . '/admin/products.php');
    exit;
}

// --- Delete (only if no stock exists) ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did  = (int)$_GET['delete'];
    $chk  = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE product_id = :id AND status = 'in_stock'");
    $chk->execute([':id' => $did]);
    if ((int)$chk->fetchColumn() > 0) {
        setFlash('error', 'Cannot delete product — it still has stock in the system. Deactivate it instead.');
    } else {
        // Delete product image if exists
        $imgStmt = $pdo->prepare('SELECT image_path FROM products WHERE id = :id LIMIT 1');
        $imgStmt->execute([':id' => $did]);
        $imgRow = $imgStmt->fetch();
        if ($imgRow && !empty($imgRow['image_path'])) {
            $absImg = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imgRow['image_path']);
            if (file_exists($absImg)) {
                unlink($absImg);
            }
        }
        $pdo->prepare('DELETE FROM products WHERE id = :id')->execute([':id' => $did]);
        logAudit($pdo, 'delete_product', 'products', $did, 'Deleted product');
        setFlash('success', 'Product deleted.');
    }
    header('Location: ' . BASE_URL . '/admin/products.php');
    exit;
}

// --- Load for edit ---
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editProduct = $stmt->fetch();
}

// ============================================================
// CSV IMPORT
// ============================================================
$csvImported   = 0;
$csvErrors     = [];
$csvShowResult = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csv_action'] ?? '') === 'products') {
    $csvShowResult = true;
    $parsed = parseUploadedCsv($_FILES['csv_file'] ?? []);

    if ($parsed['error'] !== null) {
        $csvErrors[] = $parsed['error'];
    } else {
        $requiredHeaders = ['sku', 'name'];
        $missingHeaders  = array_diff($requiredHeaders, $parsed['headers']);
        if (!empty($missingHeaders)) {
            $csvErrors[] = 'CSV is missing required column(s): ' . implode(', ', $missingHeaders);
        }
    }

    if (empty($csvErrors) && $parsed['error'] === null) {
        $rowErrors  = [];
        $validRows  = [];
        $seenSkus   = [];

        foreach ($parsed['rows'] as $idx => $row) {
            $rowNum      = $idx + 2;
            $skuRaw      = trim($row['sku']         ?? '');
            $barcodeRaw  = trim($row['barcode']     ?? '') ?: null;
            $nameRaw     = trim($row['name']        ?? '');
            $brandRaw    = trim($row['brand']       ?? 'Blackview');
            $categoryRaw = trim($row['category']    ?? '');
            $descRaw     = trim($row['description'] ?? '');

            // Skip entirely blank rows
            if ($skuRaw === '' && $nameRaw === '') {
                continue;
            }

            $rowOk = true;

            if ($skuRaw === '') {
                $rowErrors[] = "Row $rowNum: SKU is required.";
                $rowOk = false;
            }
            if ($nameRaw === '') {
                $rowErrors[] = "Row $rowNum: Name is required.";
                $rowOk = false;
            }

            if ($rowOk && isset($seenSkus[strtolower($skuRaw)])) {
                $rowErrors[] = "Row $rowNum: SKU \"$skuRaw\" appears more than once in the CSV — skipped.";
                $rowOk = false;
            }

            if ($rowOk) {
                $seenSkus[strtolower($skuRaw)] = true;
                $validRows[] = [
                    'sku'         => $skuRaw,
                    'barcode'     => $barcodeRaw,
                    'name'        => $nameRaw,
                    'brand'       => $brandRaw !== '' ? $brandRaw : 'Blackview',
                    'category'    => $categoryRaw,
                    'description' => $descRaw,
                ];
            }
        }

        // Check which SKUs already exist in DB
        if (!empty($validRows)) {
            $allSkus = array_column($validRows, 'sku');
            $inSql   = implode(',', array_fill(0, count($allSkus), '?'));
            $chkStmt = $pdo->prepare("SELECT sku FROM products WHERE sku IN ($inSql)");
            $chkStmt->execute($allSkus);
            $existingSkus = array_flip(array_map('strtolower', $chkStmt->fetchAll(PDO::FETCH_COLUMN)));

            $cleanRows = [];
            foreach ($validRows as $vr) {
                if (isset($existingSkus[strtolower($vr['sku'])])) {
                    $rowErrors[] = 'SKU "' . $vr['sku'] . '" already exists in the database — skipped.';
                } else {
                    $cleanRows[] = $vr;
                }
            }
            $validRows = $cleanRows;
        }

        $csvErrors = array_merge($csvErrors, $rowErrors);

        // Insert valid rows
        if (!empty($validRows)) {
            try {
                $pdo->beginTransaction();

                $ins = $pdo->prepare(
                    'INSERT INTO products (sku, barcode, name, brand, category, description, is_active, created_at)
                     VALUES (:sku, :barcode, :name, :brand, :cat, :desc, 1, NOW())'
                );
                foreach ($validRows as $vr) {
                    $ins->execute([
                        ':sku'     => $vr['sku'],
                        ':barcode' => $vr['barcode'],
                        ':name'    => $vr['name'],
                        ':brand'   => $vr['brand'],
                        ':cat'     => $vr['category'],
                        ':desc'    => $vr['description'],
                    ]);
                }

                $importedCount = count($validRows);
                logAudit($pdo, 'csv_import_products', 'products', null,
                    "CSV bulk import: added $importedCount product(s). SKUs: "
                    . implode(', ', array_column($validRows, 'sku'))
                );

                $pdo->commit();
                $csvImported = $importedCount;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $csvErrors[] = 'Database error during import: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================
// MANUAL FORM POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['csv_action'])) {
    $action       = $_POST['action']       ?? '';
    $productId    = (int)($_POST['product_id']  ?? 0);
    $sku          = trim($_POST['sku']          ?? '');
    $barcode      = trim($_POST['barcode']      ?? '') ?: null;
    $name         = trim($_POST['name']         ?? '');
    $brand        = trim($_POST['brand']        ?? 'Blackview');
    $category     = trim($_POST['category']     ?? '');
    $description  = trim($_POST['description']  ?? '');
    $costPrice    = (float)($_POST['cost_price']    ?? 0);
    $sellingPrice = (float)($_POST['selling_price'] ?? 0);
    $vatRate      = (float)($_POST['vat_rate']      ?? 15.00);
    $isFeatured   = isset($_POST['is_featured'])   ? 1 : 0;
    $isSerialized = isset($_POST['is_serialised'])  ? 1 : 0;
    $productType  = ($_POST['product_type'] ?? 'physical') === 'service' ? 'service' : 'physical';
    // Services are never serialised
    if ($productType === 'service') $isSerialized = 0;

    if ($sku  === '') $errors[] = 'SKU is required.';
    if ($name === '') $errors[] = 'Product name is required.';

    // Check SKU uniqueness
    if (empty($errors)) {
        $skuCheck = $pdo->prepare('SELECT id FROM products WHERE sku = :sku AND id != :id LIMIT 1');
        $skuCheck->execute([':sku' => $sku, ':id' => $productId]);
        if ($skuCheck->fetch()) {
            $errors[] = "SKU \"$sku\" is already in use by another product.";
        }
    }

    // Handle image upload
    $uploadedImagePath = null;
    $imageUploadError  = null;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $imgFile = $_FILES['product_image'];
        if ($imgFile['error'] !== UPLOAD_ERR_OK) {
            $imageUploadError = 'Image upload error (code ' . $imgFile['error'] . ').';
        } elseif ($imgFile['size'] > 3 * 1024 * 1024) {
            $imageUploadError = 'Product image exceeds 3 MB limit.';
        } else {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $imgFile['tmp_name']);
            finfo_close($finfo);

            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            $mimeToExt    = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

            if (!in_array($mimeType, $allowedMimes, true)) {
                $imageUploadError = 'Product image must be PNG, JPG, or WEBP.';
            } else {
                // We need the product ID to name the file; for new products we'll handle after insert
                if ($action === 'edit' && $productId > 0) {
                    $ext      = $mimeToExt[$mimeType];
                    $destFile = $productImgDir . DIRECTORY_SEPARATOR . $productId . '.' . $ext;

                    // Remove old image for this product (any extension)
                    foreach (['png', 'jpg', 'jpeg', 'webp'] as $oldExt) {
                        $oldFile = $productImgDir . DIRECTORY_SEPARATOR . $productId . '.' . $oldExt;
                        if (file_exists($oldFile)) {
                            unlink($oldFile);
                        }
                    }

                    if (!move_uploaded_file($imgFile['tmp_name'], $destFile)) {
                        $imageUploadError = 'Failed to save product image.';
                    } else {
                        $uploadedImagePath = 'assets/uploads/products/' . $productId . '.' . $ext;
                    }
                } else {
                    // For new products: store tmp path and handle after insert
                    $_SESSION['_pending_product_img'] = [
                        'tmp'  => $imgFile['tmp_name'],
                        'mime' => $mimeType,
                        'ext'  => $mimeToExt[$mimeType],
                    ];
                    // Note: tmp_name is still valid — we'll move after insert
                }
            }
        }
        if ($imageUploadError) {
            $errors[] = $imageUploadError;
        }
    }

    if (empty($errors)) {
        if ($action === 'add') {
            $ins = $pdo->prepare(
                'INSERT INTO products (sku, barcode, name, brand, category, product_type, description, cost_price, selling_price, vat_rate, is_featured, is_serialised, is_active, created_at)
                 VALUES (:sku, :barcode, :name, :brand, :cat, :ptype, :desc, :cost, :sell, :vat, :feat, :ser, 1, NOW())'
            );
            $ins->execute([
                ':sku'     => $sku,
                ':barcode' => $barcode,
                ':name'    => $name,
                ':brand'   => $brand,
                ':cat'     => $category,
                ':ptype'   => $productType,
                ':desc'    => $description,
                ':cost'    => $costPrice,
                ':sell'    => $sellingPrice,
                ':vat'     => $vatRate,
                ':feat'    => $isFeatured,
                ':ser'     => $isSerialized,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Move pending image now that we have the ID
            if (isset($_SESSION['_pending_product_img'])) {
                $pending = $_SESSION['_pending_product_img'];
                unset($_SESSION['_pending_product_img']);
                $ext      = $pending['ext'];
                $destFile = $productImgDir . DIRECTORY_SEPARATOR . $newId . '.' . $ext;
                if (move_uploaded_file($pending['tmp'], $destFile)) {
                    $imgPath = 'assets/uploads/products/' . $newId . '.' . $ext;
                    $pdo->prepare('UPDATE products SET image_path = :p WHERE id = :id')
                        ->execute([':p' => $imgPath, ':id' => $newId]);
                }
            }

            logAudit($pdo, 'create_product', 'products', $newId, "Created product \"$name\" (SKU: $sku)");
            setFlash('success', "Product \"$name\" created.");
            header('Location: ' . BASE_URL . '/admin/products.php');
            exit;

        } elseif ($action === 'edit' && $productId > 0) {
            // Build update with optional image_path
            if ($uploadedImagePath !== null) {
                $upd = $pdo->prepare(
                    'UPDATE products SET sku=:sku, barcode=:barcode, name=:name, brand=:brand, category=:cat, product_type=:ptype, description=:desc,
                     cost_price=:cost, selling_price=:sell, vat_rate=:vat, is_featured=:feat, is_serialised=:ser, image_path=:img WHERE id=:id'
                );
                $upd->execute([
                    ':sku'     => $sku,
                    ':barcode' => $barcode,
                    ':name'    => $name,
                    ':brand'   => $brand,
                    ':cat'     => $category,
                    ':ptype'   => $productType,
                    ':desc'    => $description,
                    ':cost'    => $costPrice,
                    ':sell'    => $sellingPrice,
                    ':vat'     => $vatRate,
                    ':feat'    => $isFeatured,
                    ':ser'     => $isSerialized,
                    ':img'     => $uploadedImagePath,
                    ':id'      => $productId,
                ]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE products SET sku=:sku, barcode=:barcode, name=:name, brand=:brand, category=:cat, product_type=:ptype, description=:desc,
                     cost_price=:cost, selling_price=:sell, vat_rate=:vat, is_featured=:feat, is_serialised=:ser WHERE id=:id'
                );
                $upd->execute([
                    ':sku'     => $sku,
                    ':barcode' => $barcode,
                    ':name'    => $name,
                    ':brand'   => $brand,
                    ':cat'     => $category,
                    ':ptype'   => $productType,
                    ':desc'    => $description,
                    ':cost'    => $costPrice,
                    ':sell'    => $sellingPrice,
                    ':vat'     => $vatRate,
                    ':feat'    => $isFeatured,
                    ':ser'     => $isSerialized,
                    ':id'      => $productId,
                ]);
            }
            logAudit($pdo, 'edit_product', 'products', $productId, "Updated product \"$name\" (SKU: $sku)");
            setFlash('success', "Product \"$name\" updated.");
            header('Location: ' . BASE_URL . '/admin/products.php');
            exit;
        }
    }

    // Re-populate form on error
    $editProduct = [
        'id'            => $productId,
        'sku'           => $sku,
        'barcode'       => $_POST['barcode'] ?? '',
        'name'          => $name,
        'brand'         => $brand,
        'category'      => $category,
        'product_type'  => $productType,
        'description'   => $description,
        'cost_price'    => $costPrice,
        'selling_price' => $sellingPrice,
        'vat_rate'      => $vatRate,
        'is_featured'   => $isFeatured,
        'is_serialised' => $isSerialized,
        'image_path'    => '',
    ];
}

// Fetch products with current stock totals
$products = $pdo->query(
    "SELECT p.*, COALESCE(p.is_serialised, 1) AS is_serialised,
            COALESCE(p.product_type, 'physical') AS product_type,
            COALESCE(SUM(inv.qty), 0) AS total_stock
     FROM products p
     LEFT JOIN inventory_stock inv ON inv.product_id = p.id
     GROUP BY p.id
     ORDER BY p.name ASC"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 class="page-title">Product Management</h2>
        <p class="page-subtitle">Click any row to edit. Add or import products below.</p>
    </div>
    <div style="display:flex;gap:.5rem;">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('csv-modal').style.display='';document.body.style.overflow='hidden';">CSV Import</button>
        <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Add Product</button>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $err): ?><div><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================================
     PRODUCTS TABLE (full width)
     ============================================================ -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <h3 class="card-title" style="margin:0;">All Products</h3>
        <input type="text" id="prod-filter" class="form-control" style="max-width:280px;"
               placeholder="Filter by name, SKU or barcode…" autocomplete="off">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="prod-table">
            <thead>
                <tr>
                    <th style="width:52px;"></th>
                    <th>SKU / Barcode</th>
                    <th>Name</th>
                    <th>Brand</th>
                    <th>Category</th>
                    <th class="text-right">Selling Price</th>
                    <th class="text-right">Stock</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th style="width:90px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $prod): ?>
                <tr class="prod-row <?= !$prod['is_active'] ? 'row-inactive' : '' ?>"
                    style="cursor:pointer;"
                    data-id="<?= $prod['id'] ?>"
                    data-sku="<?= htmlspecialchars($prod['sku'], ENT_QUOTES) ?>"
                    data-barcode="<?= htmlspecialchars($prod['barcode'] ?? '', ENT_QUOTES) ?>"
                    data-name="<?= htmlspecialchars($prod['name'], ENT_QUOTES) ?>"
                    data-brand="<?= htmlspecialchars($prod['brand'] ?? '', ENT_QUOTES) ?>"
                    data-category="<?= htmlspecialchars($prod['category'] ?? '', ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($prod['description'] ?? '', ENT_QUOTES) ?>"
                    data-cost="<?= number_format((float)($prod['cost_price'] ?? 0), 2, '.', '') ?>"
                    data-sell="<?= number_format((float)($prod['selling_price'] ?? 0), 2, '.', '') ?>"
                    data-vat="<?= number_format((float)($prod['vat_rate'] ?? 15), 2, '.', '') ?>"
                    data-featured="<?= !empty($prod['is_featured']) ? '1' : '0' ?>"
                    data-serialised="<?= !empty($prod['is_serialised']) ? '1' : '0' ?>"
                    data-ptype="<?= htmlspecialchars($prod['product_type'] ?? 'physical', ENT_QUOTES) ?>"
                    data-image="<?= htmlspecialchars($prod['image_path'] ?? '', ENT_QUOTES) ?>"
                    onclick="openEditModal(this)">

                    <td style="padding:.3rem .5rem;vertical-align:middle;" onclick="event.stopPropagation()">
                        <?php if (!empty($prod['image_path'])): ?>
                            <img src="<?= BASE_URL . '/' . htmlspecialchars($prod['image_path']) ?>"
                                 alt="" style="width:42px;height:42px;object-fit:contain;border-radius:4px;border:1px solid var(--color-border);">
                        <?php else: ?>
                            <div style="width:42px;height:42px;background:#F1F5F9;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:1rem;color:#CBD5E1;">&#128247;</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code style="font-size:.82rem;"><?= htmlspecialchars($prod['sku']) ?></code>
                        <?php if (!empty($prod['barcode'])): ?>
                            <br><span style="font-size:.72rem;color:#9CA3AF;"><?= htmlspecialchars($prod['barcode']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:500;"><?= htmlspecialchars($prod['name']) ?></td>
                    <td style="color:#6B7280;"><?= htmlspecialchars($prod['brand'] ?? '') ?></td>
                    <td style="color:#6B7280;"><?= htmlspecialchars($prod['category'] ?? '') ?></td>
                    <td class="text-right">R <?= number_format((float)$prod['selling_price'], 2) ?></td>
                    <td class="text-right">
                        <?php if (($prod['product_type'] ?? 'physical') === 'service'): ?>
                            <span style="color:#9CA3AF;font-size:.8rem;">—</span>
                        <?php else: ?>
                            <strong><?= number_format((float)$prod['total_stock']) ?></strong>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($prod['product_type'] ?? 'physical') === 'service'): ?>
                            <span style="background:#EDE9FE;color:#6D28D9;padding:1px 7px;border-radius:4px;font-size:.78rem;font-weight:600;">Service</span>
                        <?php elseif (!empty($prod['is_serialised'])): ?>
                            <span style="color:#94A3B8;font-size:.8rem;">Serial</span>
                        <?php else: ?>
                            <span style="background:#FEF9C3;color:#854D0E;padding:1px 7px;border-radius:4px;font-size:.78rem;">Bulk</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-dot <?= $prod['is_active'] ? 'status-active' : 'status-inactive' ?>"></span>
                        <?= $prod['is_active'] ? 'Active' : 'Inactive' ?>
                    </td>
                    <td class="action-btns" onclick="event.stopPropagation()">
                        <a href="?toggle=<?= $prod['id'] ?>"
                           class="btn btn-sm <?= $prod['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                           onclick="return confirm('Toggle product status?')">
                            <?= $prod['is_active'] ? 'Disable' : 'Enable' ?>
                        </a>
                        <?php if ($prod['total_stock'] == 0): ?>
                        <a href="?delete=<?= $prod['id'] ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Permanently delete this product? This cannot be undone.')">
                            Delete
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:2rem;">No products yet. Click <strong>+ Add Product</strong> to get started.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ============================================================
     MODAL: Add / Edit Product
     ============================================================ -->
<div id="prod-modal" style="display:none;position:fixed;inset:0;z-index:10000;overflow-y:auto;padding:1.5rem 1rem;">
    <div style="position:fixed;inset:0;background:rgba(15,23,42,.45);" onclick="closeProdModal()"></div>
    <div style="position:relative;margin:0 auto;max-width:640px;background:#fff;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.5rem;border-bottom:1px solid #E5E7EB;background:#F8FAFC;">
            <h3 id="prod-modal-title" style="margin:0;font-size:1.05rem;font-weight:700;">Edit Product</h3>
            <button type="button" onclick="closeProdModal()"
                    style="background:none;border:none;width:32px;height:32px;border-radius:6px;cursor:pointer;color:#9CA3AF;font-size:1.3rem;line-height:1;display:flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">&times;</button>
        </div>

        <!-- Body -->
        <div style="padding:1.5rem;max-height:calc(100vh - 10rem);overflow-y:auto;">
            <form id="prod-form" method="POST" action="" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action"     id="prod-modal-action"  value="edit">
                <input type="hidden" name="product_id" id="prod-modal-pid"     value="0">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">SKU <span class="required">*</span></label>
                        <input type="text" name="sku" id="pm-sku" class="form-control" placeholder="e.g. BV9300-BLK" required>
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Barcode</label>
                        <input type="text" name="barcode" id="pm-barcode" class="form-control" placeholder="e.g. 6001234567890">
                    </div>
                </div>

                <div class="form-group" style="margin:0 0 1rem;">
                    <label class="form-label">Product Name <span class="required">*</span></label>
                    <input type="text" name="name" id="pm-name" class="form-control" placeholder="e.g. Blackview BV9300" required>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Brand</label>
                        <input type="text" name="brand" id="pm-brand" class="form-control" placeholder="Blackview">
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Category</label>
                        <input type="text" name="category" id="pm-category" class="form-control" placeholder="e.g. Rugged Phone">
                    </div>
                </div>

                <div class="form-group" style="margin:0 0 1rem;">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="pm-description" class="form-control" rows="2" placeholder="Optional…"></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.75rem;align-items:end;">
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Cost (R)</label>
                        <input type="number" name="cost_price" id="pm-cost" class="form-control" step="0.01" min="0" value="0.00">
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">VAT %</label>
                        <input type="number" name="vat_rate" id="pm-vat" class="form-control" step="0.01" min="0" value="15.00">
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Price excl. VAT</label>
                        <input type="number" name="selling_price" id="pm-excl" class="form-control" step="0.01" min="0" value="0.00">
                    </div>
                    <div class="form-group" style="margin:0 0 1rem;">
                        <label class="form-label">Price incl. VAT</label>
                        <input type="number" id="pm-incl" class="form-control" step="0.01" min="0" placeholder="Auto">
                    </div>
                </div>
                <small class="form-hint" style="display:block;margin-top:-.5rem;margin-bottom:1rem;">Enter either price — the other updates automatically. Only excl. VAT is saved.</small>

                <div class="form-group" style="margin:0 0 1rem;">
                    <label class="form-label">Product Image</label>
                    <div id="pm-img-preview" style="display:none;margin-bottom:.5rem;"></div>
                    <input type="file" name="product_image" id="pm-img-file" class="form-control" accept=".png,.jpg,.jpeg,.webp">
                    <small class="form-hint">PNG, JPG, WEBP — max 3 MB. Leave blank to keep existing.</small>
                </div>

                <!-- Product type toggle -->
                <div class="form-group" style="margin:0 0 1rem;">
                    <label class="form-label">Product Type</label>
                    <div style="display:flex;gap:.5rem;">
                        <button type="button" id="pm-type-physical" onclick="setProdType('physical')"
                                class="btn btn-sm btn-primary" style="flex:1;">📦 Physical</button>
                        <button type="button" id="pm-type-service" onclick="setProdType('service')"
                                class="btn btn-sm btn-outline" style="flex:1;">🔧 Service</button>
                    </div>
                    <input type="hidden" name="product_type" id="pm-product-type" value="physical">
                    <small class="form-hint">Service = no stock tracking (e.g. Shipping, Installation).</small>
                </div>

                <!-- Physical-only options (hidden for services) -->
                <div id="pm-physical-options">
                    <div style="display:flex;gap:2rem;margin-bottom:1.25rem;">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;">
                            <input type="checkbox" name="is_featured" id="pm-featured" value="1">
                            Featured (POS Quick Pick)
                        </label>
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;">
                            <input type="checkbox" name="is_serialised" id="pm-serialised" value="1" checked>
                            Has serial numbers
                        </label>
                    </div>
                </div>
                <!-- Service: featured still available, serial hidden -->
                <div id="pm-service-options" style="display:none;margin-bottom:1.25rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;">
                        <input type="checkbox" name="is_featured" id="pm-featured-svc" value="1">
                        Featured (POS Quick Pick)
                    </label>
                </div>

                <div style="display:flex;gap:.75rem;padding-top:1rem;border-top:1px solid #F1F5F9;">
                    <button type="submit" class="btn btn-primary" id="pm-submit">Save Changes</button>
                    <button type="button" class="btn btn-outline" onclick="closeProdModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ============================================================
     MODAL: CSV Import
     ============================================================ -->
<div id="csv-modal" style="display:none;position:fixed;inset:0;z-index:10000;overflow-y:auto;padding:1.5rem 1rem;">
    <div style="position:fixed;inset:0;background:rgba(15,23,42,.45);" onclick="closeCsvModal()"></div>
    <div style="position:relative;margin:0 auto;max-width:520px;background:#fff;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;">

        <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.5rem;border-bottom:1px solid #E5E7EB;background:#F8FAFC;">
            <h3 style="margin:0;font-size:1.05rem;font-weight:700;">CSV Import</h3>
            <button type="button" onclick="closeCsvModal()"
                    style="background:none;border:none;width:32px;height:32px;border-radius:6px;cursor:pointer;color:#9CA3AF;font-size:1.3rem;line-height:1;display:flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">&times;</button>
        </div>

        <div style="padding:1.5rem;">
            <p style="margin:0 0 1rem;color:#6B7280;font-size:.9rem;">
                Columns: <code>sku</code>, <code>barcode</code>, <code>name</code>, <code>brand</code>, <code>category</code>, <code>description</code>.
                Barcode is optional. SKU and Name are required.
            </p>
            <div style="margin-bottom:1rem;">
                <a href="?dl_template=products" class="btn btn-outline btn-sm">Download Template CSV</a>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csv_action" value="products">
                <div class="form-group">
                    <label class="form-label">Select CSV file <span class="required">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                    <small class="form-hint">.csv or .txt — max 5 MB</small>
                </div>
                <div style="display:flex;gap:.75rem;padding-top:.5rem;">
                    <button type="submit" class="btn btn-primary">Import</button>
                    <button type="button" class="btn btn-outline" onclick="closeCsvModal()">Cancel</button>
                </div>
            </form>

            <?php if ($csvShowResult): ?>
            <div style="margin-top:1.25rem;">
                <?php if ($csvImported > 0): ?>
                    <div class="alert alert-success"><strong><?= $csvImported ?> product(s) imported successfully.</strong></div>
                <?php endif; ?>
                <?php if (!empty($csvErrors)): ?>
                    <div class="alert alert-error">
                        <strong><?= count($csvErrors) ?> row(s) had issues:</strong>
                        <ul style="margin:.5rem 0 0;padding-left:1.2rem;">
                            <?php foreach ($csvErrors as $ce): ?>
                                <li style="font-size:.85rem;"><?= htmlspecialchars($ce) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($csvImported === 0 && empty($csvErrors)): ?>
                    <div class="alert alert-warning">No valid rows found in the uploaded file.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<script>
// ---- Table filter ----
document.getElementById('prod-filter').addEventListener('input', function(){
    var q = this.value.toLowerCase();
    document.querySelectorAll('#prod-table tbody .prod-row').forEach(function(row){
        var text = (row.dataset.name+' '+row.dataset.sku+' '+(row.dataset.barcode||'')).toLowerCase();
        row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
    });
});

// ---- Product modal ----
var prodModal = document.getElementById('prod-modal');

function setProdType(type) {
    document.getElementById('pm-product-type').value = type;
    document.getElementById('pm-type-physical').className = 'btn btn-sm ' + (type === 'physical' ? 'btn-primary' : 'btn-outline');
    document.getElementById('pm-type-service').className  = 'btn btn-sm ' + (type === 'service'  ? 'btn-primary' : 'btn-outline');
    document.getElementById('pm-physical-options').style.display = type === 'physical' ? '' : 'none';
    document.getElementById('pm-service-options').style.display  = type === 'service'  ? '' : 'none';
    // Sync featured checkbox between physical/service panels
    var featPhys = document.getElementById('pm-featured');
    var featSvc  = document.getElementById('pm-featured-svc');
    if (type === 'service') featSvc.checked = featPhys.checked;
    else featPhys.checked = featSvc.checked;
}
window.setProdType = setProdType;

function openAddModal() {
    document.getElementById('prod-modal-title').textContent = 'Add Product';
    document.getElementById('prod-modal-action').value      = 'add';
    document.getElementById('prod-modal-pid').value         = '0';
    document.getElementById('pm-submit').textContent        = 'Add Product';
    document.getElementById('prod-form').reset();
    document.getElementById('pm-vat').value  = '15.00';
    document.getElementById('pm-cost').value = '0.00';
    document.getElementById('pm-excl').value = '0.00';
    document.getElementById('pm-incl').value = '';
    document.getElementById('pm-serialised').checked = true;
    document.getElementById('pm-featured').checked   = false;
    document.getElementById('pm-img-preview').style.display = 'none';
    setProdType('physical');
    prodModal.style.display = '';
    document.body.style.overflow = 'hidden';
    setTimeout(function(){ document.getElementById('pm-sku').focus(); }, 50);
}

function openEditModal(row) {
    var d = row.dataset;
    document.getElementById('prod-modal-title').textContent = 'Edit Product';
    document.getElementById('prod-modal-action').value      = 'edit';
    document.getElementById('prod-modal-pid').value         = d.id;
    document.getElementById('pm-submit').textContent        = 'Save Changes';
    document.getElementById('pm-sku').value         = d.sku;
    document.getElementById('pm-barcode').value     = d.barcode  || '';
    document.getElementById('pm-name').value        = d.name;
    document.getElementById('pm-brand').value       = d.brand    || '';
    document.getElementById('pm-category').value    = d.category || '';
    document.getElementById('pm-description').value = d.description || '';
    document.getElementById('pm-cost').value        = d.cost;
    document.getElementById('pm-excl').value        = d.sell;
    document.getElementById('pm-vat').value         = d.vat;
    document.getElementById('pm-featured').checked  = d.featured   === '1';
    document.getElementById('pm-serialised').checked = d.serialised === '1';
    setProdType(d.ptype || 'physical');
    // Calc incl
    var excl = parseFloat(d.sell) || 0, vat = parseFloat(d.vat) || 15;
    document.getElementById('pm-incl').value = excl ? (excl * (1 + vat/100)).toFixed(2) : '';
    // Image preview
    var prev = document.getElementById('pm-img-preview');
    if (d.image) {
        prev.innerHTML = '<img src="<?= BASE_URL ?>/' + d.image + '" style="width:72px;height:72px;object-fit:contain;border:1px solid #E5E7EB;border-radius:6px;padding:.2rem;background:#fff;">';
        prev.style.display = '';
    } else {
        prev.style.display = 'none';
    }
    document.getElementById('pm-img-file').value = '';
    prodModal.style.display = '';
    document.body.style.overflow = 'hidden';
}

function closeProdModal() {
    prodModal.style.display = 'none';
    document.body.style.overflow = '';
}

// ---- CSV modal ----
function closeCsvModal() {
    document.getElementById('csv-modal').style.display = 'none';
    document.body.style.overflow = '';
}

// Escape key closes whichever modal is open
document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    closeProdModal();
    closeCsvModal();
});

// ---- Price excl/incl sync ----
(function(){
    var excl = document.getElementById('pm-excl');
    var incl = document.getElementById('pm-incl');
    var vat  = document.getElementById('pm-vat');
    function rate(){ return (parseFloat(vat.value)||15)/100; }
    excl.addEventListener('input', function(){
        var v = parseFloat(excl.value)||0;
        incl.value = v ? (v*(1+rate())).toFixed(2) : '';
    });
    incl.addEventListener('input', function(){
        var v = parseFloat(incl.value)||0;
        excl.value = v ? (v/(1+rate())).toFixed(4) : '0.0000';
    });
    vat.addEventListener('change', function(){
        var v = parseFloat(excl.value)||0;
        if (v) incl.value = (v*(1+rate())).toFixed(2);
    });
}());

<?php if ($csvShowResult): ?>
// Auto-open CSV modal after import attempt
window.addEventListener('load', function(){
    document.getElementById('csv-modal').style.display = '';
    document.body.style.overflow = 'hidden';
});
<?php endif; ?>

<?php if (!empty($errors)): ?>
// Re-open product modal on validation error
window.addEventListener('load', function(){
    document.getElementById('prod-modal-action').value       = <?= json_encode($_POST['action'] ?? 'add') ?>;
    document.getElementById('prod-modal-pid').value          = <?= (int)($_POST['product_id'] ?? 0) ?>;
    document.getElementById('prod-modal-title').textContent  = <?= json_encode(($_POST['action'] ?? 'add') === 'add' ? 'Add Product' : 'Edit Product') ?>;
    document.getElementById('pm-submit').textContent         = <?= json_encode(($_POST['action'] ?? 'add') === 'add' ? 'Add Product' : 'Save Changes') ?>;
    document.getElementById('pm-sku').value         = <?= json_encode($_POST['sku']          ?? '') ?>;
    document.getElementById('pm-barcode').value     = <?= json_encode($_POST['barcode']      ?? '') ?>;
    document.getElementById('pm-name').value        = <?= json_encode($_POST['name']         ?? '') ?>;
    document.getElementById('pm-brand').value       = <?= json_encode($_POST['brand']        ?? '') ?>;
    document.getElementById('pm-category').value    = <?= json_encode($_POST['category']     ?? '') ?>;
    document.getElementById('pm-description').value = <?= json_encode($_POST['description']  ?? '') ?>;
    document.getElementById('pm-cost').value        = <?= json_encode($_POST['cost_price']   ?? '0.00') ?>;
    document.getElementById('pm-excl').value        = <?= json_encode($_POST['selling_price']?? '0.00') ?>;
    document.getElementById('pm-vat').value         = <?= json_encode($_POST['vat_rate']     ?? '15.00') ?>;
    document.getElementById('pm-featured').checked  = <?= !empty($_POST['is_featured'])  ? 'true' : 'false' ?>;
    document.getElementById('pm-serialised').checked= <?= !empty($_POST['is_serialised']) ? 'true' : 'false' ?>;
    setProdType(<?= json_encode($_POST['product_type'] ?? 'physical') ?>);
    document.getElementById('pm-img-preview').style.display = 'none';
    prodModal.style.display = '';
    document.body.style.overflow = 'hidden';
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
