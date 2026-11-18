-- What each device said when asked directly (includes/devicescan.php): open
-- ports and services, UPnP description, brand info pages, NetBIOS, SNMP.
-- Keyed by MAC when known, else IP.
CREATE TABLE IF NOT EXISTS device_scans (
  dkey VARCHAR(64) NOT NULL PRIMARY KEY,
  ip VARCHAR(45) NULL,
  mac CHAR(17) NULL,
  scanned_at BIGINT UNSIGNED NULL,
  requested_at BIGINT UNSIGNED NULL,
  data MEDIUMTEXT NULL,
  INDEX idx_ip (ip),
  INDEX idx_requested (requested_at)
) ENGINE=InnoDB;
