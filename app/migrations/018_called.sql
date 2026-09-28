-- When the kitchen called a plate to the waiter ("Hazır", or "Garsonu tekrar çağır"), kept on the plate (decision 49).
-- The waiter's "food is ready" alert is built from the called, ready plates of a bill, so it follows a plate through a
-- split, a merge or a take-back without having to be kept in step by hand. A plate only tapped as plated on an
-- unfinished ticket is ready but not called, and on no alert until "Hazır".
ALTER TABLE order_items ADD COLUMN called_at INTEGER;
-- plates already carried out were called
UPDATE order_items SET called_at = COALESCE(ready_at, served_at) WHERE status = 'served';
-- a ready plate was called only when an alert still open names it (decision 50). An alert that has ended is history,
-- not a call: a plate taken back to the kitchen and plated again since was never called this time, and waits for
-- "Hazır" like any other plated dish.
UPDATE order_items SET called_at = COALESCE(ready_at, (SELECT MAX(n.at) FROM notifications n,
        json_each(CASE WHEN json_valid(n.body) THEN n.body ELSE '{}' END, '$.lines') j
    WHERE n.kind = 'ready' AND n.done_at IS NULL AND n.deleted = 0 AND j.value = order_items.id))
WHERE status = 'ready' AND deleted = 0 AND EXISTS (SELECT 1 FROM notifications n,
        json_each(CASE WHEN json_valid(n.body) THEN n.body ELSE '{}' END, '$.lines') j
    WHERE n.kind = 'ready' AND n.done_at IS NULL AND n.deleted = 0 AND j.value = order_items.id);
