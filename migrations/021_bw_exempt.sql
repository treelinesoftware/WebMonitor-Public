-- Hosts whose heavy traffic is expected (e.g. a torrent/Usenet server): no bandwidth alerts.
ALTER TABLE hosts ADD COLUMN IF NOT EXISTS bw_exempt TINYINT(1) NOT NULL DEFAULT 0;
