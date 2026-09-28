-- Stock details from S1-S7.
-- stock_items.location: where it is kept (Soğuk oda, Kuru depo, Bar) for counting room by room; vat_rate for purchase invoices.
ALTER TABLE stock_items ADD COLUMN location TEXT;
ALTER TABLE stock_items ADD COLUMN vat_rate REAL NOT NULL DEFAULT 10;
-- recipes.waste_pct: trimming loss shown on the recipe (FİRE %).
ALTER TABLE recipes ADD COLUMN waste_pct REAL NOT NULL DEFAULT 0;
-- stock_docs.vat: VAT of a purchase invoice (total is VAT included).
ALTER TABLE stock_docs ADD COLUMN vat INTEGER NOT NULL DEFAULT 0;
