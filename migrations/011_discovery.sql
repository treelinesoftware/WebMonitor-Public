-- Subnet discovery: scheduled scans that add new devices and remove cards
-- that haven't been seen for a while (see includes/discovery.php).

-- When the host was last seen: a successful check, an ARP entry or a scan.
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS last_seen_at BIGINT UNSIGNED NULL;
-- 'manual' (added by a person) or 'scan' (added by subnet discovery).
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS added_by VARCHAR(16) NOT NULL DEFAULT 'manual';

-- Backfill: the last successful check still on record...
UPDATE hosts h JOIN (SELECT host_id, MAX(ts) t FROM checks WHERE up = 1 GROUP BY host_id) c ON c.host_id = h.id
  SET h.last_seen_at = c.t WHERE h.last_seen_at IS NULL;
-- ...then availability history...
UPDATE hosts h JOIN (SELECT s.host_id, MAX(p.ts) t FROM metric_series s JOIN metric_points p ON p.series_id = s.id
                     WHERE s.metric = 'up' AND p.value > 0 GROUP BY s.host_id) m ON m.host_id = h.id
  SET h.last_seen_at = m.t * 1000 WHERE h.last_seen_at IS NULL;
-- ...and everything else starts its "not seen" clock now, so nothing is removed right away.
UPDATE hosts SET last_seen_at = UNIX_TIMESTAMP() * 1000 WHERE last_seen_at IS NULL;

CREATE TABLE IF NOT EXISTS subnet_scans (
  subnet VARCHAR(18) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  every_min INT UNSIGNED NOT NULL DEFAULT 60,
  auto_add TINYINT(1) NOT NULL DEFAULT 0,
  remove_after_days INT UNSIGNED NULL,
  remove_scan_only TINYINT(1) NOT NULL DEFAULT 1,
  last_scan_at BIGINT UNSIGNED NULL,
  last_result TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS discovery_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT UNSIGNED NOT NULL,
  subnet VARCHAR(18) NOT NULL,
  action VARCHAR(16) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  name VARCHAR(60) NOT NULL,
  detail VARCHAR(255) NULL,
  INDEX idx_ts (ts)
) ENGINE=InnoDB;
