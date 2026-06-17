# Blackview SA — Master Portal
## User Manual & Training Guide

**Version 1.0 · Confidential — Internal Use Only**

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Getting Started — Login & Navigation](#2-getting-started)
3. [User Roles & Permissions](#3-user-roles--permissions)
4. [Dashboard](#4-dashboard)
5. [Inventory — Scan In Stock](#5-scan-in-stock)
6. [Inventory — Move Stock](#6-move-stock)
7. [Inventory — Take Out Stock](#7-take-out-stock)
8. [Inventory — View Stock](#8-view-stock)
9. [Point of Sale (POS)](#9-point-of-sale-pos)
10. [Purchasing — Purchase Orders](#10-purchase-orders)
11. [Reports](#11-reports)
12. [Admin Functions](#12-admin-functions) *(Admin/Superuser only)*
13. [Mobile App](#13-mobile-app)
14. [Quick Reference — Channels Explained](#14-channels-explained)
15. [Common Questions & Troubleshooting](#15-troubleshooting)

---

## 1. System Overview

The **Blackview SA Master Portal** is your central hub for managing all stock, sales, and inventory across your warehouses. Everything that comes into the business, moves between locations, or goes out to a customer is tracked here — with a full serial number trail on every unit.

**What the system does:**
- Tracks every device by its unique serial number from the moment it arrives to the moment it is sold
- Manages stock levels across multiple warehouses in real time
- Processes point-of-sale invoices with automatic VAT calculation
- Links incoming stock to Purchase Orders so you always know what was received against what was ordered
- Gives managers daily cashup reports and full movement history
- Provides a mobile-friendly app for warehouse staff working on the floor

---

## 2. Getting Started

### Logging In

1. Open the portal URL in your browser
2. Enter your **email address** and **password**
3. Click **Login**

> If you are on a **mobile phone or tablet**, the system automatically takes you to the streamlined Mobile App. If you want the full desktop version, tap your initials (top right) and select **Switch to Desktop Site**.

### Session Timeout

For security, the system logs you out automatically after **2 hours** of inactivity. You will see a message and be taken back to the login screen. Simply log in again — your data is safe.

### Navigation (Desktop)

The **left sidebar** contains all navigation links. Links shown depend on your role — not everyone sees every section. On smaller screens the sidebar slides in from a hamburger menu (☰) at the top left.

### Navigation (Mobile App)

Five icons appear along the **bottom** of the screen:

| Icon | Page |
|------|------|
| 🏠 Home | Overview & quick actions |
| ☐ Scan In | Add new stock |
| → Move | Transfer stock between warehouses |
| ↗ Take Out | Remove/sell stock |
| 📋 POS | Create a sale invoice |

---

## 3. User Roles & Permissions

There are three levels of access:

| Role | What they can do |
|------|-----------------|
| **User** | Scan In, Move Stock, Take Out, View Stock, POS |
| **Admin** | Everything a User can do + Products, Warehouses, Purchase Orders, Reports, User Management |
| **Superuser** | Full access including system settings and audit logs |

> **Important:** A user only sees the menu items their role allows. If you cannot find a page, you may not have permission — contact your admin.

---

## 4. Dashboard

The dashboard is the first page you see after logging in. It gives you a live snapshot of the business.

### Stat Cards (top row)

| Card | What it shows |
|------|--------------|
| Active Products | Number of product models currently active in the system |
| Units in Stock | Total physical units across all warehouses right now |
| Active Warehouses | Number of warehouse locations |
| Movements This Month | How many stock movements (in/out/transfers) happened this calendar month |

### Sales Leaderboard (Trophies)

The 🥇🥈🥉 trophy badges in the top bar show the **top 3 salespeople by total revenue** (all time). Click any trophy to open a printable **Certificate of Sales Excellence** for that person — useful for recognition and motivation.

### Recent Movements Table

Shows the last 10 stock movements. Columns include the date, product, quantity, channel (where it went), and who performed the movement. Click any row to see more detail.

### Mobile App button

A **Mobile App** button in the top right of the page header takes you directly to the mobile interface — useful if you want to hand your device to a colleague for quick scanning.

---

## 5. Scan In Stock

**Who uses this:** Receiving staff, warehouse managers
**When to use:** When new stock arrives at your warehouse — whether from a supplier, a return, or any other source

### Step-by-step

1. Go to **Inventory → Scan In Stock**
2. **Choose a source:**
   - **Purchase Order** — if this stock was ordered via the system, select the matching PO. The warehouse will auto-fill and stock will be marked as received against that order.
   - **Manual / Other** — for returns, consignments, initial stock loads. Enter a reference note.
3. **Select the Product** from the dropdown (search by name or SKU)
4. **Select the Warehouse** where the stock is going
5. **Enter Serial Numbers** — one per line. You can:
   - Scan barcodes directly into the text field (a barcode scanner will add each serial on its own line)
   - Type them manually
   - Paste from a spreadsheet
6. Review the serial count shown below the input box
7. Click **Scan In Stock**

A success message confirms how many units were added and to which warehouse.

### CSV Import (bulk loading)

For large batches it is faster to use a spreadsheet:

1. Click the **CSV Upload** tab
2. Download the **CSV Template**
3. Fill it in: `product_sku`, `warehouse_name`, `serial_no` — one row per unit
4. Upload the file and click **Import CSV**
5. Any errors (unknown SKU, duplicate serial) are listed so you can correct and re-import

> **Tip:** Duplicate serial numbers are automatically detected and rejected. A serial can only exist once in the system.

---

## 6. Move Stock

**Who uses this:** Warehouse staff, logistics
**When to use:** When stock moves from one warehouse to another, or when fulfilling an online order (Takealot, Makro, etc.)

### Step-by-step

1. Go to **Inventory → Move Stock**
2. **Select the Product**
3. **Select From Warehouse** (where the stock is now)
4. **Select To Warehouse** (where it is going)
5. **Enter Serial Numbers** to move — one per line (scan or type)
6. **Select the Channel** — where is this stock going? (see Section 14)
7. **Invoice / Reference No.** — optional, but enter the customer invoice number or order reference if you have one
8. **Notes** — any extra information
9. Click **Move Stock**

The stock is immediately removed from the source warehouse and added to the destination warehouse. Stock levels update in real time.

### Important rules
- You cannot move stock that does not exist in the source warehouse at `in_stock` status
- You cannot move stock from and to the same warehouse
- Each serial number must be in stock before it can be moved

### CSV Import

Same process as Scan In — download the template, fill in all columns including `from_warehouse`, `to_warehouse`, `channel`, and `serial_no`, then upload.

---

## 7. Take Out Stock

**Who uses this:** Sales staff, warehouse staff
**When to use:** When stock leaves the business permanently — sold in-store, dispatched to a marketplace, written off

> **This is a one-way action.** Taking out stock marks units as **sold** and removes them from inventory. This cannot be undone through the system — contact your admin if a mistake is made.

### Step-by-step

1. Go to **Inventory → Take Out Stock**
2. **Select the Channel / Reason** (Takealot, Makro, In-Store, Email Order, Other/Write-off)
3. **Enter Serial Numbers** — one per line. You do NOT need to know which warehouse they are in; the system finds them automatically
4. **Invoice / Reference No.** — enter if available
5. Click **Take Out Stock** and confirm the prompt

The system looks up each serial, finds its current location, and marks it as sold.

> **Tip:** If you enter a serial that is not found in stock (already sold, never scanned in, or typo), the system will show you which ones failed. Correct them and try again.

---

## 8. View Stock

**Who uses this:** Everyone
**When to use:** To check what stock is available, where it is, and what the serial numbers are

### How to use

1. Go to **Inventory → View Stock**
2. Optionally filter by **Product** or **Warehouse** using the dropdowns
3. Click **Filter** (or the filters apply automatically on mobile)

The table shows each product/warehouse combination with the current quantity.

**To see the serial numbers for a row:** Click the **"Show serials"** button (desktop) or tap the row (mobile). The list of serial numbers for that product at that warehouse expands below.

> **What to check before a sale:** Before selling a specific unit, check View Stock to confirm the serial is physically available.

---

## 9. Point of Sale (POS)

**Who uses this:** Sales staff
**When to use:** When making an in-store or direct sale and you need to issue an invoice

### Creating a sale

1. Go to **POS**
2. **Scan or enter a serial number** in the scan bar and click **Add** (or press Enter). The system looks up the product name, price, and warehouse automatically
3. Repeat for each unit being sold
4. **Adjust the unit price** per line if needed (e.g. negotiated price)
5. **Discount** — a discount of up to **10%** can be applied across the whole sale
6. Fill in **Customer Details:**
   - Name (required)
   - Phone number
   - Email address (recommended — used to identify returning customers)
   - ID number (optional)
7. **Select the Payment Method:** Cash / EFT / Card
8. Add any **Notes** if needed
9. Click **Create Invoice**

The system generates an invoice number (format: `INV-YYYYMM-####`), records the sale, marks the serial numbers as sold, updates stock levels, and opens the printable invoice.

### VAT

VAT at **15%** is calculated automatically. The invoice shows:
- Subtotal (excl. VAT)
- VAT amount
- **Total (incl. VAT)**

### Returning customers

If a customer's email address already exists in the system, their details are updated automatically — you do not create duplicates.

---

## 10. Purchase Orders

**Who uses this:** Admin, Managers
**When to use:** When ordering stock from a supplier — creating a record of what was ordered so receiving staff can scan against it

### Creating a Purchase Order

1. Go to **Purchasing → Purchase Orders**
2. Click **New Purchase Order**
3. Select the **Supplier**
4. Select the **Destination Warehouse** (where the stock will arrive)
5. Add line items: select each **Product** and enter the **Quantity Ordered** and **Cost Price**
6. Click **Create Purchase Order**

A PO number is generated (format: `PO-YYYYMMDD-###`).

### PO Statuses

| Status | Meaning |
|--------|---------|
| **Pending** | Created but nothing received yet |
| **Partial** | Some items received, still waiting for the rest |
| **Received** | All ordered quantities have been scanned in |
| **Cancelled** | Order was cancelled |

### Receiving against a PO

When stock arrives, go to **Scan In Stock**, select **Purchase Order** as the source, and choose the PO from the list. As you scan in serials, the PO's received quantities update automatically. When all units are received, the PO status changes to **Received**.

### Suppliers

Supplier records (name, contact details) are managed in **Purchasing → Suppliers**. Add a new supplier before creating your first PO for them.

---

## 11. Reports

### Daily Cashup

**Go to: Reports → Daily Cashup**

Shows all invoices issued on a selected date, broken down by payment method (Cash / EFT / Card).

- Select the date using the date picker (defaults to today)
- See totals per payment method: number of sales, subtotal, VAT, total
- Grand total at the bottom
- Full list of individual invoices with customer names
- **Print** the report for end-of-day reconciliation

> **Daily process:** At close of business, run the cashup report for today. Print or save it as a PDF. Reconcile the Cash total against your till float. Reconcile EFT/Card against your bank/card machine records.

### Stock Movements Report

**Go to: Reports → Stock Movements**

A full searchable history of every stock movement ever recorded.

**Filters available:**
- Date from / Date to
- Channel (Takealot, Makro, In-Store, etc.)
- Warehouse
- Product

Each row shows: date, product, quantity, from warehouse, to warehouse, channel, invoice number, and who did it.

**Expand serial numbers:** Click **Show all** on any row to see every serial number included in that movement.

Use this report to:
- Investigate a specific serial number's history
- See all Takealot dispatches in a date range
- Audit what a specific staff member moved

---

## 12. Admin Functions

*Available to Admin and Superuser roles only*

### Product Management

**Go to: Admin → Products**

- **Add a product:** Click New Product. Enter the SKU (must be unique), product name, brand, category, and optionally a description, cost price, selling price, and product image
- **Edit a product:** Click the edit icon on any row
- **Deactivate a product:** Toggle the active status. Inactive products do not appear in stock dropdowns
- **CSV Import:** Bulk-load products from a spreadsheet using the provided template

> **SKU naming convention:** Keep SKUs consistent — e.g. `BV-A95-256` for a Blackview A95 with 256GB. Once set, SKUs appear throughout the system and on invoices.

### Warehouse Management

**Go to: Admin → Warehouses**

- Add, edit, or deactivate warehouse locations
- Each warehouse needs a name (e.g. "Head Office", "Takealot JHB", "Cape Town Branch")
- Deactivating a warehouse hides it from dropdowns but retains all historical data

### User Management

**Go to: Admin → Users**

- **Add a user:** Enter their name, email, and a temporary password. Assign a role (User / Admin / Superuser)
- **Edit / deactivate:** Click edit on any user. Deactivating prevents login without deleting history
- Passwords are stored securely (hashed — nobody can read them)

> **Best practice:** Each person should have their own login. Never share accounts. This keeps the audit trail accurate.

### User Nav Permissions

**Go to: Admin → User Permissions**

For cases where a user needs a custom set of menu items — different from what their role normally shows — you can assign specific navigation links to that user. If custom permissions are set for a user, they override the role defaults entirely.

### Settings

**Go to: Admin → Settings**

- **Company name** — appears on invoices and certificates
- **Company tagline** — shown on the login page
- **Logo** — upload your company logo; it appears on the login page and printed invoices

### Audit Log

**Go to: Admin → Audit Log**

A complete, tamper-evident log of every significant action in the system: logins, stock scans, movements, invoice creation, user changes, and more.

Each entry records:
- Date and time
- Which user did it
- What action was taken
- Which record was affected
- The IP address

Use this to investigate discrepancies or verify what happened on a specific date.

---

## 13. Mobile App

The Mobile App is a simplified, touch-friendly version of the system designed for use on phones and tablets — particularly in the warehouse.

### Accessing it

- **Automatic:** If you log in from a phone or tablet, you are taken directly to the Mobile App
- **Manual:** From the desktop dashboard, click the blue **Mobile App** button (top right)
- **Direct URL:** `[your portal address]/mobile/`

### What's available on mobile

| Feature | Available |
|---------|-----------|
| Home dashboard with stats | ✅ |
| Scan In Stock | ✅ |
| Move Stock | ✅ |
| Take Out Stock | ✅ |
| View Stock (read-only) | ✅ |
| Point of Sale | ✅ |
| Reports | Desktop only |
| Admin / Settings | Desktop only |
| Purchase Orders | Desktop only |

### Serial number scanning on mobile

On the Scan In, Move, and Take Out pages, the serial number input works like this:
- Tap the input area to open the keyboard
- Scan a barcode with a Bluetooth scanner (or type and press **Enter**)
- Each serial appears as a blue chip/tag
- Tap the **✕** on any chip to remove it
- Tap **Submit** when done

### Sign out on mobile

Tap your **initial/avatar** button (top right of any screen) to open the account panel. Tap **Sign Out**.

### Switching back to desktop

Tap your **initial/avatar** → **Switch to Desktop Site**.

---

## 14. Channels Explained

When moving or taking out stock, you select a **channel**. This records *why* the stock moved — critical for your stock movement reports.

| Channel | When to use |
|---------|------------|
| **Takealot** | Units dispatched to or sold via Takealot |
| **Makro** | Units dispatched to or sold via Makro |
| **In-Store** | Walk-in customer purchase at the physical store |
| **Email Order** | Customer ordered via email or WhatsApp |
| **Transfer** | Internal stock transfer between your own warehouses (not a sale) |
| **Received** | Used when moving stock that was received back into a warehouse |
| **Other / Write-off** | Damaged, lost, stolen, or donated units |

> **Tip for managers:** The Stock Movements Report can be filtered by channel. This lets you quickly see, for example, all Takealot dispatches in a given week, or all write-offs for the month.

---

## 15. Troubleshooting

### "Serial already exists in the system"
The serial number has already been scanned in previously. Check View Stock or the Stock Movements Report to see where it is. If it was entered by mistake, an admin can correct it via the audit log investigation.

### "Serial not found or not in stock"
When taking out or selling a unit, the system cannot find that serial as `in_stock`. Possible reasons:
- Typo in the serial number — check the label carefully
- The unit was already sold (check the movements report)
- The unit was never scanned in — go to Scan In Stock first

### "Serial not found at this warehouse"
When moving stock, the serial exists in the system but is at a different warehouse. Check View Stock to find its current location, then select the correct **From Warehouse**.

### I can't see a page/menu item
Your account role may not include that page. Contact your admin to check your permissions or role.

### I made a mistake — scanned the wrong serial / wrong warehouse
Contact your **Admin or Superuser** immediately. They can review the Audit Log, identify the incorrect record, and make a corrective entry (e.g. scan the unit back in / move it back). The system does not have an undo button, but all errors can be corrected with the right steps.

### The page is showing old stock numbers
The system is live — numbers reflect the current state. If you suspect something is wrong, try refreshing the page. If it still looks wrong, check the Stock Movements Report for recent activity.

### I forgot my password
Contact your admin. They can reset your password in **Admin → Users**.

---

## Quick Tip Sheet — For Staff

Cut this out and keep it at your workstation.

```
SCANNING STOCK IN
─────────────────
1. Choose source: PO or Manual
2. Select product + warehouse
3. Scan serials (one per line)
4. Click "Scan In Stock"

MOVING STOCK
────────────
1. Select product
2. From warehouse → To warehouse
3. Scan serials
4. Select channel (Takealot / Makro / etc.)
5. Click "Move Stock"

TAKING OUT STOCK
─────────────────
1. Select channel/reason
2. Scan serials (system finds the warehouse)
3. Add invoice number if you have one
4. Confirm and click "Take Out Stock"

POS SALE
────────
1. Scan each unit's serial → Add
2. Adjust price if needed
3. Add customer name + phone
4. Select payment: Cash / EFT / Card
5. Click "Create Invoice" → Print

REMEMBER
────────
• One serial = one unit. Never skip scanning.
• Wrong warehouse? Fix it with Move Stock.
• Sold and regret it? Call the admin — do not
  scan it in again without checking first.
• If in doubt, check View Stock first.
```

---

*Blackview SA Master Portal — Internal Training Document*
*Prepared by OhSmart (Pty) Ltd*
