-- Traffic from NetFlow/IPFIX (cli/flow-collector.py -> includes/flows.php),
-- per minute. Per-host traffic also goes into the history graphs (flow_in/flow_out).
CREATE TABLE IF NOT EXISTS flow_totals (
  ts INT UNSIGNED NOT NULL PRIMARY KEY,
  down_bytes BIGINT UNSIGNED NOT NULL,
  up_bytes BIGINT UNSIGNED NOT NULL,
  internal_bytes BIGINT UNSIGNED NOT NULL,
  flows INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flow_hosts (
  ts INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  bytes_in BIGINT UNSIGNED NOT NULL,
  bytes_out BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (ts, ip),
  INDEX idx_ip_ts (ip, ts)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flow_conv (
  ts INT UNSIGNED NOT NULL,
  local_ip VARCHAR(45) NOT NULL,
  remote_ip VARCHAR(45) NOT NULL,
  proto TINYINT UNSIGNED NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  bytes_in BIGINT UNSIGNED NOT NULL,
  bytes_out BIGINT UNSIGNED NOT NULL,
  INDEX idx_ts (ts),
  INDEX idx_local_ts (local_ip, ts)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flow_services (
  ts INT UNSIGNED NOT NULL,
  proto TINYINT UNSIGNED NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  bytes_in BIGINT UNSIGNED NOT NULL,
  bytes_out BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (ts, proto, port)
) ENGINE=InnoDB;
