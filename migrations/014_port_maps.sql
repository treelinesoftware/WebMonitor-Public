-- What's connected to each switch port (see includes/portmap.php), refreshed
-- in the background and when someone opens the switch's Details.
CREATE TABLE IF NOT EXISTS port_maps (
  host_id INT UNSIGNED NOT NULL PRIMARY KEY,
  collected_at BIGINT UNSIGNED NOT NULL,
  data MEDIUMTEXT NOT NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
