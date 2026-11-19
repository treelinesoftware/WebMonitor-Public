-- Card groups (Settings -> Groups), an optional group per card, and screens:
-- named sets of groups the host view can be switched to (e.g. a wall display).
CREATE TABLE IF NOT EXISTS card_groups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS group_id INT UNSIGNED NULL;
ALTER TABLE hosts ADD CONSTRAINT fk_hosts_group FOREIGN KEY IF NOT EXISTS (group_id) REFERENCES card_groups(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS screens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS screen_groups (
  screen_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  PRIMARY KEY (screen_id, group_id),
  CONSTRAINT fk_screen_groups_screen FOREIGN KEY (screen_id) REFERENCES screens(id) ON DELETE CASCADE,
  CONSTRAINT fk_screen_groups_group FOREIGN KEY (group_id) REFERENCES card_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB;
