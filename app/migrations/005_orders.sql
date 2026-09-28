-- Orders and till details for stage 4.
-- parent_id: a separate bill split off an order; label: takeaway/delivery name shown on tickets and the till list.
ALTER TABLE orders ADD COLUMN parent_id TEXT;
ALTER TABLE orders ADD COLUMN label TEXT;
ALTER TABLE orders ADD COLUMN approved_by TEXT;
CREATE INDEX IF NOT EXISTS orders_channel ON orders(channel, status);
CREATE INDEX IF NOT EXISTS orders_customer ON orders(customer_id);
-- payments: who took the cash for a delivery (courier settlement) and the order line list for a split.
ALTER TABLE payments ADD COLUMN courier_id TEXT;
ALTER TABLE payments ADD COLUMN note TEXT;
CREATE INDEX IF NOT EXISTS payments_shift ON payments(shift_id);
-- shifts: which till (device) and the running Z number.
CREATE INDEX IF NOT EXISTS shifts_open ON shifts(closed_at);
