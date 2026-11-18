-- Time series for history graphs. A series is one metric of one host
-- (subject distinguishes e.g. disks or interfaces). Points are per minute
-- (kept 8 days); cron rolls them into hourly avg/min/max (kept 400 days).
CREATE TABLE IF NOT EXISTS metric_series (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  metric VARCHAR(32) NOT NULL,
  subject VARCHAR(120) NOT NULL DEFAULT '',
  unit VARCHAR(8) NOT NULL DEFAULT '',
  UNIQUE KEY uk_series (host_id, metric, subject),
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS metric_points (
  series_id INT UNSIGNED NOT NULL,
  ts INT UNSIGNED NOT NULL,
  value FLOAT NOT NULL,
  PRIMARY KEY (series_id, ts),
  FOREIGN KEY (series_id) REFERENCES metric_series(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS metric_hourly (
  series_id INT UNSIGNED NOT NULL,
  ts INT UNSIGNED NOT NULL,
  avg_value FLOAT NOT NULL,
  min_value FLOAT NOT NULL,
  max_value FLOAT NOT NULL,
  samples SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (series_id, ts),
  FOREIGN KEY (series_id) REFERENCES metric_series(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Carry over the per-minute agent history collected so far.
INSERT IGNORE INTO metric_series (host_id, metric, subject, unit)
  SELECT DISTINCT host_id, 'cpu', '', '%' FROM agent_metrics WHERE cpu_pct IS NOT NULL;
INSERT IGNORE INTO metric_series (host_id, metric, subject, unit)
  SELECT DISTINCT host_id, 'mem', '', '%' FROM agent_metrics WHERE mem_pct IS NOT NULL;
INSERT IGNORE INTO metric_series (host_id, metric, subject, unit)
  SELECT DISTINCT host_id, 'load1', '', '' FROM agent_metrics WHERE load1 IS NOT NULL;
INSERT IGNORE INTO metric_points (series_id, ts, value)
  SELECT s.id, FLOOR(m.ts / 60000) * 60, m.cpu_pct FROM agent_metrics m
  JOIN metric_series s ON s.host_id = m.host_id AND s.metric = 'cpu' AND s.subject = '' WHERE m.cpu_pct IS NOT NULL;
INSERT IGNORE INTO metric_points (series_id, ts, value)
  SELECT s.id, FLOOR(m.ts / 60000) * 60, m.mem_pct FROM agent_metrics m
  JOIN metric_series s ON s.host_id = m.host_id AND s.metric = 'mem' AND s.subject = '' WHERE m.mem_pct IS NOT NULL;
INSERT IGNORE INTO metric_points (series_id, ts, value)
  SELECT s.id, FLOOR(m.ts / 60000) * 60, m.load1 FROM agent_metrics m
  JOIN metric_series s ON s.host_id = m.host_id AND s.metric = 'load1' AND s.subject = '' WHERE m.load1 IS NOT NULL;
