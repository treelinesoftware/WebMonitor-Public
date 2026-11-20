-- WebMonitor Pro remote access: per-host overrides (which methods, ports,
-- user name, web address) as JSON. Empty = worked out from the OS / device type.
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS remote_cfg TEXT NULL;
