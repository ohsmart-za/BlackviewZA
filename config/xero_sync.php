<?php
// ============================================================
// Blackview SA Portal — Xero Sync Engine
//
// Two-way sync working DIRECTLY on the portal's own tables
// (customers / invoices / quotes) — no staging copies.
//
//   Contacts:  push portal customers → Xero Contacts (merge by xero_id,
//              then email, then exact name); pull Xero contacts → upsert
//              into customers.
//   Invoices:  push finalised ('active') portal invoices → Xero ACCREC
//              AUTHORISED (+ optional payment); push VOIDED for voided
//              ones; pull status/amount-due back; invoices that only
//              exist in Xero land in xero_invoices_mirror for the CRM.
//   Quotes:    push portal quotes → Xero Quotes.
//
// Conflict rule (same as the SageSync engine): a side is "dirty" when
// its updated_at > xero_synced_at. Dirty local rows win on pull (they'll
// push instead); every sync-side write sets xero_synced_at = NOW() in the
// same statement so it never re-flags itself as dirty.
// ============================================================

require_once __DIR__ . '/xero_client.php';

class XeroSync
{
    public static function run(): array {
        $s = [
            'pushed_customers' => 0, 'pulled_customers' => 0,
            'pushed_invoices'  => 0, 'pulled_invoices'  => 0, 'mirrored_invoices' => 0,
            'pushed_quotes'    => 0,
            'errors'           => 0,
            'direction'        => xeroSetting('xero_sync_direction', 'both'),
        ];

        // Master on/off switch
        if (xeroSetting('xero_sync_enabled', '1') !== '1') {
            $s['skipped'] = 'Sync is switched OFF.';
            return $s;
        }

        // Direction: 'both' = two-way merge, 'push' = portal→Xero only, 'pull' = Xero→portal only
        $dir  = $s['direction'];
        $push = ($dir === 'both' || $dir === 'push');
        $pull = ($dir === 'both' || $dir === 'pull');

        // Per-entity switches (independent — contacts, invoices, quotes)
        $doCustomers = xeroSetting('xero_sync_customers', '1') === '1';
        $doInvoices  = xeroSetting('xero_sync_invoices',  '0') === '1'; // default OFF — invoices pushed manually
        $doQuotes    = xeroSetting('xero_sync_quotes',    '0') === '1';

        if ($doCustomers && $push) self::pushCustomers($s);
        if ($doCustomers && $pull) self::pullContacts($s);
        if ($doInvoices  && $push) self::pushInvoices($s);
        if ($doInvoices  && $pull) self::pullInvoices($s);
        if ($doQuotes    && $push) self::pushQuotes($s);

        xeroSaveSetting('xero_last_sync_at', date('Y-m-d H:i:s'));
        return $s;
    }

    /** Sync start date (YYYY-MM-DD). Invoices dated before this are never pushed/pulled. '' = no limit. */
    private static function syncFromDate(): string {
        $d = xeroSetting('xero_sync_from_date', '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }

    // ============================================================
    // Customers → Xero Contacts
    // ============================================================

    private static function pushCustomers(array &$s): void {
        $pdo  = getDB();
        $rows = $pdo->query(
            "SELECT * FROM customers
             WHERE name <> ''
               AND (xero_id IS NULL OR xero_synced_at IS NULL OR updated_at > xero_synced_at)
             ORDER BY id ASC LIMIT 200"
        )->fetchAll();

        foreach ($rows as $r) {
            $xid = self::pushOneCustomer($r);
            if ($xid) $s['pushed_customers']++;
            else      $s['errors']++;
        }
    }

    private static function pullContacts(array &$s): void {
        $pdo   = getDB();
        $since = xeroSetting('xero_last_pull_contacts');
        $query = [];
        if ($since) {
            $query['where'] = 'UpdatedDateUTC>=DateTime(' .
                str_replace(['-', ':', ' '], [',', ',', ','], substr($since, 0, 19)) . ')';
        }

        foreach (XeroClient::getAll('Contacts', 'Contacts', $query) as $c) {
            try {
                if (($c['ContactStatus'] ?? 'ACTIVE') !== 'ACTIVE') continue;
                $xid   = $c['ContactID'];
                $name  = $c['Name'] ?? '';
                $email = strtolower(trim($c['EmailAddress'] ?? ''));

                // Match: xero_id → email → exact name
                $local = self::fetchOne("SELECT * FROM customers WHERE xero_id = :x LIMIT 1", [':x' => $xid]);
                if (!$local && $email !== '') {
                    $local = self::fetchOne(
                        "SELECT * FROM customers WHERE xero_id IS NULL AND LOWER(email) = :e LIMIT 1", [':e' => $email]);
                }
                if (!$local && $name !== '') {
                    $local = self::fetchOne(
                        "SELECT * FROM customers WHERE xero_id IS NULL AND (name = :n OR company_name = :n2) LIMIT 1",
                        [':n' => $name, ':n2' => $name]);
                }

                $phone = '';
                foreach (($c['Phones'] ?? []) as $p) {
                    if (!empty($p['PhoneNumber'])) { $phone = trim(($p['PhoneAreaCode'] ?? '') . ' ' . $p['PhoneNumber']); break; }
                }
                $addr = '';
                foreach (($c['Addresses'] ?? []) as $a) {
                    if (($a['AddressType'] ?? '') === 'STREET' || $addr === '') {
                        $addr = implode(', ', array_filter([
                            $a['AddressLine1'] ?? '', $a['AddressLine2'] ?? '',
                            $a['City'] ?? '', $a['PostalCode'] ?? '',
                        ]));
                    }
                }
                // First contact person = the individual behind a company contact
                $person = trim((($c['ContactPersons'][0]['FirstName'] ?? '') . ' ' . ($c['ContactPersons'][0]['LastName'] ?? '')));

                if ($local) {
                    $localDirty = $local['xero_synced_at'] !== null && $local['updated_at'] > $local['xero_synced_at'];
                    if ($localDirty) {
                        // Local edits win — they push on the next run; just make sure the link exists.
                        if (!$local['xero_id']) {
                            $pdo->prepare("UPDATE customers SET xero_id = :x WHERE id = :id")
                                ->execute([':x' => $xid, ':id' => $local['id']]);
                        }
                        self::log('pull', 'customer', $local['id'], $xid, 'skip', 'ok', 'Local copy newer — will push instead');
                        continue;
                    }
                    // Merge: Xero fills every field it has; blanks keep local values.
                    $pdo->prepare(
                        "UPDATE customers SET
                            xero_id = :x,
                            name    = :name,
                            email   = CASE WHEN :email <> '' THEN :email2 ELSE email END,
                            phone   = CASE WHEN :phone <> '' THEN :phone2 ELSE phone END,
                            address = CASE WHEN :addr  <> '' THEN :addr2  ELSE address END,
                            vat_no  = CASE WHEN :vat   <> '' THEN :vat2   ELSE vat_no END,
                            xero_synced_at = NOW()
                         WHERE id = :id"
                    )->execute([
                        ':x' => $xid,
                        ':name'  => $person !== '' && ($local['contact_type'] ?? '') === 'business' ? $local['name'] : ($person ?: $name),
                        ':email' => $email, ':email2' => $email,
                        ':phone' => $phone, ':phone2' => $phone,
                        ':addr'  => $addr,  ':addr2'  => $addr,
                        ':vat'   => $c['TaxNumber'] ?? '', ':vat2' => $c['TaxNumber'] ?? '',
                        ':id'    => $local['id'],
                    ]);
                    // Keep the company name in sync for business contacts
                    if (($local['contact_type'] ?? '') === 'business' || ($local['company_name'] ?? '') !== '') {
                        $pdo->prepare("UPDATE customers SET company_name = :cn, xero_synced_at = NOW() WHERE id = :id")
                            ->execute([':cn' => $name, ':id' => $local['id']]);
                    }
                    self::log('pull', 'customer', $local['id'], $xid, 'merge', 'ok', $name);
                } else {
                    // New contact from Xero
                    $isBiz = !empty($c['ContactPersons']) || !empty($c['TaxNumber']);
                    $pdo->prepare(
                        "INSERT INTO customers (name, email, phone, address, contact_type, company_name, vat_no, xero_id, xero_synced_at, created_at)
                         VALUES (:n, :e, :p, :a, :ct, :cn, :v, :x, NOW(), NOW())"
                    )->execute([
                        ':n'  => $person ?: $name,
                        ':e'  => $email,
                        ':p'  => $phone,
                        ':a'  => $addr,
                        ':ct' => $isBiz ? 'business' : 'individual',
                        ':cn' => $isBiz ? $name : '',
                        ':v'  => $c['TaxNumber'] ?? '',
                        ':x'  => $xid,
                    ]);
                    self::log('pull', 'customer', (int)$pdo->lastInsertId(), $xid, 'create', 'ok', $name);
                }
                $s['pulled_customers']++;
            } catch (Throwable $e) {
                $s['errors']++;
                self::log('pull', 'customer', null, $c['ContactID'] ?? null, 'error', 'error', $e->getMessage());
            }
        }
        xeroSaveSetting('xero_last_pull_contacts', gmdate('Y-m-d H:i:s'));
    }

    // ============================================================
    // Invoices → Xero (ACCREC)
    // ============================================================

    private static function pushInvoices(array &$s): void {
        $pdo = getDB();

        // 1. New finalised invoices not yet on Xero, on/after the sync-from date
        $fromDate = self::syncFromDate();
        $sql = "SELECT i.id
                FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                WHERE i.status = 'active' AND i.xero_id IS NULL";
        $params = [];
        if ($fromDate !== '') {
            $sql .= " AND DATE(COALESCE(i.invoice_date, i.created_at)) >= :fromdate";
            $params[':fromdate'] = $fromDate;
        }
        $sql .= " ORDER BY i.id ASC LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $res = self::pushSingleInvoice((int)$id);
            if (!empty($res['ok']) && empty($res['already'])) $s['pushed_invoices']++;
            elseif (empty($res['ok']) && empty($res['skip']))  $s['errors']++;
        }

        // 3. Locally-voided invoices that are still live on Xero
        $voided = $pdo->query(
            "SELECT id, invoice_no, xero_id FROM invoices
             WHERE status = 'voided' AND xero_id IS NOT NULL
               AND COALESCE(xero_status,'') NOT IN ('VOIDED','DELETED') LIMIT 50"
        )->fetchAll();
        foreach ($voided as $r) {
            try {
                XeroClient::post('Invoices', ['Invoices' => [[
                    'InvoiceID' => $r['xero_id'], 'Status' => 'VOIDED',
                ]]]);
                $pdo->prepare("UPDATE invoices SET xero_status = 'VOIDED', xero_amount_due = 0, xero_synced_at = NOW() WHERE id = :id")
                    ->execute([':id' => $r['id']]);
                self::log('push', 'invoice', $r['id'], $r['xero_id'], 'void', 'ok', $r['invoice_no']);
            } catch (Throwable $e) {
                // Xero refuses to void invoices with payments — mark it so we don't retry forever
                $pdo->prepare("UPDATE invoices SET xero_status = 'VOID_FAILED', xero_synced_at = NOW() WHERE id = :id")
                    ->execute([':id' => $r['id']]);
                $s['errors']++;
                self::log('push', 'invoice', $r['id'], $r['xero_id'], 'error', 'error',
                    'Void failed (has payments in Xero? void it there manually): ' . $e->getMessage());
            }
        }
    }

    /**
     * Push ONE invoice to Xero. Used both by the bulk loop and by the manual
     * per-invoice "Push" button. Ignores the enabled/from-date gates because
     * it's always an explicit action. Returns ['ok'=>bool, 'message'=>string, ...].
     */
    public static function pushSingleInvoice(int $invoiceId, bool $force = false): array {
        $pdo = getDB();
        $r = self::fetchOne(
            "SELECT i.*, c.xero_id AS cust_xero_id, c.name AS cust_name, c.id AS cust_id
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.id = :id LIMIT 1", [':id' => $invoiceId]);

        if (!$r)                            return ['ok' => false, 'message' => 'Invoice not found.'];
        if (($r['status'] ?? '') !== 'active')
            return ['ok' => false, 'skip' => true, 'message' => 'Only finalised invoices can be pushed (not drafts or voided).'];
        if (!empty($r['xero_id']) && !$force)
            return ['ok' => true, 'already' => true, 'xero_id' => $r['xero_id'], 'message' => 'Already on Xero.'];

        try {
            // Make sure the customer exists on Xero first
            $custXeroId = $r['cust_xero_id'];
            if (!$custXeroId) {
                $custRow = self::fetchOne("SELECT * FROM customers WHERE id = :id", [':id' => $r['cust_id']]);
                $custXeroId = $custRow ? self::pushOneCustomer($custRow) : null;
                if (!$custXeroId) {
                    return ['ok' => false, 'message' => "Could not sync customer '{$r['cust_name']}' to Xero first."];
                }
            }

            $accountCode = xeroSetting('xero_account_code', '200');
            $taxType     = xeroSetting('xero_tax_type', 'OUTPUT2');
            // Payment account: prefer the per-payment-method mapping, else the global default
            $payMap      = json_decode(xeroSetting('xero_payment_map', '{}'), true) ?: [];
            $invPayCode  = $r['payment_method'] ?? '';
            $payAccount  = ($invPayCode !== '' && !empty($payMap[$invPayCode]))
                           ? $payMap[$invPayCode]
                           : xeroSetting('xero_payment_account_code', '');

            $lineStmt = $pdo->prepare(
                "SELECT ii.*, p.name AS product_name, p.sku
                 FROM invoice_items ii LEFT JOIN products p ON p.id = ii.product_id
                 WHERE ii.invoice_id = :id"
            );
            $lineStmt->execute([':id' => $r['id']]);
            $lineItems = [];
            foreach ($lineStmt->fetchAll() as $ln) {
                $desc = $ln['product_name'] ?: 'Item';
                if (!empty($ln['sku']))       $desc .= ' (' . $ln['sku'] . ')';
                if (!empty($ln['serial_no'])) $desc .= ' — SN: ' . $ln['serial_no'];
                $line = [
                    'Description' => $desc,
                    'Quantity'    => (float)($ln['qty'] ?? 1),
                    'UnitAmount'  => (float)$ln['unit_price'],
                    'AccountCode' => $accountCode,
                    'TaxType'     => $taxType,
                ];
                // Attach the Xero Item code so the Item column is populated and
                // (if the item is tracked in Xero) inventory is deducted.
                $sku = mb_substr(trim($ln['sku'] ?? ''), 0, 30);
                if ($sku !== '' && self::ensureXeroItem($sku, $ln['product_name'] ?: $sku, $accountCode, $taxType)) {
                    $line['ItemCode'] = $sku;
                }
                $lineItems[] = $line;
            }
            if ((float)($r['discount_amount'] ?? 0) > 0) {
                $lineItems[] = [
                    'Description' => 'Discount (' . rtrim(rtrim(number_format((float)$r['discount_pct'], 1), '0'), '.') . '%)',
                    'Quantity'    => 1,
                    'UnitAmount'  => -(float)$r['discount_amount'],
                    'AccountCode' => $accountCode,
                    'TaxType'     => $taxType,
                ];
            }
            if (empty($lineItems)) return ['ok' => false, 'message' => 'Invoice has no line items.'];

            $docDate = substr($r['invoice_date'] ?? $r['created_at'], 0, 10);
            $payload = [
                'Type'            => 'ACCREC',
                'Contact'         => ['ContactID' => $custXeroId],
                'InvoiceNumber'   => $r['invoice_no'],
                'Date'            => $docDate,
                'DueDate'         => $r['due_date'] ?: $docDate,
                'Reference'       => 'Blackview Portal — ' . ucfirst($r['channel'] ?? ''),
                'Status'          => 'AUTHORISED',
                'LineAmountTypes' => 'Exclusive',
                'LineItems'       => $lineItems,
            ];

            // Re-push mode: update the existing Xero invoice in place. Any payments on it
            // must be deleted first, otherwise Xero blocks editing the line items.
            if (!empty($r['xero_id'])) {
                $payload['InvoiceID'] = $r['xero_id'];
                try {
                    $existing = XeroClient::get('Invoices/' . $r['xero_id'])['Invoices'][0] ?? null;
                    foreach (($existing['Payments'] ?? []) as $pmt) {
                        if (!empty($pmt['PaymentID'])) {
                            try { XeroClient::post('Payments/' . $pmt['PaymentID'], ['Status' => 'DELETED']); }
                            catch (Throwable $pe) { /* continue — may already be gone */ }
                        }
                    }
                } catch (Throwable $ge) { /* couldn't fetch existing — proceed anyway */ }
            }

            $saved = XeroClient::post('Invoices', ['Invoices' => [$payload]]);
            $doc = $saved['Invoices'][0] ?? null;
            if (!$doc) throw new RuntimeException('Xero did not return the saved invoice');
            $xid = $doc['InvoiceID'];

            // Optionally push POS payments so cash sales show as paid in Xero
            $localPaid = 0.0;
            if ($payAccount !== '') {
                try {
                    $pStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = :id");
                    $pStmt->execute([':id' => $r['id']]);
                    $localPaid = min((float)$pStmt->fetchColumn(), (float)($doc['AmountDue'] ?? $r['total']));
                    if ($localPaid > 0.009) {
                        XeroClient::post('Payments', ['Payments' => [[
                            'Invoice' => ['InvoiceID' => $xid],
                            'Account' => ['Code' => $payAccount],
                            'Date'    => $docDate,
                            'Amount'  => round($localPaid, 2),
                        ]]]);
                    }
                } catch (Throwable $pe) {
                    self::log('push', 'payment', $r['id'], $xid, 'error', 'error', 'Invoice pushed OK but payment failed: ' . $pe->getMessage());
                }
            }

            $pdo->prepare(
                "UPDATE invoices SET xero_id = :x, xero_status = :st, xero_amount_due = :due, xero_synced_at = NOW() WHERE id = :id"
            )->execute([
                ':x'   => $xid,
                ':st'  => $doc['Status'] ?? 'AUTHORISED',
                ':due' => max(0, (float)($doc['AmountDue'] ?? $r['total']) - $localPaid),
                ':id'  => $r['id'],
            ]);
            $verb = !empty($r['xero_id']) ? 'update' : 'create';
            self::log('push', 'invoice', $r['id'], $xid, $verb, 'ok', $r['invoice_no'] . ' → ' . $r['cust_name']);
            return ['ok' => true, 'xero_id' => $xid,
                    'message' => $r['invoice_no'] . ($verb === 'update' ? ' re-pushed (updated) on Xero.' : ' pushed to Xero.')];
        } catch (Throwable $e) {
            self::log('push', 'invoice', $r['id'], null, 'error', 'error', $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Ensure a product exists as a Xero Item (upsert by Code=SKU) so invoice lines
     * can reference ItemCode → populates the Item column and lets Xero track
     * inventory. Non-fatal: returns false on any error (invoice still pushes without it).
     */
    private static $ensuredItems = [];
    private static function ensureXeroItem(string $sku, string $name, string $accountCode, string $taxType): bool {
        // Xero Item Code: keep it clean and within 30 chars.
        $code = mb_substr(trim($sku), 0, 30);
        if ($code === '') return false;
        if (isset(self::$ensuredItems[$code])) return self::$ensuredItems[$code];
        try {
            // Minimal payload — just Code + Name. No SalesDetails: an invalid sales
            // account/tax there would make the WHOLE item push fail. The invoice line
            // carries the account + tax anyway; the item only needs to exist so the
            // ItemCode reference resolves and the Item column populates.
            $item = [
                'Code'   => $code,
                'Name'   => mb_substr($name !== '' ? $name : $code, 0, 50), // Xero Item Name max 50 chars
                'IsSold' => true,
            ];
            XeroClient::post('Items', ['Items' => [$item]]);
            self::$ensuredItems[$code] = true;
        } catch (Throwable $e) {
            // Item may already exist (incl. tracked inventory) — still reference it by code.
            $msg = $e->getMessage();
            $stillUsable = stripos($msg, 'already') !== false || stripos($msg, 'tracked') !== false;
            self::$ensuredItems[$code] = $stillUsable;
            if (!$stillUsable) {
                self::log('push', 'item', null, null, 'error', 'error', "Item '$code': $msg");
            }
        }
        return self::$ensuredItems[$code];
    }

    /**
     * Push a single customer to Xero, returning its ContactID or null on failure.
     * Robust: sanitises the payload, and if the Name is already taken in Xero it
     * links to that existing contact instead of erroring (Xero Names must be unique).
     */
    private static function pushOneCustomer(array $r): ?string {
        $pdo = getDB();
        // Xero requires a unique, non-empty Contact Name. Business → company name.
        $xeroName = trim(($r['company_name'] ?? '') !== '' ? $r['company_name'] : ($r['name'] ?? ''));
        if ($xeroName === '') {
            self::log('push', 'customer', (int)$r['id'], null, 'skip', 'ok', 'Blank name — cannot push to Xero');
            return null;
        }

        $payload = ['Name' => mb_substr($xeroName, 0, 255)];
        $email = trim($r['email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $payload['EmailAddress'] = $email;
        if (($r['vat_no'] ?? '') !== '') $payload['TaxNumber'] = mb_substr($r['vat_no'], 0, 50);
        if (($r['phone'] ?? '') !== '')  $payload['Phones'] = [['PhoneType' => 'DEFAULT', 'PhoneNumber' => mb_substr($r['phone'], 0, 50)]];
        if (($r['address'] ?? '') !== '') $payload['Addresses'] = [['AddressType' => 'STREET', 'AddressLine1' => mb_substr($r['address'], 0, 500)]];
        if (($r['company_name'] ?? '') !== '' && ($r['name'] ?? '') !== $r['company_name'] && trim($r['name'] ?? '') !== '') {
            $parts = explode(' ', trim($r['name']), 2);
            $payload['ContactPersons'] = [[
                'FirstName'       => mb_substr($parts[0], 0, 50),
                'LastName'        => mb_substr($parts[1] ?? '', 0, 50),
                'IncludeInEmails' => true,
            ]];
        }
        if (!empty($r['xero_id'])) $payload['ContactID'] = $r['xero_id'];

        $xid = null;
        $logged = false;
        try {
            $saved = XeroClient::post('Contacts', ['Contacts' => [$payload]]);
            $xid = $saved['Contacts'][0]['ContactID'] ?? null;
            if (!$xid) throw new RuntimeException('Xero did not return the saved contact');
        } catch (Throwable $e) {
            // Name already exists in Xero → link to that contact instead of failing.
            $existing = self::findXeroContactByName($xeroName);
            if ($existing) {
                $xid = $existing;
                self::log('push', 'customer', (int)$r['id'], $xid, 'link', 'ok', "Linked to existing Xero contact: $xeroName");
                $logged = true;
            } else {
                self::log('push', 'customer', (int)$r['id'], $r['xero_id'] ?? null, 'error', 'error', $e->getMessage());
                return null;
            }
        }

        $pdo->prepare("UPDATE customers SET xero_id = :x, xero_synced_at = NOW() WHERE id = :id")
            ->execute([':x' => $xid, ':id' => $r['id']]);
        if (!$logged) {
            self::log('push', 'customer', (int)$r['id'], $xid, !empty($r['xero_id']) ? 'update' : 'create', 'ok', $xeroName);
        }
        return $xid;
    }

    /** Look up a Xero contact by exact Name; returns its ContactID or null. */
    private static function findXeroContactByName(string $name): ?string {
        try {
            $safe = str_replace('"', '', $name);
            $res  = XeroClient::get('Contacts', ['where' => 'Name=="' . $safe . '"']);
            return $res['Contacts'][0]['ContactID'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function pullInvoices(array &$s): void {
        $pdo   = getDB();
        $since = xeroSetting('xero_last_pull_invoices');
        $where = 'Type=="ACCREC"';
        if ($since) {
            $where .= '&&UpdatedDateUTC>=DateTime(' .
                str_replace(['-', ':', ' '], [',', ',', ','], substr($since, 0, 19)) . ')';
        }

        foreach (XeroClient::getAll('Invoices', 'Invoices', ['where' => $where]) as $doc) {
            try {
                $xid    = $doc['InvoiceID'];
                $status = $doc['Status'] ?? '';
                $number = $doc['InvoiceNumber'] ?? '';

                // Linked portal invoice? → refresh status / amount due
                $local = self::fetchOne("SELECT id FROM invoices WHERE xero_id = :x LIMIT 1", [':x' => $xid]);
                if (!$local && $number !== '') {
                    // Re-link by invoice number if the GUID link was lost
                    $local = self::fetchOne("SELECT id FROM invoices WHERE invoice_no = :n AND xero_id IS NULL LIMIT 1", [':n' => $number]);
                }
                if ($local) {
                    $pdo->prepare(
                        "UPDATE invoices SET xero_id = :x, xero_status = :st, xero_amount_due = :due, xero_synced_at = NOW() WHERE id = :id"
                    )->execute([
                        ':x' => $xid, ':st' => $status,
                        ':due' => (float)($doc['AmountDue'] ?? 0),
                        ':id' => $local['id'],
                    ]);
                    $s['pulled_invoices']++;
                    continue;
                }

                // Xero-only invoice → mirror for CRM history.
                // Voided/deleted: mark the mirror row's status (CRM filters these out).
                // Use UPDATE, not DELETE — the app DB user is granted SELECT/INSERT/UPDATE only.
                if (in_array($status, ['DELETED', 'VOIDED'], true)) {
                    $pdo->prepare("UPDATE xero_invoices_mirror SET status = :st WHERE xero_id = :x")
                        ->execute([':st' => $status, ':x' => $xid]);
                    continue;
                }
                // Respect the sync-from cutoff: don't mirror historical Xero invoices
                $docDate = substr((string)XeroClient::parseDate($doc['DateString'] ?? $doc['Date'] ?? null), 0, 10);
                $fromDate = self::syncFromDate();
                if ($fromDate !== '' && $docDate !== '' && $docDate < $fromDate) {
                    continue;
                }
                $contactId = $doc['Contact']['ContactID'] ?? null;
                $cust = $contactId
                    ? self::fetchOne("SELECT id FROM customers WHERE xero_id = :x LIMIT 1", [':x' => $contactId])
                    : null;

                $pdo->prepare(
                    "INSERT INTO xero_invoices_mirror
                        (xero_id, customer_id, invoice_no, reference, status, doc_date, due_date, subtotal, vat_amount, total, amount_due)
                     VALUES (:x, :cid, :no, :ref, :st, :d, :dd, :sub, :vat, :tot, :due)
                     ON DUPLICATE KEY UPDATE
                        customer_id = VALUES(customer_id), invoice_no = VALUES(invoice_no),
                        reference = VALUES(reference), status = VALUES(status),
                        doc_date = VALUES(doc_date), due_date = VALUES(due_date),
                        subtotal = VALUES(subtotal), vat_amount = VALUES(vat_amount),
                        total = VALUES(total), amount_due = VALUES(amount_due)"
                )->execute([
                    ':x'   => $xid,
                    ':cid' => $cust['id'] ?? null,
                    ':no'  => $number ?: null,
                    ':ref' => $doc['Reference'] ?? null,
                    ':st'  => $status,
                    ':d'   => substr((string)XeroClient::parseDate($doc['DateString'] ?? $doc['Date'] ?? null), 0, 10) ?: null,
                    ':dd'  => substr((string)XeroClient::parseDate($doc['DueDateString'] ?? $doc['DueDate'] ?? null), 0, 10) ?: null,
                    ':sub' => (float)($doc['SubTotal'] ?? 0),
                    ':vat' => (float)($doc['TotalTax'] ?? 0),
                    ':tot' => (float)($doc['Total'] ?? 0),
                    ':due' => (float)($doc['AmountDue'] ?? 0),
                ]);
                $s['mirrored_invoices']++;
            } catch (Throwable $e) {
                $s['errors']++;
                self::log('pull', 'invoice', null, $doc['InvoiceID'] ?? null, 'error', 'error', $e->getMessage());
            }
        }
        xeroSaveSetting('xero_last_pull_invoices', gmdate('Y-m-d H:i:s'));
    }

    // ============================================================
    // Quotes → Xero
    // ============================================================

    private static function pushQuotes(array &$s): void {
        $pdo = getDB();
        $fromDate = self::syncFromDate();
        $sql = "SELECT q.* FROM quotes q
                WHERE q.xero_id IS NULL AND q.status IN ('sent','accepted')";
        $params = [];
        if ($fromDate !== '') {
            $sql .= " AND DATE(q.created_at) >= :fromdate";
            $params[':fromdate'] = $fromDate;
        }
        $sql .= " ORDER BY q.id ASC LIMIT 50";
        $qStmt = $pdo->prepare($sql);
        $qStmt->execute($params);
        $rows = $qStmt->fetchAll();

        $accountCode = xeroSetting('xero_account_code', '200');
        $taxType     = xeroSetting('xero_tax_type', 'OUTPUT2');
        $statusMap   = ['draft' => 'DRAFT', 'sent' => 'SENT', 'accepted' => 'ACCEPTED', 'declined' => 'DECLINED'];

        foreach ($rows as $r) {
            try {
                // Quotes store the customer inline — find the linked customer record
                $cust = null;
                if (($r['customer_email'] ?? '') !== '') {
                    $cust = self::fetchOne(
                        "SELECT id, xero_id FROM customers WHERE LOWER(email) = :e AND xero_id IS NOT NULL LIMIT 1",
                        [':e' => strtolower($r['customer_email'])]);
                }
                if (!$cust && ($r['customer_name'] ?? '') !== '') {
                    $cust = self::fetchOne(
                        "SELECT id, xero_id FROM customers WHERE name = :n AND xero_id IS NOT NULL LIMIT 1",
                        [':n' => $r['customer_name']]);
                }
                if (!$cust) {
                    self::log('push', 'quote', $r['id'], null, 'skip', 'ok',
                        "No Xero-linked customer for '{$r['customer_name']}' — will retry next sync");
                    continue;
                }

                $lineStmt = $pdo->prepare(
                    "SELECT qi.*, p.name AS product_name FROM quote_items qi
                     LEFT JOIN products p ON p.id = qi.product_id WHERE qi.quote_id = :id"
                );
                $lineStmt->execute([':id' => $r['id']]);
                $lineItems = [];
                foreach ($lineStmt->fetchAll() as $ln) {
                    $lineItems[] = [
                        'Description' => $ln['description'] ?: ($ln['product_name'] ?? 'Item'),
                        'Quantity'    => (float)($ln['qty'] ?? 1),
                        'UnitAmount'  => (float)$ln['unit_price'],
                        'AccountCode' => $accountCode,
                        'TaxType'     => $taxType,
                    ];
                }
                if (empty($lineItems)) { self::log('push', 'quote', $r['id'], null, 'skip', 'ok', 'No line items'); continue; }

                $payload = [
                    'Contact'         => ['ContactID' => $cust['xero_id']],
                    'QuoteNumber'     => $r['quote_no'],
                    'Date'            => substr($r['created_at'], 0, 10),
                    'Status'          => $statusMap[$r['status']] ?? 'SENT',
                    'LineAmountTypes' => 'Exclusive',
                    'LineItems'       => $lineItems,
                ];
                if (!empty($r['valid_until'])) $payload['ExpiryDate'] = $r['valid_until'];

                $saved = XeroClient::post('Quotes', ['Quotes' => [$payload]]);
                $doc = $saved['Quotes'][0] ?? null;
                if (!$doc) throw new RuntimeException('Xero did not return the saved quote');

                $pdo->prepare("UPDATE quotes SET xero_id = :x, xero_synced_at = NOW() WHERE id = :id")
                    ->execute([':x' => $doc['QuoteID'], ':id' => $r['id']]);
                self::log('push', 'quote', $r['id'], $doc['QuoteID'], 'create', 'ok', $r['quote_no'] . ' → ' . $r['customer_name']);
                $s['pushed_quotes']++;
            } catch (Throwable $e) {
                $s['errors']++;
                self::log('push', 'quote', $r['id'], null, 'error', 'error', $e->getMessage());
            }
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    private static function fetchOne(string $sql, array $params) {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function log(string $direction, string $entity, ?int $entityId, ?string $xeroId,
                               string $action, string $status, string $message = ''): void {
        try {
            getDB()->prepare(
                'INSERT INTO xero_sync_log (direction, entity, entity_id, xero_id, action, status, message)
                 VALUES (:d, :e, :eid, :x, :a, :s, :m)'
            )->execute([
                ':d' => $direction, ':e' => $entity, ':eid' => $entityId,
                ':x' => $xeroId, ':a' => $action, ':s' => $status,
                ':m' => mb_substr($message, 0, 990),
            ]);
        } catch (Throwable $e) { /* logging must never break the sync */ }
    }
}
