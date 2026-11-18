# Troubleshooting

## Logs

| Install | Command |
|---|---|
| Rocky Linux | `journalctl -u 'webmonitor-*' --since -1h`, `/var/log/httpd/ssl_error_log`, `/var/log/php-fpm/www-error.log` |
| Docker | `docker compose logs --since 1h app` and `docker compose logs db` |

## I can't open the dashboard

- Use HTTPS and the right path: `https://<server>/webmonitor/` (Rocky) or
  `https://<host>:8443/webmonitor/` (Docker).
- **Rocky:** `systemctl status httpd php-fpm mariadb`, and check firewalld allows
  https: `firewall-cmd --list-all`.
- **Docker:** `docker compose ps` should show both containers healthy. If the app
  keeps restarting, its log says why (often `WEBMON_DB_PASSWORD` not set, or the
  database password changed after the first start).
- The browser warns about the certificate: it's self-signed. Accept it once, or
  replace it with your own (see below).

## I forgot the admin password

- Rocky: `sudo php /var/www/html/webmonitor/cli/create-user.php admin`
- Docker: `docker compose exec app php /var/www/html/webmonitor/cli/create-user.php admin`

After 5 wrong passwords a login is locked for 15 minutes.

## Hosts show down but they're up

Checks are TCP connects to ports 80, 443, 22, 445 and 3389, not ping. A device
that answers (or refuses) on any of them is up; one whose firewall silently drops
all five looks down. Allow one of those ports from the WebMonitor server, add a
**Port** check for a port it does answer on (Services tab), or install the agent.

## No switch ports, map or server details

- The card's SNMP community must match the device (**Edit**). Empty means `public`.
- The device must allow SNMP from the WebMonitor server's address.
- Test from the server: `snmpwalk -v2c -c <community> <device IP> sysName`
  (Docker: run it with `docker compose exec app`).
- Port maps refresh every 5 minutes.

## No NetFlow data

See [NetFlow → Check it's working](netflow.md#check-its-working). Check UDP 2055
is open, the exporter points at the right address, and with Docker that you're
using host networking.

## Configuration backups fail

- `Host key verification failed`: the device's key changed. Use **Forget host
  key** on its card, then back up again.
- `no matching key exchange method` or `host key type`: turn on **Allow old SSH
  encryption (SHA-1)** for that device.
- `Permission denied`: check the user and password, or that WebMonitor's public
  key is on the device's user.

## Agents stop reporting after a reinstall or move

Agents trust the server by its certificate. If it changed, reinstall the agents,
or put the old certificate back (Rocky: `/etc/pki/tls/certs/webmonitor.crt` and
`/etc/pki/tls/private/webmonitor.key`; Docker: `tls/` in the state volume) and
restart the web server.

## Use your own certificate

- **Rocky:** replace `/etc/pki/tls/certs/webmonitor.crt` and
  `/etc/pki/tls/private/webmonitor.key`, then `systemctl restart httpd`.
- **Docker:** replace `webmonitor.crt` and `webmonitor.key` in the state
  volume's `tls/` folder, then `docker compose restart app`.

Reinstall agents afterwards, since they pin the certificate.

## The dashboard is slow

- Large NetFlow data needs more database memory. Rocky: set
  `innodb_buffer_pool_size=1G` in `/etc/my.cnf.d/mariadb-server.cnf` and restart
  MariaDB. Docker: set `WEBMON_DB_BUFFER_POOL=1G` in `.env` and run `docker compose up -d`.
- Check the server isn't short of memory or disk: `free -h`, `df -h`.

## Graphs don't show

The chart library loads from `cdn.jsdelivr.net`. The computer you're viewing the
dashboard on needs internet access.

## Docker: devices have no maker, or mDNS names are missing

You're probably on bridge networking (`compose.bridge.yaml` or Docker Desktop).
Use `compose.yaml` on a Linux host. See
[Why host networking](install-docker.md#why-host-networking).

## Docker: a port is already in use

With host networking, the app opens its ports on the host. Change
`WEBMON_HTTPS_PORT`, `WEBMON_HTTP_PORT`, `WEBMON_FLOW_PORT` or `WEBMON_DB_PORT`
in `.env`, then `docker compose up -d`.
