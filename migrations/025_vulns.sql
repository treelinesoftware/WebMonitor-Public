-- Vulnerability scans (Security tab). source says which engine found a
-- finding ('builtin' now; 'openvas' when a Greenbone engine feeds results).
CREATE TABLE IF NOT EXISTS vuln_scans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(64) NOT NULL,             -- 'all', 'subnet:192.168.60.0/24' or 'host:12'
  status VARCHAR(16) NOT NULL DEFAULT 'queued',   -- queued, running, done, failed, cancelled
  requested_by INT UNSIGNED NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  started_at BIGINT UNSIGNED NULL,
  finished_at BIGINT UNSIGNED NULL,
  total_hosts INT UNSIGNED NOT NULL DEFAULT 0,
  done_hosts INT UNSIGNED NOT NULL DEFAULT 0,
  current VARCHAR(255) NULL,
  error VARCHAR(500) NULL,
  KEY status_idx (status)
) ENGINE=InnoDB;

-- One row per host per scan: the newest row per address is that host's current result.
CREATE TABLE IF NOT EXISTS vuln_hosts (
  scan_id INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  host_id INT UNSIGNED NULL,
  scanned_at BIGINT UNSIGNED NOT NULL,
  open_ports VARCHAR(1000) NULL,
  os_guess VARCHAR(120) NULL,
  error VARCHAR(500) NULL,
  PRIMARY KEY (scan_id, ip),
  KEY ip_idx (ip, scanned_at),
  CONSTRAINT vuln_hosts_scan FOREIGN KEY (scan_id) REFERENCES vuln_scans (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vuln_findings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scan_id INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  port INT UNSIGNED NULL,
  service VARCHAR(120) NULL,
  check_id VARCHAR(64) NOT NULL,
  severity VARCHAR(8) NOT NULL,           -- critical, high, medium, low, info
  cvss DECIMAL(3,1) NULL,
  title VARCHAR(255) NOT NULL,
  detail TEXT NULL,
  solution VARCHAR(1000) NULL,
  refs VARCHAR(2000) NULL,                -- JSON list of CVE ids / URLs
  confidence VARCHAR(8) NOT NULL DEFAULT 'high',   -- high: confirmed; medium: version-based
  source VARCHAR(16) NOT NULL DEFAULT 'builtin',
  KEY scan_ip (scan_id, ip),
  CONSTRAINT vuln_findings_scan FOREIGN KEY (scan_id) REFERENCES vuln_scans (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- NVD / OSV answers, so each product version is looked up rarely.
CREATE TABLE IF NOT EXISTS vuln_cache (
  k VARCHAR(190) NOT NULL PRIMARY KEY,
  fetched_at BIGINT UNSIGNED NOT NULL,
  data MEDIUMTEXT NOT NULL
) ENGINE=InnoDB;
