-- Staff and payroll (ST1–ST4): what a commission is counted on, a fee per delivery for couriers,
-- advances and payments in the payroll ledger, faster per-waiter sales.
ALTER TABLE users ADD COLUMN pay_basis TEXT NOT NULL DEFAULT 'own';   -- own | till | kitchen | bar | all
ALTER TABLE users ADD COLUMN per_delivery INTEGER NOT NULL DEFAULT 0; -- kuruş per delivered order
ALTER TABLE payroll ADD COLUMN kind TEXT NOT NULL DEFAULT 'payment';  -- advance | payment
CREATE INDEX IF NOT EXISTS payroll_period ON payroll(period, user_id);
CREATE INDEX IF NOT EXISTS orders_waiter ON orders(waiter_id, closed_at);
CREATE INDEX IF NOT EXISTS payments_user ON payments(user_id, at);
