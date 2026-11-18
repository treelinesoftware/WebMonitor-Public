-- Bandwidth alerts: alerts can belong to a device that has no card (subject
-- holds its IP), and per-host flow minutes count internet traffic separately.
ALTER TABLE alerts MODIFY host_id INT UNSIGNED NULL;
ALTER TABLE flow_hosts ADD COLUMN IF NOT EXISTS inet_in BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE flow_hosts ADD COLUMN IF NOT EXISTS inet_out BIGINT UNSIGNED NOT NULL DEFAULT 0;
