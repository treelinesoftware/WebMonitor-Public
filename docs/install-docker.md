# Install with Docker

WebMonitor runs as two containers: **app** (Apache, PHP, the background jobs and
the NetFlow collector, on Rocky Linux) and **db** (MariaDB). Check the
[system requirements](requirements.md) first.

## Install

On a Linux host with Docker and the Compose plugin:

```sh
git clone https://github.com/treelinesoftware/WebMonitor-Public.git webmonitor
cd webmonitor
cp .env.example .env
```

Edit `.env` and set a database password (make one with `openssl rand -hex 24`):

```ini
WEBMON_DB_PASSWORD=paste-a-long-random-value-here
```

Then build and start it:

```sh
docker compose up -d --build
docker compose logs app
```

The first start creates the database and an `admin` login. The log shows its
password once:

```
[webmonitor]  First start: dashboard login  admin / <generated password>
```

Open `https://<host>:8443/webmonitor/` and sign in. Your browser asks you to
accept the self-signed certificate once. Then follow
[Getting started](getting-started.md).

> To choose the admin password yourself, set `WEBMON_ADMIN_PASSWORD` (10
> characters or more) in `.env` before the first start.

With Podman, use `podman compose` in place of `docker compose`.

## Why host networking

`compose.yaml` runs both containers on the host's own network
(`network_mode: host`). WebMonitor needs this to see your network the way a
server install does:

- MAC addresses (and so device makers) from the host's ARP table;
- NetFlow arriving from each switch's real address, so flows are matched to the right switch;
- mDNS, UPnP and NetBIOS replies during device scans.

Because of this, the ports are opened directly on the host. Pick free ones in
`.env` if the defaults are taken. The database only listens on 127.0.0.1.

| Setting | Default | Port |
|---|---|---|
| `WEBMON_HTTPS_PORT` | 8443 | Dashboard and agents (HTTPS) |
| `WEBMON_HTTP_PORT` | 8080 | Redirects to HTTPS |
| `WEBMON_FLOW_PORT` | 2055 | NetFlow/IPFIX (UDP) |
| `WEBMON_DB_PORT` | 3307 | MariaDB, on 127.0.0.1 only |

Open the ports in the host's firewall if it has one, for example:

```sh
sudo firewall-cmd --permanent --add-port=8443/tcp --add-port=8080/tcp --add-port=2055/udp && sudo firewall-cmd --reload
```

To serve on the standard ports 443 and 80, set `WEBMON_HTTPS_PORT=443` and
`WEBMON_HTTP_PORT=80` (nothing else on the host may use them).

## Settings

All settings live in `.env` (see `.env.example` for the full list). After
changing one, apply it with `docker compose up -d`.

| Setting | What it does |
|---|---|
| `WEBMON_DB_PASSWORD` | Required. The database password. Also used to encrypt saved switch logins, so don't change it after setup. |
| `WEBMON_ADMIN_PASSWORD` | Password for the first `admin` login (first start only). |
| `WEBMON_HOSTNAME` | Extra names and addresses for the certificate, comma separated, e.g. `monitor.example.lan,192.168.1.10`. Used when the certificate is first made. |
| `WEBMON_FLOW_EXPORTERS` | Only accept NetFlow from these addresses (comma separated). |
| `WEBMON_DB_BUFFER_POOL` | Database memory: 512M by default; 1G or more for large networks or heavy NetFlow. |
| `TZ` | Timezone for alerts and logs, e.g. `America/Toronto`. |
| `WEBMON_ACCESS_LOG` | `1` writes the web access log to the container log. |

## Docker Desktop and bridge networking

On Docker Desktop (Windows or macOS), host networking isn't fully available.
Use `compose.bridge.yaml`, which publishes the ports instead:

```sh
docker compose -f compose.bridge.yaml up -d --build
```

Everything works, with these limits:

- no MAC addresses from the ARP table (device makers still come from switches and DHCP leases);
- device scans can't hear mDNS or UPnP replies from the LAN;
- with Docker Desktop and rootless Podman, NetFlow shows the Docker gateway as the exporter instead of the switch, so per-port flows can't be matched to a switch (traffic totals still work).

Set `WEBMON_HOSTNAME` to the computer's IP address before the first start, so
the certificate matches the address agents use.

## Where things are kept

| Volume | Holds |
|---|---|
| `webmonitor_db` | The database |
| `webmonitor_state` | Switch SSH keys, NetFlow minute files and the TLS certificate (`tls/`) |

With `compose.bridge.yaml` they're named `webmonitor-bridge_db` and `webmonitor-bridge_state`.

Keep the certificate: agents trust this server by it, and a new one means
reinstalling every agent. Removing the containers keeps the volumes;
`docker compose down -v` deletes them.

## Upgrade

```sh
cd webmonitor
git pull
docker compose up -d --build
```

The app container applies any new database migrations when it starts. Back up
first (below) if you might want to go back.

## Back up and restore

Back up the database and the state volume:

```sh
docker compose exec db sh -c 'mariadb-dump -uwebmonitor -p"$MARIADB_PASSWORD" --single-transaction webmonitor' | gzip > webmonitor-db.sql.gz
docker run --rm -v webmonitor_state:/state -v "$PWD":/backup docker.io/library/alpine tar czf /backup/webmonitor-state.tgz -C /state .
```

Keep a copy of `.env` with them: the database password in it decrypts saved
switch logins.

Restore onto a fresh install (same `.env`):

```sh
docker compose up -d db
gunzip -c webmonitor-db.sql.gz | docker compose exec -T db sh -c 'mariadb -uwebmonitor -p"$MARIADB_PASSWORD" webmonitor'
docker run --rm -v webmonitor_state:/state -v "$PWD":/backup docker.io/library/alpine tar xzf /backup/webmonitor-state.tgz -C /state
docker compose up -d
```

### Moving from a Rocky Linux install

Dump the database on the old server (`mysqldump --single-transaction webmonitor`),
put the old server's `DB_PASS` from `config.php` in `.env` as
`WEBMON_DB_PASSWORD`, and restore the dump as above. To keep your agents, copy
`/etc/pki/tls/certs/webmonitor.crt` and `/etc/pki/tls/private/webmonitor.key`
into the state volume's `tls/` folder before the first full start. Agents also
need to reach the new address and port.

## Everyday commands

| Task | Command |
|---|---|
| Status | `docker compose ps` |
| Logs | `docker compose logs -f app` |
| Restart | `docker compose restart app` |
| Stop / start | `docker compose stop` / `docker compose up -d` |
| Reset a password | `docker compose exec app php /var/www/html/webmonitor/cli/create-user.php admin` |
| Database shell | `docker compose exec db sh -c 'mariadb -uwebmonitor -p"$MARIADB_PASSWORD" webmonitor'` |

## Limits compared with the Rocky Linux install

- The Ookla speed test CLI isn't included; the built-in Cloudflare test is used.
- Upgrades are done with `git pull` and a rebuild, not `rocky-upgrade.sh`.
