-- Time-based promotions (stage 13): a percentage off some dishes on some days and hours (happy hour).
-- scope: all | categories | items, with the ids in targets; days 1 (Monday) … 7; times 'HH:MM' (empty = all day,
-- an end before the start runs past midnight); dates 'Y-m-d' inclusive; channels: table (with QR), takeaway, delivery, online.
CREATE TABLE IF NOT EXISTS promotions (
  id TEXT PRIMARY KEY, name TEXT NOT NULL, names TEXT NOT NULL DEFAULT '{}', pct REAL NOT NULL,
  scope TEXT NOT NULL DEFAULT 'all', targets TEXT NOT NULL DEFAULT '[]', days TEXT NOT NULL DEFAULT '[1,2,3,4,5,6,7]',
  time_from TEXT, time_to TEXT, date_from TEXT, date_to TEXT,
  channels TEXT NOT NULL DEFAULT '["table","takeaway","delivery","online"]', active INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 0, created_at INTEGER, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
-- order_items: the promotion a line was sold under and the menu price before it.
ALTER TABLE order_items ADD COLUMN promo_id TEXT;
ALTER TABLE order_items ADD COLUMN list_price INTEGER;
