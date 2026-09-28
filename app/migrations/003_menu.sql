-- Menu and floor details from the M1–M8 designs.
-- items: separate visibility on the website menu, the QR table menu and online ordering; preparation time.
ALTER TABLE items ADD COLUMN show_web INTEGER NOT NULL DEFAULT 1;
ALTER TABLE items ADD COLUMN show_qr INTEGER NOT NULL DEFAULT 1;
ALTER TABLE items ADD COLUMN prep_minutes INTEGER;
-- areas: names in four languages (subtitle "Garden · باغ") and the smoking flag ("Sigara" badge).
ALTER TABLE areas ADD COLUMN names TEXT NOT NULL DEFAULT '{}';
ALTER TABLE areas ADD COLUMN smoking INTEGER NOT NULL DEFAULT 0;
-- option groups: kind single (radio) / multi (e.g. "Çıkar"), applied per item through item_modifier_groups.
ALTER TABLE modifier_groups ADD COLUMN kind TEXT NOT NULL DEFAULT 'single';
CREATE INDEX IF NOT EXISTS item_modifier_groups_item ON item_modifier_groups(item_id);
CREATE INDEX IF NOT EXISTS modifiers_group ON modifiers(group_id, sort);
