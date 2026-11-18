-- Wi-Fi from Aruba Instant virtual controllers (see includes/wireless.php):
-- APs, radios, SSIDs and clients, refreshed every 5 minutes.
CREATE TABLE IF NOT EXISTS wlan_maps (
  host_id INT UNSIGNED NOT NULL PRIMARY KEY,
  collected_at BIGINT UNSIGNED NOT NULL,
  data MEDIUMTEXT NOT NULL,
  FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
