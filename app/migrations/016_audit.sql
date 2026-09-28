-- Fixes after the 2026-09-28 audit.
-- orders: the shift the bill was settled in (sales belong to that shift, payments to theirs), and how many lines a
-- guest order was placed with (the till takes a web order in only when all its lines have arrived).
ALTER TABLE orders ADD COLUMN closed_shift_id TEXT;
ALTER TABLE orders ADD COLUMN lines_expected INTEGER;
-- the phone a QR order came from (a hash of its cookie): each new phone's first order waits for a waiter
ALTER TABLE orders ADD COLUMN qr_device TEXT;
ALTER TABLE qr_sessions ADD COLUMN devices TEXT NOT NULL DEFAULT '[]';
-- order_items: portions sent to the kitchen (stock comes back per portion of what was really taken), the line a partly
-- voided part came from, and what happened to the dish after a void:
--   pending (sent, not ready: the till asks the kitchen) | returned (not cooked: back to stock) | waste (cooked, thrown away)
--   | table (cooked, given to another bill) | staff (cooked, charged to a staff member)
ALTER TABLE order_items ADD COLUMN sent_qty REAL;
ALTER TABLE order_items ADD COLUMN void_of TEXT;
ALTER TABLE order_items ADD COLUMN void_stock TEXT;
ALTER TABLE order_items ADD COLUMN reuse_ref TEXT;
ALTER TABLE order_items ADD COLUMN reuse_at INTEGER;
ALTER TABLE order_items ADD COLUMN reuse_by TEXT;
UPDATE order_items SET sent_qty = qty WHERE sent_at IS NOT NULL AND status <> 'void';
-- print_jobs: retried with growing pauses until the printer is back (a ticket is never dropped)
ALTER TABLE print_jobs ADD COLUMN next_at INTEGER;
UPDATE print_jobs SET status = 'retry' WHERE status = 'failed';
-- files already sent to the web copy (path → modified time and size)
CREATE TABLE IF NOT EXISTS sync_media (path TEXT PRIMARY KEY, mtime INTEGER NOT NULL, size INTEGER NOT NULL);
-- online order e-mails: sent only when the mail server took them; failures are tried again
ALTER TABLE online_mail ADD COLUMN status TEXT NOT NULL DEFAULT 'sent';
ALTER TABLE online_mail ADD COLUMN tries INTEGER NOT NULL DEFAULT 0;
ALTER TABLE online_mail ADD COLUMN next_at INTEGER;
