-- Locations (Settings -> Locations) and an optional location per card.
CREATE TABLE IF NOT EXISTS locations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED NULL;
ALTER TABLE hosts ADD CONSTRAINT fk_hosts_location FOREIGN KEY IF NOT EXISTS (location_id) REFERENCES locations(id) ON DELETE SET NULL;
