-- One-time links for setting or resetting a staff password (remote sign-in). Replicated so a link
-- created on the till PC also works on the web copy. Only a hash of the token is stored.
CREATE TABLE password_resets (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, token_hash TEXT NOT NULL, expires_at INTEGER NOT NULL,
  used_at INTEGER, created_by TEXT, updated_at INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX password_resets_token ON password_resets(token_hash);
