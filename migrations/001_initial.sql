-- Baseline schema. Written with IF NOT EXISTS so it is a no-op on databases
-- created before migrations existed.

CREATE TABLE IF NOT EXISTS hosts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  os VARCHAR(20) NOT NULL DEFAULT 'other',
  device VARCHAR(20) NOT NULL DEFAULT 'server',
  community VARCHAR(64) NOT NULL DEFAULT 'public',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- For deployments made before the community column existed.
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS community VARCHAR(64) NOT NULL DEFAULT 'public';

CREATE TABLE IF NOT EXISTS checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  ts BIGINT UNSIGNED NOT NULL,
  up TINYINT(1) NOT NULL,
  ms INT UNSIGNED NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
  INDEX idx_host_ts (host_id, ts)
) ENGINE=InnoDB;

-- Last server details collected over SNMP (JSON), one row per host.
CREATE TABLE IF NOT EXISTS host_details (
  host_id INT UNSIGNED NOT NULL PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  collected_at BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
