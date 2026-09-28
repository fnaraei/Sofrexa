-- Customers stage (CU1–CU7, C12): black list flag, faster statement and history lookups.
ALTER TABLE customers ADD COLUMN blacklist INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS account_order ON account_ledger(order_id);
CREATE INDEX IF NOT EXISTS loyalty_order ON loyalty_ledger(order_id);
CREATE INDEX IF NOT EXISTS customers_name ON customers(name);
