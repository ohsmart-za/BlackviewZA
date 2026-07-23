# Blackview SA Portal — Project Guide

B2B portal for Blackview SA (electronics/smartphone brand). Manages products, inventory, customers,
point-of-sale, invoicing, quotes, credit notes, purchasing, payments, and reporting.

- **Production URL:** `https://b2b.blackview.co.za`
- **Local URL:** `http://localhost/BlackviewZA`

## Stack

- **Backend:** plain PHP 8 + MySQL (PDO). No framework.
- **Frontend:** server-rendered PHP pages + vanilla JS. No build step, no SPA framework, no npm.
- **CSS:** single stylesheet at `assets/css/style.css`.
- **Auth:** session cookies. Roles: `user`, `admin`, `superuser`.
- **Email:** custom SMTP mailer (`config/mailer.php`) — supports Gmail OAuth or any SMTP. Config stored in `settings` DB table.
- **Payments:** Yoco and PayFast webhooks under `payment/`.

## Directory Layout

```
config/
  config.php          DB_*, BASE_URL, APP_NAME, SESSION_NAME, SESSION_TIMEOUT
  db.php              getDB() — returns PDO singleton
  auth.php            Auth helpers (see below)
  settings.php        getSettings(), getSetting(), saveSettings()
  mailer.php          Mailer class, sendDirectEmail(), sendDocumentEmail(), renderEmailTemplate()
  invoice_helpers.php finaliseDraftStock() — stock deduction at invoice finalise time

includes/
  header.php          HTML head + navbar (loads settings, nav_links)
  footer.php          Closing HTML
  navbar.php          Navigation rendered from nav_links table

admin/
  users.php           User management (roles, can_use_pos, can_edit_invoices)
  products.php        Product catalogue (physical / service types)
  settings.php        Company info, SMTP, logo, etc.
  payment_methods.php Cash / EFT / Card etc.
  nav_links.php       Manage navigation links
  audit_log.php       View audit trail
  warehouses.php      Warehouse management
  customer_statement.php  Customer account statement + email

pos/
  index.php           Point of Sale (restricted by can_use_pos flag)
  invoice.php         Invoice viewer — view, finalise draft, void, email
  invoices.php        Invoice list
  quotes.php          Quote creation and list
  quote_view.php      Single quote view / PDF / email
  invoice_edit.php    Invoice editing (restricted by can_edit_invoices flag)
  credit_note.php     Credit note creation
  credit_notes.php    Credit note list
  credit_note_view.php

invoices/
  create.php          Xero-style invoice creation (for sales staff without POS access)
  ajax.php            AJAX-only: customer search, product search (clean JSON — no page output)

crm/
  index.php           Customer list + search
  customer.php        Customer detail: edit, invoice history, quote history

inventory/
  view_stock.php      Stock levels by product/warehouse
  serials.php         Serial number search
  stock_operations.php  Scan-in / adjustments
  scan_in.php         Receive stock
  move_stock.php      Transfer between warehouses
  take_out.php        Write-off / non-sale removal

reports/
  stock_executive.php  Executive stock summary (physical products only)
  stock_movements.php  Movement history
  cashup.php          Daily cashup / sales summary

purchasing/
  orders.php          Purchase orders
  suppliers.php       Supplier list
  order_view.php      PO detail

mobile/             Mobile-optimised versions of key pages

database/
  migration_NNN.sql   Numbered DB migrations (001 → 022 at time of writing)
  schema.sql          Full schema for fresh installs

run_migration.php     Browser-based migration runner (admin-only, delete after use)
```

## Auth Helpers (config/auth.php)

| Function | What it does |
|---|---|
| `requireLogin()` | Redirect to `index.php` if not logged in; enforces session timeout |
| `requireAdmin()` | Requires role `admin` or `superuser` |
| `requireSuperuser()` | Requires role `superuser` only |
| `isAdmin()` | Returns bool — true for admin or superuser |
| `isSuperuser()` | Returns bool — true for superuser only |
| `currentUser()` | Returns `[id, name, email, role]` array or null |
| `setFlash($type, $msg)` | Queue a flash message (`success|error|warning|info`) |
| `logAudit($pdo, $action, $entity, $entity_id, $details)` | Write to `audit_log` table |
| `getNavLinks($pdo)` | Returns nav items for current user (role-based or per-user override) |

## Key Patterns

### Every page starts with:
```php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();          // or requireAdmin() / requireSuperuser()
$pdo = getDB();
```

### AJAX endpoints
- Use a **dedicated file** (e.g. `invoices/ajax.php`) rather than mixing AJAX into full pages.
- Always wrap with `ob_start()` / `ob_end_clean()` before `header('Content-Type: application/json')` to strip any PHP notices that would corrupt JSON.
- Pattern:
```php
ob_start();
require_once '../config/config.php';
require_once '../config/db.php';
require_once '../config/auth.php';
requireLogin();
ob_end_clean();
header('Content-Type: application/json');
```

### Dropdowns (autocomplete)
- To **show** a dropdown that has `display:none` in CSS, always set `element.style.display = 'block'` — never `''` (empty string removes the inline style and lets the CSS `display:none` win again).
- To **hide** it, set `element.style.display = 'none'`.
- Customer search dropdowns always append an **"➕ Add as new customer"** option when results are empty.

### XSS
- Always `htmlspecialchars($value)` before inserting user data into HTML.
- In JS, always use `escHtml(str)` before putting values in `innerHTML`.

### Settings
```php
require_once __DIR__ . '/../config/settings.php';
$settings  = getSettings($pdo);          // all settings as key=>value array
$coName    = $settings['company_name'] ?? 'Blackview SA';
$coVatNo   = $settings['company_vat_no'] ?? '';
// or single key:
$val = getSetting($pdo, 'smtp_host', '');
```

### Email
```php
require_once __DIR__ . '/../config/mailer.php';
// Raw HTML email (no template lookup):
$result = sendDirectEmail($pdo, $toEmail, $toName, $subject, $htmlBody);
// Template-based (uses email_templates table):
$result = sendDocumentEmail($pdo, 'invoice', $vars, $toEmail, $toName);
// $result = ['ok' => bool, 'error' => string]
```

## Products — physical vs service

Products have `product_type ENUM('physical','service')`.

- **Physical** products: have stock, serials, inventory movements.
- **Service** products: no stock at all — skip every inventory/movement/serial operation.
- Always check: `COALESCE(product_type,'physical') = 'physical'` when filtering inventory queries.
- In POS/invoice logic: `$si['is_service'] = ($prodTypeMap[$pid] ?? 'physical') === 'service'` — wrap all stock ops in `if (!$si['is_service']) { ... }`.

## Invoices — draft workflow

| Status | Prefix | Stock deducted | Notes |
|---|---|---|---|
| `draft` | `DRF-YYYYMM-####` | No | Saved for later, no stock impact |
| `active` | `INV-YYYYMM-####` | Yes | Finalised — stock deducted |
| `voided` | — | Restored | Stock returned to `in_stock` |

- Finalising a draft calls `finaliseDraftStock($pdo, $invoiceId, $invoiceNo, $channel)` from `config/invoice_helpers.php`.
- Serialised items: auto-assigned FIFO from `stock_items`; marks serials `sold`, decrements `inventory_stock`.
- Non-serialised items: finds warehouse with highest qty, decrements `inventory_stock`.

## User Permission Flags

| Column | Table | Default | Meaning |
|---|---|---|---|
| `role` | `users` | `user` | `user` / `admin` / `superuser` |
| `can_edit_invoices` | `users` | `0` | Can edit posted invoices (audit-logged) |
| `can_use_pos` | `users` | `1` | Access to POS (`pos/index.php`); when 0, redirected to `/invoices/create.php` |

## Xero Integration

Xero is the **main accounting system**; the portal is the processing front-end. Two-way sync
built on the portal's own tables (no staging copies), ported from the SageSync project's client.

- **Files:** `config/xero_client.php` (OAuth2 client), `config/xero_sync.php` (`XeroSync::run()`),
  `auth/xero_callback.php` (OAuth redirect), `admin/xero.php` (connect/settings/sync/log).
- **Link columns:** `customers.xero_id` (ContactID), `invoices.xero_id/xero_status/xero_amount_due`,
  `quotes.xero_id`; each with `xero_synced_at`. `xero_invoices_mirror` holds invoices that exist
  only in Xero (shown in CRM history + totals). Tokens in `xero_oauth_tokens`; log in `xero_sync_log`.
- **Dirty rule:** a row needs pushing when `updated_at > xero_synced_at` (MySQL auto-maintains
  `updated_at`). Sync-side writes always set `xero_synced_at = NOW()` in the same statement so
  they never re-flag themselves. Local edits win pull conflicts.
- **Flow:** customers push+pull (merge by xero_id → email → name); `active` invoices push as
  ACCREC AUTHORISED (discount as negative line, `LineAmountTypes: Exclusive`); voided → VOIDED
  (fails if paid in Xero → status `VOID_FAILED`, no retry); status/AmountDue pulled back;
  sent/accepted quotes push. Optional `xero_payment_account_code` setting pushes POS payments.
- **Settings keys:** `xero_client_id/secret/redirect_uri/scopes/tenant_id/account_code/tax_type/payment_account_code`.
  SA tax type = `OUTPUT2` (15%); Demo Company (Global) uses `OUTPUT`.
- Sync is manual via Admin → Xero Sync → "Sync Now". CRM shows live Xero balance for pushed invoices.

## Navigation

Nav items stored in `nav_links` table (`label, url, icon_class, role_required, display_order, is_active`).
Per-user overrides stored in `user_nav_permissions`. Managed at `admin/nav_links.php`.

To add a nav link via SQL:
```sql
INSERT IGNORE INTO nav_links (label, url, icon_class, role_required, display_order, is_active)
VALUES ('My Page', '/my/page.php', '', 'user', 50, 1);
```

## Database Changes

Use numbered migrations — **never** inline `try { ALTER TABLE … } catch`:

1. Create `database/migration_NNN.sql` (next number in sequence, currently up to 022).
2. Add the file to `run_migration.php`'s `$migrations` array.
3. Run `https://b2b.blackview.co.za/run_migration.php` (admin-only) after deploying files.
4. Or paste SQL directly into phpMyAdmin — make sure the correct database is selected first.

`ADD COLUMN IF NOT EXISTS` is safe to re-run. `MODIFY COLUMN` on ENUMs is idempotent.

## Deploy

No build step. Upload changed PHP/JS/CSS files via FTP. After deploy:
1. Run any new migrations (`run_migration.php` or phpMyAdmin).
2. If changes don't show, clear OPcache or restart PHP-FPM on the server.

**Never deploy:** `test.php`, `phpcheck.php`, `reset_superuser.php` — remove from server.
`run_migration.php` should be deleted immediately after running migrations.

## Local vs Production Config

- Local: `config/config.php` — `BASE_URL = 'http://localhost/BlackviewZA'`, DB = root/no-password.
- Production: `config/config - server.php` — copy to `config/config.php` on the server before deploying.
- Never commit production credentials.
