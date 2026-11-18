# Install on Rocky Linux

The install script sets up everything on a fresh Rocky Linux 9 or 10 server (or
AlmaLinux/RHEL): Apache with HTTPS, PHP, MariaDB, the background jobs and the
NetFlow collector. Check the [system requirements](requirements.md) first.

## Install

The script carries every application file, so copy just `deploy/rocky-install.sh`
from the release to the server and run it as root:

```sh
sudo bash rocky-install.sh
```

If MariaDB is already installed and its root account has a password:

```sh
sudo MYSQL_ROOT_PASS='yourpass' bash rocky-install.sh
```

It takes a few minutes and:

1. installs the packages (httpd, mod_ssl, MariaDB, PHP 8, net-snmp-utils, nmap);
2. creates the `webmonitor` database and a database user with a random password;
3. writes the app to `/var/www/html/webmonitor` and applies the database migrations;
4. creates an `admin` login with a random password (shown at the end);
5. sets up HTTPS with a self-signed certificate and opens http, https and UDP 2055 in firewalld;
6. installs the background jobs as systemd timers and runs a self-test.

When it finishes, open `https://<server>/webmonitor/` and sign in as `admin`
with the password it showed. Your browser asks you to accept the self-signed
certificate once. Then follow [Getting started](getting-started.md).

> Change the admin password under **Settings → Account** after you sign in.

## What runs where

| Item | Location |
|---|---|
| Application | `/var/www/html/webmonitor` |
| Settings (database login) | `/var/www/html/webmonitor/config.php` |
| State (switch SSH keys, NetFlow files) | `/var/lib/webmonitor` |
| Certificate | `/etc/pki/tls/certs/webmonitor.crt` and `/etc/pki/tls/private/webmonitor.key` |
| Upgrade backups | `/var/backups/webmonitor` |
| Logs | `journalctl -u 'webmonitor-*'`, `/var/log/httpd/`, `/var/log/php-fpm/` |

Background jobs (systemd):

| Unit | When | What |
|---|---|---|
| `webmonitor-cron.timer` | every minute | host checks, alerts, requested scans and backups, schedules, flows, switch port traffic |
| `webmonitor-snmp.timer` | every 5 minutes | SNMP polling, subnet discovery, port and Wi-Fi maps, DHCP leases, Home Assistant, device scans |
| `webmonitor-vuln.timer` | every minute | starts queued vulnerability scans |
| `webmonitor-speedtest.timer` | every minute | runs an internet speed test when one is due |
| `webmonitor-flow.service` | always | NetFlow/IPFIX collector on UDP 2055 |

Check them with `systemctl list-timers 'webmonitor-*'` and
`systemctl status webmonitor-flow`.

## Upgrade

Use `deploy/rocky-upgrade.sh` from the new release, not the install script:

```sh
sudo bash rocky-upgrade.sh
```

It backs up the application, state and database to `/var/backups/webmonitor/`
first, keeps `config.php`, replaces the application files, runs any new
migrations and adds any new background services. Don't copy the repository onto
the server, and don't replace `config.php`: its database password also decrypts
saved switch logins. See [deploy/UPGRADING.md](../deploy/UPGRADING.md) for the
details and how to restore.

## Back up

The upgrade script makes a backup each time. For regular backups, save these:

```sh
sudo mysqldump --single-transaction webmonitor | gzip > webmonitor-db.sql.gz
sudo tar czf webmonitor-files.tgz /var/www/html/webmonitor/config.php /var/lib/webmonitor \
    /etc/pki/tls/certs/webmonitor.crt /etc/pki/tls/private/webmonitor.key
```

Keep the certificate: agents trust this server by its certificate, and a new one
means reinstalling every agent.

## Restore or move to a new server

1. Run the install script on the new server.
2. Stop the jobs: `sudo systemctl stop 'webmonitor-*'`.
3. Load the database: `gunzip -c webmonitor-db.sql.gz | sudo mysql webmonitor`.
4. Put back the files: `sudo tar xzf webmonitor-files.tgz -C /` and
   `sudo chown -R apache:apache /var/lib/webmonitor`, then
   `sudo restorecon -R /var/www/html/webmonitor /var/lib/webmonitor /etc/pki/tls`.
5. Start everything: `sudo systemctl restart httpd php-fpm && sudo systemctl start webmonitor-flow 'webmonitor-*.timer'`.

Restoring `config.php` from the old server keeps its database password, so set
the database user to it: `sudo mysql -e "ALTER USER 'webmonitor'@'localhost' IDENTIFIED BY '<DB_PASS from config.php>'"`.

## Reset a password

```sh
sudo php /var/www/html/webmonitor/cli/create-user.php admin
```

It asks for the new password. The same command creates a login that doesn't exist yet.

## Uninstall

```sh
sudo systemctl disable --now webmonitor-flow 'webmonitor-*.timer'
sudo rm -f /etc/systemd/system/webmonitor-* /etc/httpd/conf.d/webmonitor.conf
sudo rm -rf /var/www/html/webmonitor /var/lib/webmonitor
sudo mysql -e "DROP DATABASE webmonitor; DROP USER 'webmonitor'@'localhost'"
sudo systemctl daemon-reload && sudo systemctl restart httpd
```
