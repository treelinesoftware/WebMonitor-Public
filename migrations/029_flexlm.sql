-- FlexLM licence servers (Licences tab). Each row is one lmgrd, checked from
-- this server with "lmutil lmstat -a -c port@host".
CREATE TABLE IF NOT EXISTS flexlm_servers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  target VARCHAR(500) NOT NULL,            -- port@host, or a triad: port@a,port@b,port@c
  interval_sec INT UNSIGNED NOT NULL DEFAULT 300,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  alert TINYINT(1) NOT NULL DEFAULT 1,     -- alert when it's down, full or expiring
  down_after_min INT UNSIGNED NOT NULL DEFAULT 10,   -- down this long before an alert
  full_pct TINYINT UNSIGNED NOT NULL DEFAULT 100,    -- warn when a feature is this % in use (0 = off)
  expiry_warn_days INT UNSIGNED NOT NULL DEFAULT 30, -- warn this many days before a feature expires (0 = off)
  notes VARCHAR(500) NULL,                 -- e.g. who to call about renewals
  status VARCHAR(8) NOT NULL DEFAULT 'pending',      -- up, warn, down, pending
  message VARCHAR(500) NULL,
  vendors VARCHAR(500) NULL,               -- JSON: [{name, up, version}]
  lmgrd_version VARCHAR(40) NULL,
  last_check_at BIGINT UNSIGNED NULL,
  last_ms INT UNSIGNED NULL,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  changed_at BIGINT UNSIGNED NULL,         -- when status last changed
  check_requested TINYINT(1) NOT NULL DEFAULT 0,     -- "Check now": cron runs it within a minute
  last_output MEDIUMTEXT NULL,             -- the last lmstat output, for troubleshooting
  created_by INT UNSIGNED NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  KEY due_idx (enabled, last_check_at)
) ENGINE=InnoDB;

-- Features as of the last successful check.
CREATE TABLE IF NOT EXISTS flexlm_features (
  server_id INT UNSIGNED NOT NULL,
  feature VARCHAR(120) NOT NULL,
  vendor VARCHAR(60) NULL,
  version VARCHAR(40) NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  used INT UNSIGNED NOT NULL DEFAULT 0,
  reserved INT UNSIGNED NOT NULL DEFAULT 0,
  expires DATE NULL,                       -- NULL = permanent or not shown
  updated_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (server_id, feature),
  CONSTRAINT flexlm_features_server FOREIGN KEY (server_id) REFERENCES flexlm_servers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Licences in use at each check (kept 14 days) ...
CREATE TABLE IF NOT EXISTS flexlm_usage (
  server_id INT UNSIGNED NOT NULL,
  feature VARCHAR(120) NOT NULL,
  ts BIGINT UNSIGNED NOT NULL,
  used INT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  PRIMARY KEY (server_id, feature, ts),
  KEY ts_idx (ts),
  CONSTRAINT flexlm_usage_server FOREIGN KEY (server_id) REFERENCES flexlm_servers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ... and the peak per hour (kept 400 days), for renewal planning.
CREATE TABLE IF NOT EXISTS flexlm_usage_hourly (
  server_id INT UNSIGNED NOT NULL,
  feature VARCHAR(120) NOT NULL,
  hour BIGINT UNSIGNED NOT NULL,           -- ms, start of the hour
  peak INT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  PRIMARY KEY (server_id, feature, hour),
  KEY hour_idx (hour),
  CONSTRAINT flexlm_usage_hourly_server FOREIGN KEY (server_id) REFERENCES flexlm_servers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Checkouts: one row per user/host/feature session, from the first check that
-- saw it until the last (ended_at NULL = still checked out). Kept 180 days.
CREATE TABLE IF NOT EXISTS flexlm_checkouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  server_id INT UNSIGNED NOT NULL,
  feature VARCHAR(120) NOT NULL,
  username VARCHAR(120) NOT NULL,
  host VARCHAR(190) NOT NULL,
  display VARCHAR(190) NULL,
  version VARCHAR(40) NULL,
  licenses INT UNSIGNED NOT NULL DEFAULT 1,
  start_text VARCHAR(40) NOT NULL,         -- lmstat's own start time ("Mon 11/18 9:15"), part of the identity
  started_at BIGINT UNSIGNED NOT NULL,     -- best guess from start_text, else first seen
  last_seen BIGINT UNSIGNED NOT NULL,
  ended_at BIGINT UNSIGNED NULL,
  KEY open_idx (server_id, ended_at),
  KEY user_idx (username),
  KEY started_idx (started_at),
  CONSTRAINT flexlm_checkouts_server FOREIGN KEY (server_id) REFERENCES flexlm_servers (id) ON DELETE CASCADE
) ENGINE=InnoDB;
