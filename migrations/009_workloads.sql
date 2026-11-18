-- Latest workloads report from each agent (JSON): Proxmox VE guests and
-- Docker/Podman containers. Sent every 5 minutes by Linux agents 0.4+.
ALTER TABLE agents ADD COLUMN IF NOT EXISTS workloads MEDIUMTEXT NULL;
ALTER TABLE agents ADD COLUMN IF NOT EXISTS workloads_at BIGINT UNSIGNED NULL;
