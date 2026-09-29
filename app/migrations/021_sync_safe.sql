-- Audit 9. Pay terms (decision 52) are dated by when the profile was changed on whichever copy changed it — the row's own
-- updated_at, which replication carries — not by when a copy heard of it: a raise made in August and synced in September
-- is August's on both copies, with the same id, so the two histories are the same history (R03).
DROP TRIGGER IF EXISTS pay_terms_new;
DROP TRIGGER IF EXISTS pay_terms_changed;

CREATE TRIGGER pay_terms_new AFTER INSERT ON users
WHEN NEW.base_salary > 0 OR NEW.commission_pct > 0 OR NEW.per_delivery > 0
BEGIN
  INSERT OR IGNORE INTO pay_terms (id, user_id, from_month, base_salary, commission_pct, pay_basis, per_delivery, at)
  VALUES ('terms-' || NEW.id || '-' || NEW.updated_at, NEW.id, strftime('%Y-%m', NEW.updated_at / 1000, 'unixepoch', 'localtime'),
    NEW.base_salary, NEW.commission_pct, NEW.pay_basis, NEW.per_delivery, NEW.updated_at);
END;

CREATE TRIGGER pay_terms_changed AFTER UPDATE OF base_salary, commission_pct, pay_basis, per_delivery ON users
WHEN NEW.base_salary IS NOT OLD.base_salary OR NEW.commission_pct IS NOT OLD.commission_pct OR NEW.pay_basis IS NOT OLD.pay_basis
  OR NEW.per_delivery IS NOT OLD.per_delivery
BEGIN
  INSERT OR IGNORE INTO pay_terms (id, user_id, from_month, base_salary, commission_pct, pay_basis, per_delivery, at)
  VALUES ('terms-' || NEW.id || '-' || NEW.updated_at, NEW.id, strftime('%Y-%m', NEW.updated_at / 1000, 'unixepoch', 'localtime'),
    NEW.base_salary, NEW.commission_pct, NEW.pay_basis, NEW.per_delivery, NEW.updated_at);
END;

-- A bag known to be handed over (decision 54). Every bill closed before this version counts as handed over: until now
-- paying ended a take-away, so an old paid bag whose dishes were never marked carried out is not waiting at the pass. From
-- here on the board keeps a paid bag until it is handed over, however long that takes (R05) — no time window.
ALTER TABLE orders ADD COLUMN handed_at INTEGER;
UPDATE orders SET handed_at = COALESCE(closed_at, 1) WHERE status NOT IN ('pending', 'open', 'billed');
