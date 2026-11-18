-- Explicit scan requests take priority over automatic scans.
ALTER TABLE device_scans ADD COLUMN IF NOT EXISTS priority_at BIGINT UNSIGNED NULL;
ALTER TABLE subnet_scans ADD COLUMN IF NOT EXISTS requested_at BIGINT UNSIGNED NULL;
ALTER TABLE subnet_scans ADD COLUMN IF NOT EXISTS priority_at BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS scan_queue_order (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  sequence BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO scan_queue_order (id, sequence) VALUES (1, 0);

-- Active entries are valid only while their named lock is held by the recorded connection.
CREATE TABLE IF NOT EXISTS scan_activity (
  lock_name VARCHAR(64) NOT NULL PRIMARY KEY,
  connection_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,
  target VARCHAR(255) NOT NULL,
  started_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
