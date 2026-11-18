-- Service monitoring (Services tab). One row per check:
--   http   target = URL; options: method, expect (status codes), keyword, verify_tls, cert_warn_days, warn_ms
--   tcp    target = host:port
--   dns    target = name to resolve; options: server, type (A, AAAA, CNAME, MX), expect
--   agent  target = service name on the host (systemd unit / Windows service), reported by its agent
CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NULL,               -- the card it belongs to (required for agent services)
  kind VARCHAR(8) NOT NULL,
  name VARCHAR(120) NOT NULL,
  target VARCHAR(500) NOT NULL,
  options TEXT NULL,                       -- JSON
  interval_sec INT UNSIGNED NOT NULL DEFAULT 60,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  alert TINYINT(1) NOT NULL DEFAULT 1,     -- send alerts when it's down
  status VARCHAR(8) NOT NULL DEFAULT 'pending',   -- up, warn, down, pending, unknown
  message VARCHAR(500) NULL,               -- why it's down / what was seen
  last_check_at BIGINT UNSIGNED NULL,
  last_ms INT UNSIGNED NULL,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  changed_at BIGINT UNSIGNED NULL,         -- when status last changed
  cert_expires_at BIGINT UNSIGNED NULL,
  detail TEXT NULL,                        -- JSON: agent service state, start time, restarts
  created_by INT UNSIGNED NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  KEY host_idx (host_id),
  KEY due_idx (enabled, kind, last_check_at),
  CONSTRAINT services_host FOREIGN KEY (host_id) REFERENCES hosts (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Check results for the uptime bar and percentage (kept 7 days).
CREATE TABLE IF NOT EXISTS service_results (
  service_id INT UNSIGNED NOT NULL,
  ts BIGINT UNSIGNED NOT NULL,
  up TINYINT(1) NOT NULL,
  ms INT UNSIGNED NULL,
  PRIMARY KEY (service_id, ts),
  KEY ts_idx (ts),
  CONSTRAINT service_results_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB;
