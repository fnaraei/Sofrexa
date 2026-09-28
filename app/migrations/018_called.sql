-- When the kitchen called a plate to the waiter ("Hazır", or "Garsonu tekrar çağır"), kept on the plate (decision 49).
-- The waiter's "food is ready" alert is built from the called, ready plates of a bill, so it follows a plate through a
-- split, a merge or a take-back without having to be kept in step by hand. A plate only tapped as plated on an
-- unfinished ticket is ready but not called, and on no alert until "Hazır".
ALTER TABLE order_items ADD COLUMN called_at INTEGER;
-- plates already carried out were called; ready ones on an alert were called when that alert came
UPDATE order_items SET called_at = COALESCE(ready_at, served_at) WHERE status = 'served';
UPDATE order_items SET called_at = COALESCE(ready_at, (SELECT n.at FROM notifications n WHERE n.kind = 'ready' AND n.ref_id = order_items.order_id ORDER BY n.at DESC LIMIT 1))
WHERE status = 'ready' AND id IN (SELECT j.value FROM notifications n, json_each(n.body, '$.lines') j WHERE n.kind = 'ready');
