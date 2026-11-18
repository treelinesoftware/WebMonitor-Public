-- Switch/router configuration backups (see includes/configbackup.php).

-- How to log in to each device and when to back it up. Secrets are
-- encrypted (AES-256-GCM, key derived from the database password).
CREATE TABLE IF NOT EXISTS config_backup_settings (
  host_id INT UNSIGNED NOT NULL PRIMARY KEY,
  vendor VARCHAR(32) NOT NULL,
  port INT UNSIGNED NOT NULL DEFAULT 22,
  username VARCHAR(64) NOT NULL,
  auth VARCHAR(16) NOT NULL DEFAULT 'key',
  secret_enc TEXT NULL,
  enable_enc TEXT NULL,
  command VARCHAR(255) NULL,
  schedule VARCHAR(16) NOT NULL DEFAULT 'off',
  requested_at BIGINT UNSIGNED NULL,
  last_attempt_at BIGINT UNSIGNED NULL,
  last_ok_at BIGINT UNSIGNED NULL,
  last_error VARCHAR(500) NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every download is kept as a new version.
CREATE TABLE IF NOT EXISTS switch_configs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  fetched_at BIGINT UNSIGNED NOT NULL,
  source VARCHAR(16) NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  changed TINYINT(1) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
  INDEX idx_host_time (host_id, fetched_at)
) ENGINE=InnoDB;
