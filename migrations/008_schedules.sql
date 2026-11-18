-- Recurring update windows, e.g. "security updates every Sunday 03:00".
-- days: bitmask Mon=1, Tue=2, Wed=4, Thu=8, Fri=16, Sat=32, Sun=64.
-- at_time is server-local time (HH:MM). targets: all | linux | windows | hosts.
CREATE TABLE IF NOT EXISTS update_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  job_type VARCHAR(24) NOT NULL,
  params VARCHAR(200) NOT NULL DEFAULT '{}',
  days TINYINT UNSIGNED NOT NULL,
  at_time CHAR(5) NOT NULL,
  targets VARCHAR(10) NOT NULL DEFAULT 'hosts',
  host_ids TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  last_run_key VARCHAR(20) NULL
) ENGINE=InnoDB;

-- One row per time a schedule fired: which jobs it queued, what it skipped,
-- and when its summary notification went out.
CREATE TABLE IF NOT EXISTS schedule_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT UNSIGNED NOT NULL,
  started_at BIGINT UNSIGNED NOT NULL,
  manual TINYINT(1) NOT NULL DEFAULT 0,
  job_ids TEXT NOT NULL,
  skipped TEXT NOT NULL,
  finished_at BIGINT UNSIGNED NULL,
  summary VARCHAR(500) NULL,
  FOREIGN KEY (schedule_id) REFERENCES update_schedules(id) ON DELETE CASCADE,
  INDEX idx_sched (schedule_id, started_at)
) ENGINE=InnoDB;

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS schedule_id INT UNSIGNED NULL AFTER created_by;
