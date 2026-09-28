-- M2 shows a VAT rate on the item; empty means the category's rate.
ALTER TABLE items ADD COLUMN vat_rate REAL;
