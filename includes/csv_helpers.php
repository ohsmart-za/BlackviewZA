<?php
// ============================================================
// Blackview SA Portal — CSV Helper Functions
// ============================================================

/**
 * csvTemplateDownload($filename, $headers, $sampleRows)
 *
 * Sends HTTP headers and streams a CSV file for download.
 *
 * @param string   $filename    Suggested download filename, e.g. 'scan_in_template.csv'
 * @param array    $headers     Column headers, e.g. ['product_sku', 'warehouse_name', 'serial_no']
 * @param array    $sampleRows  Zero or more rows of sample data, each an indexed array matching $headers
 */
function csvTemplateDownload(string $filename, array $headers, array $sampleRows): void
{
    // Prevent any buffered output from corrupting the download
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');

    // UTF-8 BOM so Excel opens the file with correct encoding
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, $headers);

    foreach ($sampleRows as $row) {
        fputcsv($out, $row);
    }

    fclose($out);
}


/**
 * parseUploadedCsv($fileArray)
 *
 * Validates and parses an uploaded CSV file from $_FILES['csv_file'].
 *
 * Validation rules:
 *  - Field must have been uploaded (UPLOAD_ERR_OK)
 *  - Extension must be .csv or .txt
 *  - File size must be <= 5 MB
 *
 * Parsing behaviour:
 *  - Strips UTF-8 BOM from the very first bytes
 *  - Normalises Windows line endings (\r\n) — fgetcsv handles these natively
 *  - First non-empty row is treated as the header row; values are trimmed and lowercased
 *  - Subsequent rows are returned as associative arrays keyed by the header names
 *  - Empty rows (all cells empty) are silently skipped
 *
 * @param  array $fileArray  The element from $_FILES, e.g. $_FILES['csv_file']
 * @return array {
 *     headers: string[],
 *     rows:    array[],     // each row is ['header_name' => 'value', ...]
 *     error:   string|null  // non-null means parsing failed; rows will be empty
 * }
 */
function parseUploadedCsv(array $fileArray): array
{
    $empty = ['headers' => [], 'rows' => [], 'error' => null];

    // --- Upload error check ---
    $uploadError = $fileArray['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadMessages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
        ];
        $empty['error'] = $uploadMessages[$uploadError] ?? 'Unknown upload error (code ' . $uploadError . ').';
        return $empty;
    }

    // --- Extension check ---
    $originalName = $fileArray['name'] ?? '';
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        $empty['error'] = 'Invalid file type. Please upload a .csv or .txt file.';
        return $empty;
    }

    // --- Size check (5 MB) ---
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    $fileSize = $fileArray['size'] ?? 0;
    if ($fileSize > $maxBytes) {
        $empty['error'] = 'File is too large. Maximum allowed size is 5 MB.';
        return $empty;
    }

    $tmpPath = $fileArray['tmp_name'] ?? '';
    if (empty($tmpPath) || !is_uploaded_file($tmpPath)) {
        $empty['error'] = 'Could not access the uploaded file.';
        return $empty;
    }

    // --- Open the file ---
    $handle = fopen($tmpPath, 'rb');
    if ($handle === false) {
        $empty['error'] = 'Could not open the uploaded file for reading.';
        return $empty;
    }

    // --- Strip UTF-8 BOM (EF BB BF) ---
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        // No BOM — rewind to start
        rewind($handle);
    }
    // If BOM was present we've already consumed it; fgetcsv will continue from byte 4

    // --- Read header row ---
    $headers = null;
    $rows    = [];

    while (($rawRow = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        // Skip completely empty rows
        if ($rawRow === [null]) {
            continue;
        }

        // Check if truly empty (all cells whitespace-only)
        $trimmed = array_map('trim', $rawRow);
        if (count(array_filter($trimmed, fn($v) => $v !== '')) === 0) {
            continue;
        }

        if ($headers === null) {
            // First non-empty row = headers
            $headers = array_map(function ($h) {
                return strtolower(trim($h));
            }, $rawRow);
            continue;
        }

        // Subsequent rows: map to associative array using headers
        $assoc = [];
        foreach ($headers as $i => $col) {
            $assoc[$col] = isset($rawRow[$i]) ? trim($rawRow[$i]) : '';
        }
        $rows[] = $assoc;
    }

    fclose($handle);

    if ($headers === null) {
        $empty['error'] = 'The uploaded file appears to be empty or could not be parsed.';
        return $empty;
    }

    return [
        'headers' => $headers,
        'rows'    => $rows,
        'error'   => null,
    ];
}
