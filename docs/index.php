<?php
// ============================================================
// Blackview SA Portal — Company Documents Library
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';

requireLogin();

$pdo       = getDB();
$pageTitle = 'Company Documents';

// ---- Search / filter ----
$search   = trim($_GET['q']        ?? '');
$catFilter = trim($_GET['category'] ?? '');

$where  = ['cd.is_active = 1'];
$params = [];

if ($search !== '') {
    $where[]         = '(cd.title LIKE :q OR cd.description LIKE :q OR cd.category LIKE :q)';
    $params[':q']    = '%' . $search . '%';
}
if ($catFilter !== '') {
    $where[]          = 'cd.category = :cat';
    $params[':cat']   = $catFilter;
}

$whereSQL = implode(' AND ', $where);

$docs = $pdo->prepare(
    "SELECT cd.*, u.name AS uploader_name
     FROM company_documents cd
     LEFT JOIN users u ON u.id = cd.uploaded_by
     WHERE $whereSQL
     ORDER BY cd.category ASC, cd.sort_order ASC, cd.title ASC"
);
$docs->execute($params);
$docs = $docs->fetchAll();

// ---- All active categories for filter pills ----
$categories = $pdo->query(
    "SELECT DISTINCT category FROM company_documents WHERE is_active = 1 ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ---- Group by category ----
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
    if ($mime === 'application/pdf') return 'pdf';
    if (str_contains($mime, 'word'))  return 'word';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return 'excel';
    if (str_contains($mime, 'image')) return 'image';
    return 'file';
}

function mimeColor(string $mime): string {
    if ($mime === 'application/pdf')  return '#dc2626';
    if (str_contains($mime, 'word'))  return '#2563eb';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return '#16a34a';
    if (str_contains($mime, 'image')) return '#7c3aed';
    return '#6b7280';
}

function mimeLabel(string $mime): string {
    if ($mime === 'application/pdf')  return 'PDF';
    if (str_contains($mime, 'word'))  return 'Word';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return 'Excel';
    if (str_contains($mime, 'image')) return 'Image';
    return 'File';
}
?>

<div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
        <h2 class="page-title">Company Documents</h2>
        <p class="page-subtitle">Download company certificates, registrations and compliance documents.</p>
    </div>
    <?php if (isAdmin()): ?>
    <a href="<?= BASE_URL ?>/admin/company_docs.php" class="btn btn-outline btn-sm">
        ⚙ Manage Documents
    </a>
    <?php endif; ?>
</div>

<!-- ============================================================
     SEARCH + CATEGORY FILTER
     ============================================================ -->
<form method="GET" action="" style="margin-bottom:1.5rem;">
    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;">
        <div style="flex:1;min-width:220px;max-width:380px;position:relative;">
            <input type="text" name="q" class="form-control" placeholder="Search documents…"
                   value="<?= htmlspecialchars($search) ?>"
                   style="padding-left:2.25rem;">
            <svg style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);opacity:.45;"
                 width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </div>
        <?php if ($search !== '' || $catFilter !== ''): ?>
        <a href="<?= BASE_URL ?>/docs/index.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
        <input type="hidden" name="category" value="">
    </div>
</form>

<!-- Category filter pills -->
<?php if (!empty($categories)): ?>
<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.75rem;">
    <a href="<?= BASE_URL ?>/docs/index.php<?= $search ? '?q=' . urlencode($search) : '' ?>"
       style="display:inline-block;padding:.3rem .9rem;border-radius:20px;font-size:.82rem;font-weight:600;text-decoration:none;
              background:<?= $catFilter === '' ? '#1e40af' : '#f1f5f9' ?>;
              color:<?= $catFilter === '' ? '#fff' : '#374151' ?>;
              border:1px solid <?= $catFilter === '' ? '#1e40af' : '#e5e7eb' ?>;">
        All
    </a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category=<?= urlencode($cat) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
       style="display:inline-block;padding:.3rem .9rem;border-radius:20px;font-size:.82rem;font-weight:600;text-decoration:none;
              background:<?= $catFilter === $cat ? '#1e40af' : '#f1f5f9' ?>;
              color:<?= $catFilter === $cat ? '#fff' : '#374151' ?>;
              border:1px solid <?= $catFilter === $cat ? '#1e40af' : '#e5e7eb' ?>;">
        <?= htmlspecialchars($cat) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================================
     DOCUMENT GRID
     ============================================================ -->
<?php if (empty($grouped)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;color:var(--color-muted);">
        <?php if ($search !== '' || $catFilter !== ''): ?>
            No documents match your search.
            <a href="<?= BASE_URL ?>/docs/index.php" style="display:block;margin-top:.5rem;">Clear filters</a>
        <?php else: ?>
            No documents have been published yet. Check back later.
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<?php foreach ($grouped as $categoryName => $categoryDocs): ?>
<div style="margin-bottom:2rem;">
    <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
               color:var(--color-muted);margin-bottom:.9rem;padding-bottom:.4rem;
               border-bottom:1px solid #e5e7eb;">
        <?= htmlspecialchars($categoryName) ?>
        <span style="font-weight:400;text-transform:none;letter-spacing:0;">
            (<?= count($categoryDocs) ?>)
        </span>
    </h3>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
        <?php foreach ($categoryDocs as $doc):
            $color = mimeColor($doc['mime_type']);
            $label = mimeLabel($doc['mime_type']);
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;
                    display:flex;flex-direction:column;overflow:hidden;
                    transition:box-shadow .15s;box-shadow:0 1px 4px rgba(0,0,0,.06);"
             onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,.12)'"
             onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,.06)'">

            <!-- Coloured top bar -->
            <div style="height:4px;background:<?= $color ?>;"></div>

            <div style="padding:1.1rem 1.1rem .75rem;flex:1;">
                <!-- File type badge -->
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;">
                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:.72rem;
                                 font-weight:700;background:<?= $color ?>18;color:<?= $color ?>;
                                 border:1px solid <?= $color ?>33;letter-spacing:.04em;">
                        <?= $label ?>
                    </span>
                    <span style="font-size:.75rem;color:var(--color-muted);">
                        <?= fmtBytes((int)$doc['file_size']) ?>
                    </span>
                </div>

                <!-- Title -->
                <div style="font-weight:700;font-size:.95rem;color:#1e293b;line-height:1.35;margin-bottom:.4rem;">
                    <?= htmlspecialchars($doc['title']) ?>
                </div>

                <!-- Description -->
                <?php if ($doc['description']): ?>
                <div style="font-size:.83rem;color:var(--color-muted);line-height:1.5;margin-bottom:.6rem;">
                    <?= htmlspecialchars($doc['description']) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer: date + download -->
            <div style="padding:.65rem 1.1rem;border-top:1px solid #f1f5f9;
                        display:flex;align-items:center;justify-content:space-between;background:#fafafa;">
                <span style="font-size:.75rem;color:var(--color-muted);">
                    <?= date('d M Y', strtotime($doc['uploaded_at'])) ?>
                </span>
                <a href="<?= BASE_URL ?>/docs/download.php?id=<?= $doc['id'] ?>"
                   style="display:inline-flex;align-items:center;gap:.35rem;font-size:.82rem;font-weight:600;
                          color:<?= $color ?>;text-decoration:none;padding:.3rem .75rem;
                          border:1px solid <?= $color ?>55;border-radius:6px;background:<?= $color ?>08;
                          transition:background .12s;"
                   onmouseover="this.style.background='<?= $color ?>18'"
                   onmouseout="this.style.background='<?= $color ?>08'"
                   download>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Download
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
