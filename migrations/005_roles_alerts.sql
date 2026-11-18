-- Roles: admins manage everything; viewers can look (and ping/refresh).
-- Accounts that existed before roles were all admins.
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(10) NOT NULL DEFAULT 'viewer' AFTER password_hash;
-- Migrations run once, so this only touches pre-existing accounts.
UPDATE users SET role = 'admin';

-- Key/value settings (JSON values), e.g. alert thresholds.
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

-- Where notifications go. config is JSON; secrets in it (SMTP password)
-- are never sent back to the browser.
CREATE TABLE IF NOT EXISTS notification_channels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(16) NOT NULL,
  name VARCHAR(60) NOT NULL,
  config TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Problems (open until resolved) and one-off events (crash/reboot, created
-- already resolved). subject distinguishes e.g. disks on the same host.
CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  type VARCHAR(24) NOT NULL,
  subject VARCHAR(190) NOT NULL DEFAULT '',
  severity VARCHAR(10) NOT NULL,
  message VARCHAR(500) NOT NULL,
  started_at BIGINT UNSIGNED NOT NULL,
  resolved_at BIGINT UNSIGNED NULL,
  notified_at BIGINT UNSIGNED NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
  INDEX idx_open (resolved_at, host_id, type),
  INDEX idx_started (started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NULL,
  alert_id BIGINT UNSIGNED NULL,
  ok TINYINT(1) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  INDEX idx_ts (ts)
) ENGINE=InnoDB;

-- Last known boot time per agent, to spot reboots and crashes.
ALTER TABLE agents ADD COLUMN IF NOT EXISTS boot_time BIGINT UNSIGNED NULL;
