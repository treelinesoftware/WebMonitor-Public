-- Network tab (includes/netlive.php, includes/speedtest.php).

-- Interface counters of each switch/router, for live port traffic. "live" is
-- the latest sample (the dashboard polls every few seconds while the Network
-- tab is open); "minute" is the baseline cron uses for per-minute history.
CREATE TABLE IF NOT EXISTS iface_samples (
  host_id INT UNSIGNED NOT NULL PRIMARY KEY,
  live_at BIGINT UNSIGNED NOT NULL,          -- ms
  live_counters MEDIUMTEXT NOT NULL,         -- JSON {ifIndex: [in, out]} octets
  rates MEDIUMTEXT NULL,                     -- JSON {ifIndex: [in, out]} bit/s, from the last two live samples
  minute_at BIGINT UNSIGNED NULL,
  minute_counters MEDIUMTEXT NULL,
  error VARCHAR(255) NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Internet speed tests, scheduled (Network tab -> Speed test settings) or run on demand.
CREATE TABLE IF NOT EXISTS speedtests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT UNSIGNED NOT NULL,               -- ms, when it started
  engine VARCHAR(16) NOT NULL,               -- cloudflare, ookla
  manual TINYINT(1) NOT NULL DEFAULT 0,
  down_mbps FLOAT NULL,
  up_mbps FLOAT NULL,
  ping_ms FLOAT NULL,
  jitter_ms FLOAT NULL,
  loss_pct FLOAT NULL,
  bytes_used BIGINT UNSIGNED NULL,
  duration_ms INT UNSIGNED NULL,
  server VARCHAR(160) NULL,
  isp VARCHAR(160) NULL,
  public_ip VARCHAR(45) NULL,
  result_url VARCHAR(255) NULL,
  error VARCHAR(500) NULL,
  KEY ts_idx (ts)
) ENGINE=InnoDB;
