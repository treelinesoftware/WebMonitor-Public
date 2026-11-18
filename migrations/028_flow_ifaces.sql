-- NetFlow per exporter interface (cli/flow-collector.py -> includes/flows.php), for
-- the Network tab's per-port traffic breakdown (includes/portflows.php).
--   dir 'i': the flow came in on this interface (the flow record's input ifIndex)
--   dir 'o': it went out of it (output ifIndex, when the exporter sends one)
CREATE TABLE IF NOT EXISTS flow_ifaces (
  ts INT UNSIGNED NOT NULL,
  exporter VARCHAR(45) NOT NULL,
  if_index INT UNSIGNED NOT NULL,
  dir CHAR(1) NOT NULL,
  bytes BIGINT UNSIGNED NOT NULL,
  packets BIGINT UNSIGNED NOT NULL,
  flows INT UNSIGNED NOT NULL,
  PRIMARY KEY (exporter, if_index, dir, ts),
  KEY ts_idx (ts)
) ENGINE=InnoDB;

-- Top conversations per exporter and input interface, per minute (kept 3 days).
CREATE TABLE IF NOT EXISTS flow_iface_conv (
  ts INT UNSIGNED NOT NULL,
  exporter VARCHAR(45) NOT NULL,
  if_index INT UNSIGNED NOT NULL,
  src_ip VARCHAR(45) NOT NULL,
  dst_ip VARCHAR(45) NOT NULL,
  proto TINYINT UNSIGNED NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  bytes BIGINT UNSIGNED NOT NULL,
  KEY iface_ts (exporter, if_index, ts),
  KEY ts_idx (ts)
) ENGINE=InnoDB;

-- Port matching looks flows up by the far end too (internal traffic).
ALTER TABLE flow_conv ADD INDEX IF NOT EXISTS idx_remote_ts (remote_ip, ts);
