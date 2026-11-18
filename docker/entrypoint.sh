#!/bin/bash
# WebMonitor container start: writes the config from the environment, waits for
# the database, applies migrations, creates the first admin login, makes the
# TLS certificate once, then runs PHP-FPM, Apache, the NetFlow collector and
# the job scheduler. If any of them exits, the container exits (and restarts).
set -euo pipefail

APP=/var/www/html/webmonitor
STATE=/var/lib/webmonitor
TLS_DIR="$STATE/tls"
export CERT="$TLS_DIR/webmonitor.crt"
KEY="$TLS_DIR/webmonitor.key"
HTTPS_PORT="${WEBMON_HTTPS_PORT:-8443}"
HTTP_PORT="${WEBMON_HTTP_PORT:-8080}"

log() { echo "[webmonitor] $*"; }
die() { echo "[webmonitor] ERROR: $*" >&2; exit 1; }

[ -n "${WEBMON_DB_PASSWORD:-}" ] || die "WEBMON_DB_PASSWORD is not set (see .env.example)."
for p in "$HTTPS_PORT" "$HTTP_PORT" "${WEBMON_FLOW_PORT:-2055}" "${WEBMON_DB_PORT:-3306}"; do
    [[ "$p" =~ ^[0-9]+$ ]] || die "port '$p' is not a number"
done

# ---- config ----
# Generated on every start, outside the app directory (includes/db.php looks here first).
php -r '
$e = fn($k, $d = "") => var_export((string) (getenv($k) ?: $d), true);
$tz = getenv("TZ");
echo "<?php\n// Written by the container on each start from its environment. Do not edit.\n",
  "define(\"DB_HOST\", ", $e("WEBMON_DB_HOST", "127.0.0.1"), ");\n",
  "define(\"DB_PORT\", ", (int) (getenv("WEBMON_DB_PORT") ?: 3306), ");\n",
  "define(\"DB_NAME\", ", $e("WEBMON_DB_NAME", "webmonitor"), ");\n",
  "define(\"DB_USER\", ", $e("WEBMON_DB_USER", "webmonitor"), ");\n",
  "define(\"DB_PASS\", ", $e("WEBMON_DB_PASSWORD"), ");\n",
  "define(\"CHECK_INTERVAL_SEC\", 10);\ndefine(\"CHECK_TIMEOUT_SEC\", 5);\ndefine(\"HISTORY_LIMIT\", 60);\n",
  "define(\"TLS_CERT_FILE\", ", var_export(getenv("CERT"), true), ");\n",
  $tz && in_array($tz, DateTimeZone::listIdentifiers(), true) ? "define(\"TIMEZONE\", " . var_export($tz, true) . ");\n" : "";
' > /etc/webmonitor/config.php.new
mv /etc/webmonitor/config.php.new /etc/webmonitor/config.php
chown root:apache /etc/webmonitor/config.php
chmod 640 /etc/webmonitor/config.php

# ---- state directories ----
install -d -o apache -g apache -m 750 "$STATE"
install -d -o apache -g apache -m 700 "$STATE/ssh"
install -d -o apache -g apache -m 750 "$STATE/flows"
install -d -o root -g root -m 755 "$TLS_DIR"

# ---- database ----
log "Waiting for the database at ${WEBMON_DB_HOST}:${WEBMON_DB_PORT}"
for i in $(seq 1 90); do
    if php -r 'require "/etc/webmonitor/config.php"; new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_USER, DB_PASS);' 2>/dev/null; then
        break
    fi
    [ "$i" = 90 ] && die "could not connect to the database after 3 minutes (check WEBMON_DB_* and the db container's log)"
    sleep 2
done
log "Applying database migrations"
php "$APP/cli/migrate.php" | sed 's/^/    /'

if [ -z "$(php -r 'require "'"$APP"'/includes/db.php"; echo db()->query("SELECT 1 FROM users LIMIT 1")->fetchColumn();')" ]; then
    ADMIN_PASS="${WEBMON_ADMIN_PASSWORD:-$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)}"
    WEBMON_PASSWORD="$ADMIN_PASS" php "$APP/cli/create-user.php" admin | sed 's/^/    /'
    if [ -n "${WEBMON_ADMIN_PASSWORD:-}" ]; then
        log "Created the 'admin' login with the password from WEBMON_ADMIN_PASSWORD."
    else
        log "================================================================"
        log " First start: dashboard login  admin / ${ADMIN_PASS}"
        log " Change it under Settings -> Account after signing in."
        log "================================================================"
    fi
fi

# ---- TLS certificate (made once; agents pin it, so it lives in the state volume) ----
if [ ! -s "$CERT" ] || [ ! -s "$KEY" ]; then
    NAME="$(hostname)"
    NAMES="${WEBMON_HOSTNAME:-}"
    SAN="DNS:${NAME},DNS:localhost,IP:127.0.0.1"
    for n in ${NAMES//,/ } $(hostname -I 2>/dev/null); do
        if [[ "$n" =~ ^[0-9.]+$ || "$n" == *:* ]]; then n="IP:${n}"; else n="DNS:${n}"; fi
        [[ ",${SAN}," == *",${n},"* ]] || SAN="${SAN},${n}"
    done
    CN="${NAMES%%,*}"; CN="${CN:-$NAME}"
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 3650 \
        -subj "/CN=${CN}/O=WebMonitor" -addext "subjectAltName=${SAN}" \
        -keyout "$KEY" -out "$CERT" 2>/dev/null
    chmod 600 "$KEY"
    chmod 644 "$CERT"
    log "Created a self-signed certificate for: ${SAN}"
fi

# ---- Apache ----
cp /etc/httpd/conf/httpd.conf.orig /etc/httpd/conf/httpd.conf
cp /etc/httpd/conf.d/ssl.conf.orig /etc/httpd/conf.d/ssl.conf
sed -i -E "s/^Listen 80$/Listen ${HTTP_PORT}/; s|^ErrorLog .*|ErrorLog /proc/self/fd/2|; s|^([[:space:]]*)CustomLog .*|\\1CustomLog /dev/null combined|" \
    /etc/httpd/conf/httpd.conf
sed -i -E "s/^Listen 443 https/Listen ${HTTPS_PORT} https/; s/_default_:443/_default_:${HTTPS_PORT}/; \
    s|^([[:space:]]*)SSLCertificateFile .*|\\1SSLCertificateFile ${CERT}|; \
    s|^([[:space:]]*)SSLCertificateKeyFile .*|\\1SSLCertificateKeyFile ${KEY}|; \
    s|^([[:space:]]*)ErrorLog .*|\\1ErrorLog /proc/self/fd/2|; \
    s|^([[:space:]]*)TransferLog .*|\\1CustomLog /dev/null combined|" \
    /etc/httpd/conf.d/ssl.conf
# The SSL request log spans two lines (a trailing backslash): drop it whole.
sed -i -E '/^[[:space:]]*CustomLog .*\\$/,+1d' /etc/httpd/conf.d/ssl.conf
if [ "${WEBMON_ACCESS_LOG:-0}" = 1 ]; then
    sed -i -E 's|CustomLog /dev/null|CustomLog /proc/self/fd/1|' /etc/httpd/conf/httpd.conf /etc/httpd/conf.d/ssl.conf
fi
HTTPS_SUFFIX=":${HTTPS_PORT}"; [ "$HTTPS_PORT" = 443 ] && HTTPS_SUFFIX=""
cat > /etc/httpd/conf.d/webmonitor.conf <<EOF
ServerName localhost
<Directory "${APP}">
    AllowOverride All
    Require all granted
    Options -Indexes
</Directory>
RedirectMatch 302 ^/$ /webmonitor/
# Plain HTTP only redirects to HTTPS (on its own port).
<VirtualHost *:${HTTP_PORT}>
    RewriteEngine On
    RewriteCond %{HTTP_HOST} ^(\[[^]]+\]|[^:]+)
    RewriteRule ^ https://%1${HTTPS_SUFFIX}%{REQUEST_URI} [R=302,L]
</VirtualHost>
EOF
httpd -t 2>&1 | grep -v "Syntax OK" || true

# ---- run ----
mkdir -p /run/php-fpm /run/webmonitor
pids=()
php-fpm -F & pids+=($!)
runuser -u apache -- env WM_FLOW_PORT="${WEBMON_FLOW_PORT:-2055}" WM_FLOW_DIR="$STATE/flows" \
    ${WEBMON_FLOW_EXPORTERS:+WM_FLOW_EXPORTERS="$WEBMON_FLOW_EXPORTERS"} \
    python3 -I "$APP/cli/flow-collector.py" & pids+=($!)
/usr/local/bin/scheduler.sh & pids+=($!)
httpd -DFOREGROUND & pids+=($!)
log "Running. Open https://<this host>${HTTPS_SUFFIX}/webmonitor/"

trap 'log "Stopping"; kill -TERM "${pids[@]}" 2>/dev/null; wait; exit 0' TERM INT
set +e
wait -n
rc=$?
log "A service exited (status ${rc}); stopping the container so it restarts."
kill -TERM "${pids[@]}" 2>/dev/null
wait
exit "$rc"
