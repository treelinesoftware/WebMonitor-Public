-- A card can be in several groups (replaces hosts.group_id, keeping existing
-- assignments), and update schedules can target groups (targets = 'groups').
CREATE TABLE IF NOT EXISTS host_group_members (
  host_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (host_id, group_id),
  INDEX idx_group (group_id),
  CONSTRAINT fk_hgm_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
  CONSTRAINT fk_hgm_group FOREIGN KEY (group_id) REFERENCES card_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT IGNORE INTO host_group_members (host_id, group_id) SELECT id, group_id FROM hosts WHERE group_id IS NOT NULL;
ALTER TABLE hosts DROP FOREIGN KEY IF EXISTS fk_hosts_group;
ALTER TABLE hosts DROP COLUMN IF EXISTS group_id;
ALTER TABLE update_schedules ADD COLUMN IF NOT EXISTS group_ids TEXT NULL AFTER host_ids;
