-- Reference table for cycleia/usage-log on SQLite (used by the tests).

CREATE TABLE IF NOT EXISTS usage_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL,
  source TEXT NOT NULL,
  app TEXT NOT NULL,
  user_id INTEGER,
  user_role TEXT,
  org_id INTEGER,
  event_type TEXT NOT NULL,
  surface TEXT,
  search_text TEXT,
  params TEXT,
  method TEXT,
  path TEXT,
  route TEXT,
  entity_type TEXT,
  entity_id INTEGER,
  status INTEGER,
  duration_ms INTEGER,
  session_key TEXT,
  device TEXT,
  user_agent TEXT
);
