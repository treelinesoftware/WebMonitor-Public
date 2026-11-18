-- Per-host mute / maintenance: no alerts are opened or sent for the host
-- until muted_until (ms epoch). Far-future = until unmuted.
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS muted_until BIGINT UNSIGNED NULL;
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS mute_reason VARCHAR(120) NULL;

-- Per-user display preferences (JSON): theme, columns.
ALTER TABLE users ADD COLUMN IF NOT EXISTS prefs TEXT NULL;
