-- Name and type cards from what the devices are (Settings -> Subnets, per subnet).
ALTER TABLE subnet_scans ADD COLUMN IF NOT EXISTS auto_name TINYINT(1) NOT NULL DEFAULT 0;
-- Set while a card's name and type came from auto-naming; a person renaming it clears it.
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS auto_named TINYINT(1) NOT NULL DEFAULT 0;
