-- Sofrexa schema v1. Money is stored as integer kuruş (1 TL = 100). Times are unix milliseconds.
-- Replicated tables carry id (UUID v7), updated_at and deleted; append-only tables forbid UPDATE/DELETE.

CREATE TABLE settings (id TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

-- ---------------------------------------------------------------- people and access
CREATE TABLE roles (
  id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, perms TEXT NOT NULL DEFAULT '[]',
  is_system INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE users (
  id TEXT PRIMARY KEY, name TEXT NOT NULL, phone TEXT, email TEXT, role_id TEXT NOT NULL REFERENCES roles(id),
  perms_allow TEXT NOT NULL DEFAULT '[]', perms_deny TEXT NOT NULL DEFAULT '[]',
  pin_hash TEXT, password_hash TEXT, remote_login INTEGER NOT NULL DEFAULT 0, lang TEXT NOT NULL DEFAULT 'tr',
  active INTEGER NOT NULL DEFAULT 1, failed_pins INTEGER NOT NULL DEFAULT 0, locked_until INTEGER NOT NULL DEFAULT 0,
  last_login_at INTEGER, base_salary INTEGER NOT NULL DEFAULT 0, commission_pct REAL NOT NULL DEFAULT 0,
  hired_on TEXT, legacy_id TEXT, sort INTEGER NOT NULL DEFAULT 0, created_at INTEGER,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE UNIQUE INDEX users_email ON users(email) WHERE email IS NOT NULL AND email <> '';

CREATE TABLE audit_log (
  id TEXT PRIMARY KEY, at INTEGER NOT NULL, user_id TEXT, user_name TEXT, device TEXT, action TEXT NOT NULL,
  entity TEXT, entity_id TEXT, summary TEXT, detail TEXT);
CREATE INDEX audit_at ON audit_log(at);
CREATE INDEX audit_action ON audit_log(action, at);
CREATE TRIGGER audit_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;
CREATE TRIGGER audit_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;

CREATE TABLE sessions (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, kind TEXT NOT NULL, created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL, ip TEXT, agent TEXT, lang TEXT);

-- ---------------------------------------------------------------- floor
CREATE TABLE areas (id TEXT PRIMARY KEY, name TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE tables (id TEXT PRIMARY KEY, area_id TEXT NOT NULL REFERENCES areas(id), number TEXT NOT NULL,
  seats INTEGER NOT NULL DEFAULT 4, code TEXT, sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE UNIQUE INDEX tables_code ON tables(code) WHERE code IS NOT NULL;

-- ---------------------------------------------------------------- menu
CREATE TABLE categories (
  id TEXT PRIMARY KEY, slug TEXT, names TEXT NOT NULL DEFAULT '{}', descs TEXT NOT NULL DEFAULT '{}',
  station TEXT NOT NULL DEFAULT 'kitchen', vat_rate REAL NOT NULL DEFAULT 0, image TEXT, section TEXT,
  sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, show_online INTEGER NOT NULL DEFAULT 1,
  legacy_id TEXT, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE items (
  id TEXT PRIMARY KEY, category_id TEXT NOT NULL REFERENCES categories(id), slug TEXT,
  names TEXT NOT NULL DEFAULT '{}', descs TEXT NOT NULL DEFAULT '{}', price INTEGER NOT NULL DEFAULT 0,
  cost INTEGER NOT NULL DEFAULT 0, image TEXT, portion TEXT, allergens TEXT, flags TEXT NOT NULL DEFAULT '{}',
  station TEXT, available INTEGER NOT NULL DEFAULT 1, daily_stock INTEGER, show_online INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, legacy_id TEXT,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX items_category ON items(category_id, sort);

CREATE TABLE modifier_groups (id TEXT PRIMARY KEY, names TEXT NOT NULL DEFAULT '{}', min_sel INTEGER NOT NULL DEFAULT 0,
  max_sel INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE modifiers (id TEXT PRIMARY KEY, group_id TEXT NOT NULL REFERENCES modifier_groups(id),
  names TEXT NOT NULL DEFAULT '{}', price INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE item_modifier_groups (id TEXT PRIMARY KEY, item_id TEXT NOT NULL REFERENCES items(id),
  group_id TEXT NOT NULL REFERENCES modifier_groups(id), sort INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE price_history (id TEXT PRIMARY KEY, item_id TEXT NOT NULL, old_price INTEGER NOT NULL,
  new_price INTEGER NOT NULL, at INTEGER NOT NULL, user_id TEXT);

-- sold count per item per business day (drives the daily stock limit)
CREATE TABLE item_daily (item_id TEXT NOT NULL, day TEXT NOT NULL, sold REAL NOT NULL DEFAULT 0, PRIMARY KEY (item_id, day));

-- ---------------------------------------------------------------- customers and loyalty
CREATE TABLE tiers (id TEXT PRIMARY KEY, name TEXT NOT NULL, threshold INTEGER NOT NULL DEFAULT 0,
  discount_pct REAL NOT NULL DEFAULT 0, earn_pct REAL NOT NULL DEFAULT 0, tone TEXT NOT NULL DEFAULT 'Neutral',
  sort INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE customers (
  id TEXT PRIMARY KEY, name TEXT NOT NULL, phone TEXT, phone_norm TEXT, email TEXT, company TEXT, tax_no TEXT,
  note TEXT, discount_pct REAL NOT NULL DEFAULT 0, tier_id TEXT, tier_manual INTEGER NOT NULL DEFAULT 0, tier_note TEXT,
  loyalty INTEGER NOT NULL DEFAULT 1, credit_enabled INTEGER NOT NULL DEFAULT 0, credit_limit INTEGER NOT NULL DEFAULT 0,
  birthday TEXT, legacy_id TEXT, created_at INTEGER, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX customers_phone ON customers(phone_norm);
CREATE TABLE customer_addresses (id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customers(id), label TEXT,
  address TEXT NOT NULL, note TEXT, is_default INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE loyalty_ledger (id TEXT PRIMARY KEY, customer_id TEXT NOT NULL, points INTEGER NOT NULL, kind TEXT NOT NULL,
  order_id TEXT, note TEXT, at INTEGER NOT NULL, user_id TEXT);
CREATE INDEX loyalty_customer ON loyalty_ledger(customer_id, at);
CREATE TABLE account_ledger (id TEXT PRIMARY KEY, customer_id TEXT NOT NULL, amount INTEGER NOT NULL, kind TEXT NOT NULL,
  order_id TEXT, method TEXT, note TEXT, at INTEGER NOT NULL, user_id TEXT);
CREATE INDEX account_customer ON account_ledger(customer_id, at);

CREATE TABLE online_accounts (id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customers(id),
  email TEXT NOT NULL, password_hash TEXT NOT NULL, verified_at INTEGER, code_hash TEXT, code_purpose TEXT,
  code_expires INTEGER, code_tries INTEGER NOT NULL DEFAULT 0, marketing INTEGER NOT NULL DEFAULT 0, created_at INTEGER,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE UNIQUE INDEX online_accounts_email ON online_accounts(email);

-- ---------------------------------------------------------------- orders, money
CREATE TABLE shifts (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, device TEXT, opened_at INTEGER NOT NULL,
  closed_at INTEGER, opening_cash INTEGER NOT NULL DEFAULT 0, counted TEXT, expected TEXT, z_no INTEGER, note TEXT,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE fx_rates (id TEXT PRIMARY KEY, currency TEXT NOT NULL, rate REAL NOT NULL, at INTEGER NOT NULL, user_id TEXT);

CREATE TABLE qr_sessions (id TEXT PRIMARY KEY, table_id TEXT NOT NULL, token TEXT NOT NULL, opened_at INTEGER NOT NULL,
  approved_at INTEGER, approved_by TEXT, closed_at INTEGER,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

CREATE TABLE orders (
  id TEXT PRIMARY KEY, no INTEGER NOT NULL, day TEXT NOT NULL,
  channel TEXT NOT NULL,          -- table | qr | takeaway | delivery | online
  status TEXT NOT NULL,           -- pending (awaiting approval) | open | billed | paid | void
  table_id TEXT, qr_session_id TEXT, customer_id TEXT, waiter_id TEXT, guests INTEGER NOT NULL DEFAULT 0,
  opened_at INTEGER NOT NULL, closed_at INTEGER, note TEXT,
  delivery TEXT,                  -- JSON: phone, address, courier_id, eta, pay_hint, cash_given, stage
  subtotal INTEGER NOT NULL DEFAULT 0, discount INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0,
  paid INTEGER NOT NULL DEFAULT 0, shift_id TEXT, printed_bill INTEGER NOT NULL DEFAULT 0, legacy_id TEXT,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX orders_status ON orders(status, day);
CREATE INDEX orders_table ON orders(table_id, status);
CREATE INDEX orders_day ON orders(day);

CREATE TABLE order_items (
  id TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES orders(id), item_id TEXT, name TEXT NOT NULL,
  qty REAL NOT NULL DEFAULT 1, unit_price INTEGER NOT NULL, mods TEXT NOT NULL DEFAULT '[]', mods_price INTEGER NOT NULL DEFAULT 0,
  note TEXT, station TEXT NOT NULL DEFAULT 'kitchen', round INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'new',   -- new | sent | ready | served | void
  vat_rate REAL NOT NULL DEFAULT 0, cost INTEGER NOT NULL DEFAULT 0,
  created_by TEXT, created_at INTEGER NOT NULL, sent_at INTEGER, ready_at INTEGER, served_at INTEGER,
  void_reason TEXT, void_by TEXT, void_at INTEGER,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX order_items_order ON order_items(order_id);
CREATE INDEX order_items_status ON order_items(status, station);

CREATE TABLE order_discounts (id TEXT PRIMARY KEY, order_id TEXT NOT NULL, kind TEXT NOT NULL, value REAL NOT NULL,
  amount INTEGER NOT NULL, reason TEXT, user_id TEXT, at INTEGER NOT NULL);

CREATE TABLE payments (id TEXT PRIMARY KEY, order_id TEXT, shift_id TEXT, method TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'TRY', amount_fx REAL NOT NULL DEFAULT 0, rate REAL NOT NULL DEFAULT 1,
  amount INTEGER NOT NULL, change_given INTEGER NOT NULL DEFAULT 0, customer_id TEXT, at INTEGER NOT NULL, user_id TEXT);
CREATE INDEX payments_order ON payments(order_id);
CREATE INDEX payments_at ON payments(at);

CREATE TABLE cash_moves (id TEXT PRIMARY KEY, shift_id TEXT, kind TEXT NOT NULL, currency TEXT NOT NULL DEFAULT 'TRY',
  amount_fx REAL NOT NULL DEFAULT 0, amount INTEGER NOT NULL DEFAULT 0, reason TEXT, note TEXT, photo TEXT,
  reverses TEXT, user_id TEXT, at INTEGER NOT NULL);
CREATE INDEX cash_moves_shift ON cash_moves(shift_id, at);
CREATE TRIGGER cash_moves_no_update BEFORE UPDATE ON cash_moves BEGIN SELECT RAISE(ABORT, 'cash_moves is append-only'); END;
CREATE TRIGGER cash_moves_no_delete BEFORE DELETE ON cash_moves BEGIN SELECT RAISE(ABORT, 'cash_moves is append-only'); END;

CREATE TABLE notifications (id TEXT PRIMARY KEY, user_id TEXT, role TEXT, kind TEXT NOT NULL, title TEXT, body TEXT,
  ref_type TEXT, ref_id TEXT, at INTEGER NOT NULL, read_at INTEGER);
CREATE INDEX notifications_user ON notifications(user_id, read_at);

-- ---------------------------------------------------------------- stock
CREATE TABLE suppliers (id TEXT PRIMARY KEY, name TEXT NOT NULL, phone TEXT, note TEXT, legacy_id TEXT,
  updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stock_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, unit TEXT NOT NULL DEFAULT 'kg', category TEXT,
  kind TEXT NOT NULL DEFAULT 'raw',     -- raw | semi (semi-finished, has its own recipe)
  min_qty REAL NOT NULL DEFAULT 0, avg_cost REAL NOT NULL DEFAULT 0, supplier_id TEXT, legacy_id TEXT,
  active INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE recipes (id TEXT PRIMARY KEY, parent_kind TEXT NOT NULL, parent_id TEXT NOT NULL,
  stock_item_id TEXT NOT NULL REFERENCES stock_items(id), qty REAL NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0,
  deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX recipes_parent ON recipes(parent_kind, parent_id);
CREATE TABLE stock_docs (id TEXT PRIMARY KEY, kind TEXT NOT NULL, supplier_id TEXT, doc_no TEXT, day TEXT NOT NULL,
  total INTEGER NOT NULL DEFAULT 0, pay_method TEXT, note TEXT, user_id TEXT, at INTEGER NOT NULL, legacy_id TEXT);
CREATE TABLE stock_moves (id TEXT PRIMARY KEY, doc_id TEXT, stock_item_id TEXT NOT NULL, qty REAL NOT NULL,
  unit_cost REAL NOT NULL DEFAULT 0, reason TEXT NOT NULL, order_item_id TEXT, at INTEGER NOT NULL, user_id TEXT);
CREATE INDEX stock_moves_item ON stock_moves(stock_item_id, at);
CREATE TRIGGER stock_moves_no_update BEFORE UPDATE ON stock_moves BEGIN SELECT RAISE(ABORT, 'stock_moves is append-only'); END;
CREATE TRIGGER stock_moves_no_delete BEFORE DELETE ON stock_moves BEGIN SELECT RAISE(ABORT, 'stock_moves is append-only'); END;
CREATE TABLE stock_count_lines (id TEXT PRIMARY KEY, doc_id TEXT NOT NULL, stock_item_id TEXT NOT NULL,
  expected REAL NOT NULL, counted REAL NOT NULL);

-- ---------------------------------------------------------------- staff
CREATE TABLE time_entries (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, kind TEXT NOT NULL, at INTEGER NOT NULL, device TEXT);
CREATE INDEX time_entries_user ON time_entries(user_id, at);
CREATE TABLE payroll (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, period TEXT NOT NULL, base INTEGER NOT NULL DEFAULT 0,
  commission INTEGER NOT NULL DEFAULT 0, bonus INTEGER NOT NULL DEFAULT 0, deduction INTEGER NOT NULL DEFAULT 0,
  total INTEGER NOT NULL, method TEXT, note TEXT, at INTEGER NOT NULL, user_by TEXT);

-- ---------------------------------------------------------------- finance
CREATE TABLE finance_entries (id TEXT PRIMARY KEY, kind TEXT NOT NULL, category TEXT NOT NULL, description TEXT,
  day TEXT NOT NULL, amount INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'TRY', amount_fx REAL NOT NULL DEFAULT 0,
  method TEXT NOT NULL DEFAULT 'bank', source TEXT NOT NULL DEFAULT 'manual', ref_id TEXT, receipt TEXT,
  recurring_id TEXT, reverses TEXT, user_id TEXT, at INTEGER NOT NULL);
CREATE INDEX finance_day ON finance_entries(day);
CREATE TABLE recurring_expenses (id TEXT PRIMARY KEY, category TEXT NOT NULL, description TEXT, amount INTEGER NOT NULL,
  day_of_month INTEGER NOT NULL DEFAULT 1, method TEXT NOT NULL DEFAULT 'bank', active INTEGER NOT NULL DEFAULT 1,
  last_run TEXT, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);

-- ---------------------------------------------------------------- infrastructure
CREATE TABLE print_jobs (id TEXT PRIMARY KEY, printer TEXT NOT NULL, kind TEXT NOT NULL, ref_id TEXT, payload TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0, error TEXT, at INTEGER NOT NULL, done_at INTEGER);
CREATE INDEX print_jobs_status ON print_jobs(status, at);

CREATE TABLE sync_outbox (seq INTEGER PRIMARY KEY AUTOINCREMENT, tbl TEXT NOT NULL, row_id TEXT NOT NULL, at INTEGER NOT NULL);
CREATE TABLE sync_state (key TEXT PRIMARY KEY, value TEXT);

CREATE TABLE rate_limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL);
