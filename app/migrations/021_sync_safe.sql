-- Audit 9. (The pay-terms triggers below are replaced by 022: the history is now kept where the change is made.)
-- Pay terms (decision 52) are dated by when the profile was changed on whichever copy changed it — the row's own
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

-- A bag known to be handed over (decision 54): the board keeps a paid bag until it is handed over, however long that
-- takes (R05) — no time window. Marked from what the rows say (as corrected by 022): delivered at the door, or every dish
-- of a take-away or pickup carried out; a bill of any other kind that has ended is not on the board at all.
ALTER TABLE orders ADD COLUMN handed_at INTEGER;
UPDATE orders SET handed_at = COALESCE(closed_at, 1)
WHERE status NOT IN ('pending', 'open', 'billed')
  AND NOT (status = 'paid' AND channel IN ('delivery', 'takeaway', 'online')
    AND COALESCE(CASE WHEN json_valid(delivery) THEN json_extract(delivery, '$.stage') END, '') <> 'done'
    AND (channel = 'delivery' OR COALESCE(CASE WHEN json_valid(delivery) THEN json_extract(delivery, '$.type') END, '') = 'delivery'
      OR EXISTS (SELECT 1 FROM order_items l WHERE l.order_id = orders.id AND l.deleted = 0 AND l.status IN ('new', 'sent', 'ready'))));
