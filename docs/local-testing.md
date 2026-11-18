# Local test copy

Run a private copy of WebMonitor on your own PC, to try it out, test a new
release before upgrading your server, or work on the code. It uses Docker (or
Podman) and doesn't touch a production install. See
[Install with Docker](install-docker.md) for the details behind each step.

## Start a test copy

You need Docker Desktop (Windows or macOS), or Docker or Podman on Linux.

```sh
git clone https://github.com/treelinesoftware/WebMonitor-Public.git webmonitor
cd webmonitor
cp .env.example .env
```

In `.env`, set at least:

```ini
WEBMON_DB_PASSWORD=any-long-random-value
WEBMON_ADMIN_PASSWORD=test-admin-password
```

Start it on Docker's own network, so it doesn't open ports on your whole machine
beyond the published ones:

```sh
docker compose -f compose.bridge.yaml up -d --build
```

Open `https://localhost:8443/webmonitor/` and sign in as `admin`.

The test copy really monitors whatever you point it at, so it can check your
network from your PC. Agents and switches should keep reporting to your real
server; only point a test switch or agent at the test copy.

## Test with a copy of your real data

To see your own hosts, history and settings in the test copy, load a database
backup from your server.

1. On the server, make a dump:
   - Rocky Linux install: `sudo mysqldump --single-transaction webmonitor | gzip > webmonitor-db.sql.gz`
   - Docker install: see [Back up and restore](install-docker.md#back-up-and-restore).
2. Put the server's database password in the test copy's `.env` as
   `WEBMON_DB_PASSWORD` (it's `DB_PASS` in the server's `config.php`). Saved
   switch logins only decrypt with it.
3. Load it into a fresh test copy:

```sh
docker compose -f compose.bridge.yaml up -d db
gunzip -c webmonitor-db.sql.gz | docker compose -f compose.bridge.yaml exec -T db sh -c 'mariadb -uwebmonitor -p"$MARIADB_PASSWORD" webmonitor'
docker compose -f compose.bridge.yaml up -d --build
```

> A copy of production data also sends alerts, runs scheduled update windows
> through enrolled agents and backs up switch configs. Before starting the app,
> consider turning off notifications and schedules in the test copy's
> **Settings**, or start it with your PC disconnected from the network those
> devices are on.

## Test an upgrade

With the test copy running your current version and a copy of your data:

```sh
git pull                 # or check out the release you want to test
docker compose -f compose.bridge.yaml up -d --build
docker compose -f compose.bridge.yaml logs app
```

The log shows the migrations that ran. Check the dashboard, then upgrade the
server.

## Work on the code

`compose.dev.yaml` serves the app straight from your checkout, so changes show
up on the next page load without rebuilding:

```sh
docker compose -f compose.bridge.yaml -f compose.dev.yaml up -d --build
```

- PHP, JavaScript and CSS changes apply on reload (background jobs pick them up on their next run).
- Changes to `docker/` files, or new migrations, need `docker compose ... up -d --build` or a restart.
- Run the tests that need no server: see [the README](../README.md#development).

## Clean up

```sh
docker compose -f compose.bridge.yaml down        # stop, keep the data
docker compose -f compose.bridge.yaml down -v     # stop and delete the test data
```
