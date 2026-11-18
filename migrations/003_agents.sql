-- Installed agents, one per host. Agents authenticate with a long random
-- token; only its SHA-256 hash is stored.
CREATE TABLE IF NOT EXISTS agents (
  host_id INT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  enrolled_at BIGINT UNSIGNED NOT NULL,
  last_seen_at BIGINT UNSIGNED NULL,
  last_ip VARCHAR(45) NULL,
  agent_version VARCHAR(20) NULL,
  hostname VARCHAR(255) NULL,
  -- Latest reports (JSON) and when they arrived.
  metrics MEDIUMTEXT NULL,
  metrics_at BIGINT UNSIGNED NULL,
  inventory MEDIUMTEXT NULL,
  inventory_at BIGINT UNSIGNED NULL,
  -- Copied out of the inventory so the host list can show them cheaply.
  os_pretty VARCHAR(120) NULL,
  updates_total INT UNSIGNED NULL,
  updates_security INT UNSIGNED NULL,
  reboot_required TINYINT(1) NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One-time install tokens shown in the dashboard. Exchanged by the
-- installer for the agent's permanent token, so a token left in shell
-- history is useless afterwards.
CREATE TABLE IF NOT EXISTS agent_enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  used_at BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Per-minute history for charts and alerting (kept for 7 days).
CREATE TABLE IF NOT EXISTS agent_metrics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  ts BIGINT UNSIGNED NOT NULL,
  cpu_pct DECIMAL(5,1) NULL,
  mem_pct DECIMAL(5,1) NULL,
  load1 DECIMAL(8,2) NULL,
  disk_max_pct DECIMAL(5,1) NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
  INDEX idx_host_ts (host_id, ts)
) ENGINE=InnoDB;
