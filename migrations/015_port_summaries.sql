-- Compact port strip for switch cards (one code per port), computed from the
-- port maps whenever they're refreshed (includes/portmap.php).
ALTER TABLE port_maps ADD COLUMN IF NOT EXISTS summary TEXT NULL;
