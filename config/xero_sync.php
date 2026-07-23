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
        ];
        self::pushCustomers($s);
        self::pullContacts($s);
        self::pushInvoices($s);
        self::pullInvoices($s);
        self::pushQuotes($s);
        xeroSaveSetting('xero_last_sync_at', date('Y-m-d H:i:s'));
        return $s;
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
            try {
                // Xero requires unique Name — business customers use company name.
                $xeroName = ($r['company_name'] ?? '') !== '' ? $r['company_name'] : $r['name'];
                $payload  = ['Name' => $xeroName];
                if (($r['email'] ?? '') !== '') $payload['EmailAddress'] = $r['email'];
                if (($r['vat_no'] ?? '') !== '') $payload['TaxNumber'] = $r['vat_no'];
                if (($r['phone'] ?? '') !== '') {
                    $payload['Phones'] = [['PhoneType' => 'DEFAULT', 'PhoneNumber' => $r['phone']]];
                }
                if (($r['address'] ?? '') !== '') {
                    $payload['Addresses'] = [['AddressType' => 'STREET', 'AddressLine1' => mb_substr($r['address'], 0, 500)]];
                }
                if (($r['company_name'] ?? '') !== '' && $r['name'] !== $r['company_name']) {
                    $parts = explode(' ', trim($r['name']), 2);
                    $payload['ContactPersons'] = [[
                        'FirstName' => $parts[0],
                        'LastName'  => $parts[1] ?? '',
                        'EmailAddress' => $r['email'] ?: null,
                        'IncludeInEmails' => true,
                    ]];
                }
                if ($r['xero_id']) $payload['ContactID'] = $r['xero_id'];

                $saved = XeroClient::post('Contacts', ['Contacts' => [$payload]]);
                $c = $saved['Contacts'][0] ?? null;
                if (!$c) throw new RuntimeException('Xero did not return the saved contact');

                $pdo->prepare("UPDATE customers SET xero_id = :xid, xero_synced_at = NOW() WHERE id = :id")
                    ->execute([':xid' => $c['ContactID'], ':id' => $r['id']]);
                self::log('push', 'customer', $r['id'], $c['ContactID'], $r['xero_id'] ? 'update' : 'create', 'ok', $xeroName);
                $s['pushed_customers']++;
            } catch (Throwable $e) {
                $s['errors']++;
                self::log('push', 'customer', $r['id'], $r['xero_id'], 'error', 'error', $e->getMessage());
            }
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

        // 1. New finalised invoices not yet on Xero
        $rows = $pdo->query(
            "SELECT i.*, c.xero_id AS cust_xero_id, c.name AS cust_name
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             WHERE i.status = 'active' AND i.xero_id IS NULL
             ORDER BY i.id ASC LIMIT 100"
        )->fetchAll();

        $accountCode = xeroSetting('xero_account_code', '200');
        $taxType     = xeroSetting('xero_tax_type', 'OUTPUT2');
        $payAccount  = xeroSetting('xero_payment_account_code'); // optional

        foreach ($rows as $r) {
            try {
                if (!$r['cust_xero_id']) {
                    self::log('push', 'invoice', $r['id'], null, 'skip', 'ok',
                        "Customer '{$r['cust_name']}' not on Xero yet — will retry next sync");
                    continue;
                }

                $lineStmt = $pdo->prepare(
                    "SELECT ii.*, p.name AS product_name, p.sku
                     FROM invoice_items ii
                     LEFT JOIN products p ON p.id = ii.product_id
                     WHERE ii.invoice_id = :id"
                );
                $lineStmt->execute([':id' => $r['id']]);
                $lineItems = [];
                foreach ($lineStmt->fetchAll() as $ln) {
                    $desc = $ln['product_name'] ?: 'Item';
                    if (!empty($ln['sku']))       $desc .= ' (' . $ln['sku'] . ')';
                    if (!empty($ln['serial_no'])) $desc .= ' — SN: ' . $ln['serial_no'];
                    $lineItems[] = [
                        'Description' => $desc,
                        'Quantity'    => (float)($ln['qty'] ?? 1),
                        'UnitAmount'  => (float)$ln['unit_price'],   // excl. VAT
                        'AccountCode' => $accountCode,
                        'TaxType'     => $taxType,
                    ];
                }
                // Portal discount → its own negative line so Xero's total matches ours
                if ((float)($r['discount_amount'] ?? 0) > 0) {
                    $lineItems[] = [
                        'Description' => 'Discount (' . rtrim(rtrim(number_format((float)$r['discount_pct'], 1), '0'), '.') . '%)',
                        'Quantity'    => 1,
                        'UnitAmount'  => -(float)$r['discount_amount'],
                        'AccountCode' => $accountCode,
                        'TaxType'     => $taxType,
                    ];
                }
                if (empty($lineItems)) { self::log('push', 'invoice', $r['id'], null, 'skip', 'ok', 'No line items'); continue; }

                $docDate = substr($r['invoice_date'] ?? $r['created_at'], 0, 10);
                $payload = [
                    'Type'            => 'ACCREC',
                    'Contact'         => ['ContactID' => $r['cust_xero_id']],
                    'InvoiceNumber'   => $r['invoice_no'],
                    'Date'            => $docDate,
                    'DueDate'         => $r['due_date'] ?: $docDate,
                    'Reference'       => 'Blackview Portal — ' . ucfirst($r['channel'] ?? ''),
                    'Status'          => 'AUTHORISED',
                    'LineAmountTypes' => 'Exclusive',
                    'LineItems'       => $lineItems,
                ];

                $saved = XeroClient::post('Invoices', ['Invoices' => [$payload]]);
                $doc = $saved['Invoices'][0] ?? null;
                if (!$doc) throw new RuntimeException('Xero did not return the saved invoice');
                $xid = $doc['InvoiceID'];

                // 2. Optional: mirror local POS payments so cash sales don't pile up unpaid in Xero
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
                self::log('push', 'invoice', $r['id'], $xid, 'create', 'ok', $r['invoice_no'] . ' → ' . $r['cust_name']);
                $s['pushed_invoices']++;
            } catch (Throwable $e) {
                $s['errors']++;
                self::log('push', 'invoice', $r['id'], null, 'error', 'error', $e->getMessage());
            }
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

                // Xero-only invoice → mirror for CRM history
                if (in_array($status, ['DELETED', 'VOIDED'], true)) {
                    $pdo->prepare("DELETE FROM xero_invoices_mirror WHERE xero_id = :x")->execute([':x' => $xid]);
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
        $rows = $pdo->query(
            "SELECT q.* FROM quotes q
             WHERE q.xero_id IS NULL AND q.status IN ('sent','accepted')
             ORDER BY q.id ASC LIMIT 50"
        )->fetchAll();

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
