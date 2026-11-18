-- Actions run by agents (pull-based: agents ask for work). Only a fixed set
-- of job types exists; the agent implements each one itself, so the
-- server can never make an agent run arbitrary commands.
CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host_id INT UNSIGNED NOT NULL,
  type VARCHAR(24) NOT NULL,
  params VARCHAR(500) NOT NULL DEFAULT '{}',
  -- queued -> running -> succeeded | failed ; queued -> cancelled | expired
  status VARCHAR(12) NOT NULL DEFAULT 'queued',
  created_by INT UNSIGNED NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  started_at BIGINT UNSIGNED NULL,
  updated_at BIGINT UNSIGNED NULL,
  finished_at BIGINT UNSIGNED NULL,
  exit_code INT NULL,
  summary VARCHAR(500) NULL,
  output MEDIUMTEXT NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
  INDEX idx_host_status (host_id, status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;
