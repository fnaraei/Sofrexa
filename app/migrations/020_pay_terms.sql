-- What a person is paid, month by month (audit 8, O11; decision 52). A change of salary, commission, its basis or the
-- per-delivery fee holds from the month it is made in: a month already worked keeps the terms it was worked under, so
-- raising a salary in September does not raise what August earned. The history is kept by the database itself, so no
-- way of changing a profile — the staff page, an import, a row arriving from the other copy — can skip it (each copy
-- keeps its own; the profiles themselves are what is replicated).
CREATE TABLE pay_terms (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, from_month TEXT NOT NULL, base_salary INTEGER NOT NULL DEFAULT 0,
  commission_pct REAL NOT NULL DEFAULT 0, pay_basis TEXT, per_delivery INTEGER NOT NULL DEFAULT 0, at INTEGER NOT NULL);
CREATE INDEX pay_terms_user ON pay_terms(user_id, from_month);
-- the terms known until now hold for every month before the first change
INSERT INTO pay_terms (id, user_id, from_month, base_salary, commission_pct, pay_basis, per_delivery, at)
SELECT 'terms0-' || id, id, '0000-00', base_salary, commission_pct, pay_basis, per_delivery, 0 FROM users
WHERE deleted = 0 AND (base_salary > 0 OR commission_pct > 0 OR per_delivery > 0);

CREATE TRIGGER pay_terms_new AFTER INSERT ON users
WHEN NEW.base_salary > 0 OR NEW.commission_pct > 0 OR NEW.per_delivery > 0
BEGIN
  INSERT INTO pay_terms (id, user_id, from_month, base_salary, commission_pct, pay_basis, per_delivery, at)
  VALUES (lower(hex(randomblob(16))), NEW.id, strftime('%Y-%m', 'now', 'localtime'), NEW.base_salary, NEW.commission_pct, NEW.pay_basis, NEW.per_delivery,
    CAST(strftime('%s', 'now') AS INTEGER) * 1000);
END;

CREATE TRIGGER pay_terms_changed AFTER UPDATE OF base_salary, commission_pct, pay_basis, per_delivery ON users
WHEN NEW.base_salary IS NOT OLD.base_salary OR NEW.commission_pct IS NOT OLD.commission_pct OR NEW.pay_basis IS NOT OLD.pay_basis
  OR NEW.per_delivery IS NOT OLD.per_delivery
BEGIN
  INSERT INTO pay_terms (id, user_id, from_month, base_salary, commission_pct, pay_basis, per_delivery, at)
  VALUES (lower(hex(randomblob(16))), NEW.id, strftime('%Y-%m', 'now', 'localtime'), NEW.base_salary, NEW.commission_pct, NEW.pay_basis, NEW.per_delivery,
    CAST(strftime('%s', 'now') AS INTEGER) * 1000);
END;
