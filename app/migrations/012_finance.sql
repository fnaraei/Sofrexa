-- Reports and finance (R1–R8, FI1–FI4): what a cash move belongs to (a purchase, a salary, an expense entry)
-- so the finance views never count it twice; faster period queries.
ALTER TABLE cash_moves ADD COLUMN ref TEXT;
CREATE INDEX IF NOT EXISTS orders_closed ON orders(status, closed_at);
CREATE INDEX IF NOT EXISTS cash_moves_at ON cash_moves(at);
CREATE INDEX IF NOT EXISTS payroll_at ON payroll(at);
CREATE INDEX IF NOT EXISTS stock_docs_day ON stock_docs(kind, day);
CREATE INDEX IF NOT EXISTS order_items_void ON order_items(status, void_at);
