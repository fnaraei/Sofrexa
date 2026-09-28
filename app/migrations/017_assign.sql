-- Sharing guest orders between the waiters on shift, and the loud "your food is ready" alert (2026-09-28).
-- A QR order whose table nobody owns used to alert every waiter at once, so whoever looked first collected
-- them all. It is now given to one waiter on shift, and assigned_at remembers when, so that on an equal
-- load the one who has gone longest without an order takes the next one.
ALTER TABLE orders ADD COLUMN assigned_at INTEGER;
UPDATE orders SET assigned_at = opened_at WHERE waiter_id IS NOT NULL;
