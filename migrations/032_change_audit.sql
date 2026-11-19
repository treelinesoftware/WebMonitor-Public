-- Change management for updates and an audit log.
--
-- change_records: one per change (a scheduled update window or a manual job
-- batch): what was planned (hosts, pending packages), who requested and
-- approved it, and the outcome per host. Kept indefinitely; jobs themselves
-- are pruned after 180 days.
CREATE TABLE IF NOT EXISTS change_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(10) NOT NULL,                 -- schedule | manual
  schedule_id INT UNSIGNED NULL,
  occurrence_key VARCHAR(20) NULL,             -- scheduled: 'Y-m-d H:i' of the window
  schedule_run_id BIGINT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  job_type VARCHAR(24) NOT NULL,
  params VARCHAR(500) NOT NULL DEFAULT '{}',
  planned_at BIGINT UNSIGNED NOT NULL,
  -- planned | pending_approval | approved | rejected | in_progress |
  -- completed | partial | failed | skipped | cancelled
  status VARCHAR(16) NOT NULL,
  host_ids TEXT NOT NULL,
  planned TEXT NOT NULL,                       -- {hostId: {host, packages: [...]}} at creation
  job_ids TEXT NOT NULL,
  outcome MEDIUMTEXT NULL,                     -- {hostId: {host, status, summary, exitCode, output}}
  skipped TEXT NULL,
  verification TEXT NULL,                      -- {hostId: {stillPending: [...]}} from the next inventory
  requested_by INT UNSIGNED NULL,
  requested_name VARCHAR(60) NULL,
  decided_by INT UNSIGNED NULL,
  decided_name VARCHAR(60) NULL,
  decided_at BIGINT UNSIGNED NULL,
  decision_note VARCHAR(500) NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  started_at BIGINT UNSIGNED NULL,
  finished_at BIGINT UNSIGNED NULL,
  verified_at BIGINT UNSIGNED NULL,
  notice_sent_at BIGINT UNSIGNED NULL,
  report_sent_at BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_occurrence (schedule_id, occurrence_key),
  INDEX idx_status (status),
  INDEX idx_planned (planned_at)
) ENGINE=InnoDB;

-- Append-only audit trail. Each row's hash covers its content and the
-- previous row's hash, so editing or deleting a row breaks the chain.
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(60) NULL,                   -- NULL = WebMonitor itself (schedules, cron)
  ip VARCHAR(45) NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(120) NULL,
  summary VARCHAR(500) NOT NULL,
  details TEXT NULL,
  outcome VARCHAR(10) NOT NULL DEFAULT 'ok',   -- ok | denied | failed
  prev_hash CHAR(64) NOT NULL,
  hash CHAR(64) NOT NULL,
  INDEX idx_ts (ts),
  INDEX idx_action (action),
  INDEX idx_user (username)
) ENGINE=InnoDB;
