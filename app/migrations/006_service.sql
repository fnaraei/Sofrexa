-- Table service details (W1–W11, C1–C11).
-- orders.bill_at: the guest asked for the bill (waiter "Hesap iste" or the QR menu); receipt_note: printed under the receipt.
ALTER TABLE orders ADD COLUMN bill_at INTEGER;
ALTER TABLE orders ADD COLUMN receipt_note TEXT;
-- notifications replicate between the till and the web copy (QR bill requests, waiter calls), and remember "handled".
ALTER TABLE notifications ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0;
ALTER TABLE notifications ADD COLUMN deleted INTEGER NOT NULL DEFAULT 0;
ALTER TABLE notifications ADD COLUMN done_at INTEGER;
CREATE INDEX IF NOT EXISTS notifications_ref ON notifications(ref_type, ref_id);
