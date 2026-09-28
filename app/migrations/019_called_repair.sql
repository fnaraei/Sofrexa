-- Repairs a database that ran 018 before it was corrected (decision 50): that first version took a plate named on any
-- ready alert ever made — one ended long ago too — as called, so a plate taken back and plated again could ring the
-- waiter without a new "Hazır". What the waiter was really told is the alerts still open: a ready plate is called when
-- one of them names it, and not otherwise. (Since 018 an open alert always names exactly the called ready plates of its
-- bill, so on a database that ran the corrected 018 this changes nothing.)
UPDATE order_items SET called_at = NULL
WHERE status = 'ready' AND called_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM notifications n,
        json_each(CASE WHEN json_valid(n.body) THEN n.body ELSE '{}' END, '$.lines') j
    WHERE n.kind = 'ready' AND n.done_at IS NULL AND n.deleted = 0 AND j.value = order_items.id);
UPDATE order_items SET called_at = COALESCE(ready_at, (SELECT MAX(n.at) FROM notifications n,
        json_each(CASE WHEN json_valid(n.body) THEN n.body ELSE '{}' END, '$.lines') j
    WHERE n.kind = 'ready' AND n.done_at IS NULL AND n.deleted = 0 AND j.value = order_items.id))
WHERE status = 'ready' AND deleted = 0 AND called_at IS NULL AND EXISTS (SELECT 1 FROM notifications n,
        json_each(CASE WHEN json_valid(n.body) THEN n.body ELSE '{}' END, '$.lines') j
    WHERE n.kind = 'ready' AND n.done_at IS NULL AND n.deleted = 0 AND j.value = order_items.id);
