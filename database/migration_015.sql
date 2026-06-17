-- ============================================================
-- Migration 015 — Email Templates
-- ============================================================

CREATE TABLE IF NOT EXISTS email_templates (
    id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(50)     NOT NULL UNIQUE,
    label        VARCHAR(100)    NOT NULL,
    subject      VARCHAR(255)    NOT NULL DEFAULT '',
    body_html    TEXT            NOT NULL,
    updated_at   DATETIME        NULL DEFAULT NULL,
    updated_by   INT UNSIGNED    NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO email_templates (template_key, label, subject, body_html) VALUES

('invoice',
 'Invoice Email',
 'Your Invoice {{invoice_no}} from {{company_name}}',
 '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0;}.wrap{max-width:560px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);}.hdr{background:#1e40af;padding:24px 32px;}.hdr h1{color:#fff;margin:0;font-size:1.3rem;}.body{padding:28px 32px;}.body p{color:#374151;line-height:1.6;margin:0 0 14px;}.info{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;margin:18px 0;}.info table{width:100%;border-collapse:collapse;}.info td{padding:5px 4px;color:#374151;font-size:.9rem;}.info td:first-child{font-weight:600;color:#1e3a5f;width:140px;}.ftr{padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:.78rem;text-align:center;}</style></head><body><div class="wrap"><div class="hdr"><h1>Invoice from {{company_name}}</h1></div><div class="body"><p>Dear {{customer_name}},</p><p>Thank you for your purchase. Please find your invoice details below.</p>{{personal_note}}<div class="info"><table><tr><td>Invoice No:</td><td>{{invoice_no}}</td></tr><tr><td>Date:</td><td>{{invoice_date}}</td></tr><tr><td>Total (incl. VAT):</td><td><strong>R {{total}}</strong></td></tr><tr><td>Amount Due:</td><td><strong style="color:{{balance_color}};">R {{balance}}</strong></td></tr><tr><td>Payment Method:</td><td>{{payment_method}}</td></tr></table></div><p>If you have any questions regarding this invoice, please do not hesitate to contact us.</p><p>Kind regards,<br><strong>{{company_name}}</strong></p></div><div class="ftr">{{company_email}} &nbsp;|&nbsp; {{company_phone}}<br>This is an automated message from {{company_name}}.</div></div></body></html>'
),

('quote',
 'Quotation Email',
 'Your Quotation {{quote_no}} from {{company_name}}',
 '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0;}.wrap{max-width:560px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);}.hdr{background:#1e40af;padding:24px 32px;}.hdr h1{color:#fff;margin:0;font-size:1.3rem;}.body{padding:28px 32px;}.body p{color:#374151;line-height:1.6;margin:0 0 14px;}.info{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;margin:18px 0;}.info table{width:100%;border-collapse:collapse;}.info td{padding:5px 4px;color:#374151;font-size:.9rem;}.info td:first-child{font-weight:600;color:#1e3a5f;width:140px;}.ftr{padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:.78rem;text-align:center;}</style></head><body><div class="wrap"><div class="hdr"><h1>Quotation from {{company_name}}</h1></div><div class="body"><p>Dear {{customer_name}},</p><p>Please find your quotation details below. We look forward to hearing from you.</p>{{personal_note}}<div class="info"><table><tr><td>Quote No:</td><td>{{quote_no}}</td></tr><tr><td>Date:</td><td>{{quote_date}}</td></tr><tr><td>Valid Until:</td><td>{{valid_until}}</td></tr><tr><td>Total (incl. VAT):</td><td><strong>R {{total}}</strong></td></tr></table></div><p>To accept this quote or for any queries, please reply to this email or contact us directly.</p><p>Kind regards,<br><strong>{{company_name}}</strong></p></div><div class="ftr">{{company_email}} &nbsp;|&nbsp; {{company_phone}}<br>This is an automated message from {{company_name}}.</div></div></body></html>'
),

('credit_note',
 'Credit Note Email',
 'Credit Note {{credit_note_no}} issued against {{invoice_no}}',
 '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0;}.wrap{max-width:560px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);}.hdr{background:#dc2626;padding:24px 32px;}.hdr h1{color:#fff;margin:0;font-size:1.3rem;}.body{padding:28px 32px;}.body p{color:#374151;line-height:1.6;margin:0 0 14px;}.info{background:#fff5f5;border:1px solid #fecaca;border-radius:6px;padding:14px 18px;margin:18px 0;}.info table{width:100%;border-collapse:collapse;}.info td{padding:5px 4px;color:#374151;font-size:.9rem;}.info td:first-child{font-weight:600;color:#991b1b;width:160px;}.ftr{padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:.78rem;text-align:center;}</style></head><body><div class="wrap"><div class="hdr"><h1>Credit Note from {{company_name}}</h1></div><div class="body"><p>Dear {{customer_name}},</p><p>A credit note has been issued against your invoice. Please see the details below.</p>{{personal_note}}<div class="info"><table><tr><td>Credit Note No:</td><td>{{credit_note_no}}</td></tr><tr><td>Against Invoice:</td><td>{{invoice_no}}</td></tr><tr><td>Date:</td><td>{{date}}</td></tr><tr><td>Credit Amount:</td><td><strong style="color:#dc2626;">R {{total}}</strong></td></tr><tr><td>Reason:</td><td>{{reason}}</td></tr></table></div><p>If you have any questions, please contact us.</p><p>Kind regards,<br><strong>{{company_name}}</strong></p></div><div class="ftr">{{company_email}} &nbsp;|&nbsp; {{company_phone}}<br>This is an automated message from {{company_name}}.</div></div></body></html>'
);

-- Add nav link for Email Templates under Admin group
INSERT INTO nav_links (label, url, icon_class, role_required, display_order, group_label, is_active)
VALUES ('Email Templates', '/admin/email_templates.php', 'icon-email', 'admin', 92, 'Admin', 1);
