-- Per-device opt-in to old SSH algorithms (SHA-1 key exchange and ssh-rsa
-- host keys) for switches whose firmware offers nothing newer.
ALTER TABLE config_backup_settings ADD COLUMN IF NOT EXISTS legacy_ssh TINYINT(1) NOT NULL DEFAULT 0;
