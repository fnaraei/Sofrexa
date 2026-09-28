-- Online ordering (stage 12).
-- online_accounts: when the terms were accepted, when the marketing opt-in was given, when the last code was sent
-- (60 s before another) and the last sign-in.
ALTER TABLE online_accounts ADD COLUMN terms_at INTEGER;
ALTER TABLE online_accounts ADD COLUMN marketing_at INTEGER;
ALTER TABLE online_accounts ADD COLUMN code_sent_at INTEGER;
ALTER TABLE online_accounts ADD COLUMN login_at INTEGER;
-- the customer's language for e-mails sent later (codes, order progress)
ALTER TABLE online_accounts ADD COLUMN lang TEXT;
CREATE INDEX IF NOT EXISTS online_accounts_customer ON online_accounts(customer_id);
CREATE INDEX IF NOT EXISTS customer_addresses_customer ON customer_addresses(customer_id);
-- e-mails already sent about an online order's progress (kept on the copy that sends them; not replicated).
CREATE TABLE IF NOT EXISTS online_mail (order_id TEXT NOT NULL, stage TEXT NOT NULL, at INTEGER NOT NULL, PRIMARY KEY (order_id, stage));
