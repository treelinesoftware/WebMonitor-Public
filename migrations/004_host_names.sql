-- Hosts added with their IP address as the name get a real one from an
-- installed agent (short hostname) or, failing that, from SNMP sysName.
-- New reports keep doing this (see includes/agents.php, hostdetails.php).
UPDATE hosts h JOIN agents a ON a.host_id = h.id
SET h.name = LEFT(SUBSTRING_INDEX(a.hostname, '.', 1), 60)
WHERE h.name = h.ip_address AND a.hostname IS NOT NULL AND a.hostname <> '';

UPDATE hosts h JOIN host_details d ON d.host_id = h.id
SET h.name = LEFT(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(d.data, '$.system.name')), '.', 1), 60)
WHERE h.name = h.ip_address
  AND JSON_UNQUOTE(JSON_EXTRACT(d.data, '$.system.name')) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(d.data, '$.system.name')) NOT IN ('', 'null');
