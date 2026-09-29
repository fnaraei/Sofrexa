-- Audit 10. Two corrections of 021, which guessed where the rows could say.
--
-- Pay terms: a copy no longer builds its history from the profile rows it receives — two raises before a sync, or a name
-- changed after a raise, lost the step in between (the profile row carries only its last state). The change is kept
-- where it is made, as a replicated row of its own (Staff::recordTerms); the triggers go.
DROP TRIGGER IF EXISTS pay_terms_new;
DROP TRIGGER IF EXISTS pay_terms_changed;

-- A bag is handed over when it is: delivered at the door, or — a take-away or pickup — every dish carried out. 021 took
-- every closed bill as handed over, and a paid bag still in the kitchen at the upgrade fell off the board. Put back:
-- a paid bag whose rows say it is still waiting is waiting.
UPDATE orders SET handed_at = NULL
WHERE handed_at IS NOT NULL AND status = 'paid' AND channel IN ('delivery', 'takeaway', 'online')
  AND COALESCE(CASE WHEN json_valid(delivery) THEN json_extract(delivery, '$.stage') END, '') <> 'done'
  AND (channel = 'delivery' OR COALESCE(CASE WHEN json_valid(delivery) THEN json_extract(delivery, '$.type') END, '') = 'delivery'
    OR EXISTS (SELECT 1 FROM order_items l WHERE l.order_id = orders.id AND l.deleted = 0 AND l.status IN ('new', 'sent', 'ready')));
