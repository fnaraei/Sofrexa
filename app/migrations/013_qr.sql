-- QR table ordering (stage 11).
-- orders: a guest's QR order is created on the web copy with status 'pending'; the till takes it in (intake_at),
-- a waiter approves it (approved_at, approved_by) and its lines join the table's bill (merged_into) or it becomes the bill.
ALTER TABLE orders ADD COLUMN intake_at INTEGER;
ALTER TABLE orders ADD COLUMN approved_at INTEGER;
ALTER TABLE orders ADD COLUMN merged_into TEXT;
-- order_items: the guest order a line was placed in, kept after the line joins the table's bill (status page of the guest).
ALTER TABLE order_items ADD COLUMN src_order_id TEXT;
CREATE INDEX IF NOT EXISTS order_items_src ON order_items(src_order_id);
CREATE INDEX IF NOT EXISTS qr_sessions_table ON qr_sessions(table_id, closed_at);
