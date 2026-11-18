-- Manufacturer of each MAC prefix (IEEE OUI list from the hwdata package),
-- loaded by the background jobs; used to identify devices.
CREATE TABLE IF NOT EXISTS mac_vendors (
  prefix CHAR(6) NOT NULL PRIMARY KEY,
  vendor VARCHAR(160) NOT NULL
) ENGINE=InnoDB;
