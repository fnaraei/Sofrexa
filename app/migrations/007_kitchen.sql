-- Kitchen screens (K1-K3): when the cook pressed "Başla" on a ticket (New -> Cooking).
ALTER TABLE order_items ADD COLUMN started_at INTEGER;
