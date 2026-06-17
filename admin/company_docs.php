<?php
// ============================================================
// Blackview SA Portal — Admin: Company Documents
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

requireAdmin();

$pdo       = getDB();
$pageTitle = 'Company Documents';
$errors    = [];

// ---- Upload directory ----
$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
           . 'uploads' . DIRECTORY_SEPARATOR . 'company_docs';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ---- Allowed MIME types ----
$allowedMimes = [
    'application/pdf'                                                        => 'pdf',
    'application/msword'                                                     => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=> 'docx',
    'application/vnd.ms-excel'                                               => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'      => 'xlsx',
    'image/jpeg'                                                             => 'jpg',
    'image/png'                                                              => 'png',
];

// ---- Preset categories ----
$presetCategories = [
    'Legal & Compliance',
    'Banking',
    'Certifications',
    'Tax',
    'Insurance',
    'Company Registration',
    'Contracts & Templates',
    'Other',
];

// ============================================================
// Handle POST actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Upload new document ----
    if ($action === 'upload') {
        $title       = trim($_POST['title']       ?? '');
        $category    = trim($_POST['category']    ?? 'General');
        $customCat   = trim($_POST['category_custom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        // Use custom category if typed
        if ($category === '__custom__' && $customCat !== '') $category = $customCat;
        if ($category === '__custom__') $category = 'General';

        if ($title === '') $errors[] = 'Document title is required.';

        // File upload
        $file = $_FILES['doc_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please select a file to upload.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload error (code ' . $file['error'] . ').';
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $errors[] = 'File exceeds the 20 MB limit.';
        } else {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mimeType, $allowedMimes)) {
                $errors[] = 'File type not allowed. Accepted: PDF, Word, Excel, JPG, PNG.';
            }
        }

        if (empty($errors)) {
            $ext      = $allowedMimes[$mimeType];
            $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $errors[] = 'Failed to save file. Check server write permissions.';
            } else {
                $relPath = 'assets/uploads/company_docs/' . $safeName;
                $pdo->prepare(
                    "INSERT INTO company_documents
                     (title, category, description, file_path, file_name, file_size, mime_type, sort_order, is_active, uploaded_by)
                     VALUES (:title, :cat, :desc, :path, :fname, :fsize, :mime, :sort, 1, :uid)"
                )->execute([
                    ':title'  => $title,
                    ':cat'    => $category,
                    ':desc'   => $description,
                    ':path'   => $relPath,
                    ':fname'  => $file['name'],
                    ':fsize'  => $file['size'],
                    ':mime'   => $mimeType,
                    ':sort'   => $sortOrder,
                    ':uid'    => $_SESSION['user_id'],
                ]);
                logAudit($pdo, 'upload_company_doc', 'company_documents',
                    (int)$pdo->lastInsertId(), "Uploaded: $title ($category)");
                setFlash('success', "Document \"$title\" uploaded successfully.");
                header('Location: ' . BASE_URL . '/admin/company_docs.php');
                exit;
            }
        }
    }

    // ---- Update metadata ----
    if ($action === 'update') {
        $docId       = (int)($_POST['doc_id'] ?? 0);
        $title       = trim($_POST['title']       ?? '');
        $category    = trim($_POST['category']    ?? 'General');
        $customCat   = trim($_POST['category_custom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        if ($category === '__custom__' && $customCat !== '') $category = $customCat;
        if ($category === '__custom__') $category = 'General';

        if ($title === '') $errors[] = 'Title is required.';

        if (empty($errors) && $docId > 0) {
            $pdo->prepare(
                "UPDATE company_documents SET title=:t, category=:c, description=:d, sort_order=:s WHERE id=:id"
            )->execute([':t' => $title, ':c' => $category, ':d' => $description, ':s' => $sortOrder, ':id' => $docId]);
            logAudit($pdo, 'edit_company_doc', 'company_documents', $docId, "Updated metadata: $title");
            setFlash('success', 'Document updated.');
            header('Location: ' . BASE_URL . '/admin/company_docs.php');
            exit;
        }
    }

    // ---- Toggle active ----
    if ($action === 'toggle') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            $pdo->prepare("UPDATE company_documents SET is_active = 1 - is_active WHERE id = :id")
                ->execute([':id' => $docId]);
            header('Location: ' . BASE_URL . '/admin/company_docs.php');
            exit;
        }
    }

    // ---- Delete ----
    if ($action === 'delete') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            $row = $pdo->prepare("SELECT file_path, title FROM company_documents WHERE id=:id LIMIT 1");
            $row->execute([':id' => $docId]);
            $row = $row->fetch();
            if ($row) {
                // Delete physical file
                $absPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['file_path']);
                if (file_exists($absPath)) unlink($absPath);
                $pdo->prepare("DELETE FROM company_documents WHERE id=:id")->execute([':id' => $docId]);
                logAudit($pdo, 'delete_company_doc', 'company_documents', $docId, "Deleted: {$row['title']}");
                setFlash('success', "Document \"{$row['title']}\" deleted.");
            }
            header('Location: ' . BASE_URL . '/admin/company_docs.php');
            exit;
        }
    }
}

// ---- Load all documents ----
$docs = $pdo->query(
    "SELECT cd.*, u.name AS uploader_name
     FROM company_documents cd
     LEFT JOIN users u ON u.id = cd.uploaded_by
     ORDER BY cd.category ASC, cd.sort_order ASC, cd.uploaded_at DESC"
)->fetchAll();

// ---- Collect existing categories from DB ----
$dbCategories = $pdo->query(
    "SELECT DISTINCT category FROM company_documents ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);
$allCategories = array_unique(array_merge($presetCategories, $dbCategories));
sort($allCategories);

// ---- Group by category for display ----
$grouped = [];
foreach ($docs as $doc) {
    $grouped[$doc['category']][] = $doc;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

function fmtBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function mimeIcon(string $mime): string {
    if ($mime === 'application/pdf') return '📄';
    if (str_contains($mime, 'word'))  return '📝';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return '📊';
    if (str_contains($mime, 'image')) return '🖼️';
    return '📎';
}
?>

<div class="page-header">
    <h2 class="page-title">Company Documents</h2>
    <p class="page-subtitle">Upload and manage documents that staff can access from the Company Docs page.</p>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<!-- ============================================================
     UPLOAD FORM
     ============================================================ -->
<div class="card" style="max-width:700px;margin-bottom:2rem;">
    <div class="card-header">
        <h3 class="card-title">Upload New Document</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="upload">

            <div class="form-row">
                <div class="form-group form-group--half">
                    <label class="form-label">Document Title <span class="required">*</span></label>
                    <input type="text" name="title" class="form-control"
                           placeholder="e.g. ICASA Type Approval Certificate"
                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                </div>
                <div class="form-group form-group--half">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control form-select" id="cat-select"
                            onchange="document.getElementById('custom-cat-wrap').style.display=this.value==='__custom__'?'block':'none'">
                        <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"
                            <?= (($_POST['category'] ?? '') === $cat) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="__custom__">+ New category…</option>
                    </select>
                    <div id="custom-cat-wrap" style="display:none;margin-top:.5rem;">
                        <input type="text" name="category_custom" class="form-control"
                               placeholder="Type new category name">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description <small style="color:var(--color-muted);">(shown to staff)</small></label>
                <textarea name="description" class="form-control" rows="2"
                          placeholder="e.g. Valid until 31 Dec 2026 — required for B2B tenders"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group form-group--half">
                    <label class="form-label">File <span class="required">*</span></label>
                    <input type="file" name="doc_file" class="form-control" required
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                    <small class="form-hint">PDF, Word, Excel, JPG, PNG — max 20 MB</small>
                </div>
                <div class="form-group form-group--half">
                    <label class="form-label">Sort Order <small style="color:var(--color-muted);">(lower = first)</small></label>
                    <input type="number" name="sort_order" class="form-control" value="0" min="0" style="max-width:120px;">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Upload Document</button>
        </form>
    </div>
</div>

<!-- ============================================================
     DOCUMENT LIST
     ============================================================ -->
<?php if (empty($docs)): ?>
<div class="card">
    <div class="card-body" style="color:var(--color-muted);text-align:center;padding:2rem;">
        No documents uploaded yet. Use the form above to add your first document.
    </div>
</div>
<?php else: ?>
<?php foreach ($grouped as $categoryName => $categoryDocs): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title"><?= htmlspecialchars($categoryName) ?></h3>
        <span style="font-size:.82rem;color:var(--color-muted);">
            <?= count($categoryDocs) ?> document<?= count($categoryDocs) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Title / Description</th>
                    <th style="width:110px;">File</th>
                    <th style="width:90px;">Size</th>
                    <th style="width:130px;">Uploaded</th>
                    <th style="width:80px;">Visible</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categoryDocs as $doc): ?>
                <tr style="<?= $doc['is_active'] ? '' : 'opacity:.45;' ?>">
                    <td style="font-size:1.3rem;text-align:center;vertical-align:middle;">
                        <?= mimeIcon($doc['mime_type']) ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($doc['title']) ?></strong>
                        <?php if ($doc['description']): ?>
                        <br><small style="color:var(--color-muted);"><?= htmlspecialchars($doc['description']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:var(--color-muted);word-break:break-all;">
                        <?= htmlspecialchars($doc['file_name']) ?>
                    </td>
                    <td style="font-size:.85rem;"><?= fmtBytes((int)$doc['file_size']) ?></td>
                    <td style="font-size:.8rem;color:var(--color-muted);">
                        <?= date('d M Y', strtotime($doc['uploaded_at'])) ?><br>
                        <?= htmlspecialchars($doc['uploader_name'] ?? '—') ?>
                    </td>
                    <td style="text-align:center;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action"  value="toggle">
                            <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline"
                                    style="color:<?= $doc['is_active'] ? '#16a34a' : '#9ca3af' ?>;border-color:<?= $doc['is_active'] ? '#16a34a' : '#9ca3af' ?>;"
                                    title="<?= $doc['is_active'] ? 'Click to hide from staff' : 'Click to show to staff' ?>">
                                <?= $doc['is_active'] ? '✓ Visible' : '✗ Hidden' ?>
                            </button>
                        </form>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-outline"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($doc)) ?>)"
                                style="margin-right:.25rem;">
                            Edit
                        </button>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Delete &quot;<?= addslashes(htmlspecialchars($doc['title'])) ?>&quot;? This cannot be undone.')">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline"
                                    style="color:#dc2626;border-color:#dc2626;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ============================================================
     EDIT MODAL
     ============================================================ -->
<div id="edit-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:1.75rem;max-width:520px;width:90%;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.25);">
        <button onclick="closeEditModal()" style="position:absolute;top:.75rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280;">&#x2715;</button>
        <h3 style="margin-bottom:1.25rem;font-size:1rem;font-weight:700;color:#1e3a5f;">Edit Document</h3>
        <form method="POST" id="edit-form">
            <input type="hidden" name="action"  value="update">
            <input type="hidden" name="doc_id"  id="edit-doc-id">

            <div class="form-group">
                <label class="form-label">Title <span class="required">*</span></label>
                <input type="text" name="title" id="edit-title" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" id="edit-category" class="form-control form-select"
                        onchange="document.getElementById('edit-custom-cat-wrap').style.display=this.value==='__custom__'?'block':'none'">
                    <?php foreach ($allCategories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                    <option value="__custom__">+ New category…</option>
                </select>
                <div id="edit-custom-cat-wrap" style="display:none;margin-top:.5rem;">
                    <input type="text" name="category_custom" id="edit-category-custom"
                           class="form-control" placeholder="Type new category name">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="edit-description" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" id="edit-sort" class="form-control" min="0" style="max-width:120px;">
            </div>

            <div style="display:flex;gap:.75rem;margin-top:.25rem;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(doc) {
    document.getElementById('edit-doc-id').value       = doc.id;
    document.getElementById('edit-title').value        = doc.title;
    document.getElementById('edit-description').value  = doc.description || '';
    document.getElementById('edit-sort').value         = doc.sort_order || 0;

    var catSel = document.getElementById('edit-category');
    var found  = false;
    for (var i = 0; i < catSel.options.length; i++) {
        if (catSel.options[i].value === doc.category) {
            catSel.selectedIndex = i;
            found = true;
            break;
        }
    }
    if (!found) {
        // Category doesn't exist in dropdown — show custom field
        catSel.value = '__custom__';
        document.getElementById('edit-custom-cat-wrap').style.display = 'block';
        document.getElementById('edit-category-custom').value = doc.category;
    } else {
        document.getElementById('edit-custom-cat-wrap').style.display = 'none';
    }

    var overlay = document.getElementById('edit-modal-overlay');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('edit-modal-overlay').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('edit-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeEditModal(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
