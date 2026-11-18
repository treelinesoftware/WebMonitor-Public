# WebMonitor application image: Apache + PHP-FPM, the background jobs and the
# NetFlow collector, on the same Rocky Linux base as the bare-metal install.
# The database runs in its own container (see compose.yaml).
#
#   docker compose build          (from the repository root)
FROM docker.io/rockylinux/rockylinux:10

RUN dnf -y install \
        httpd mod_ssl openssl \
        php php-cli php-fpm php-mysqlnd php-snmp php-mbstring php-xml php-process \
        net-snmp-utils nmap python3 openssh-clients hwdata tzdata \
        util-linux hostname iproute curl-minimal \
    && dnf clean all \
    && rm -rf /var/cache/dnf \
    # Originals the entrypoint rewrites on every start (ports and certificate come from the environment).
    && cp /etc/httpd/conf/httpd.conf /etc/httpd/conf/httpd.conf.orig \
    && cp /etc/httpd/conf.d/ssl.conf /etc/httpd/conf.d/ssl.conf.orig \
    # Errors to the container log.
    && sed -i 's|^error_log = .*|error_log = /proc/self/fd/2|' /etc/php-fpm.conf \
    && mkdir -p /run/php-fpm /etc/webmonitor

COPY . /var/www/html/webmonitor
COPY docker/entrypoint.sh docker/scheduler.sh /usr/local/bin/
RUN chmod 755 /usr/local/bin/entrypoint.sh /usr/local/bin/scheduler.sh \
    && rm -rf /var/www/html/webmonitor/docker /var/www/html/webmonitor/config.php \
    && find /var/www/html/webmonitor -type d -exec chmod 755 {} + \
    && find /var/www/html/webmonitor -type f -exec chmod 644 {} +

# FlexLM (Licences tab): lmutil is Flexera's and can't be included here. Copy it to
# /var/lib/webmonitor/lmutil on the volume, or mount it at /opt/flexlm/lmutil (docs/flexlm.md).
# The LSB loader it asks for is not needed: WebMonitor starts it through glibc's loader.
RUN mkdir -p /opt/flexlm

# Switch SSH keys, NetFlow minute files, the TLS certificate (keep it: agents pin it) and lmutil.
VOLUME /var/lib/webmonitor

ENV WEBMON_HTTPS_PORT=8443 \
    WEBMON_HTTP_PORT=8080 \
    WEBMON_FLOW_PORT=2055 \
    WEBMON_DB_HOST=127.0.0.1 \
    WEBMON_DB_PORT=3307 \
    WEBMON_DB_NAME=webmonitor \
    WEBMON_DB_USER=webmonitor

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -fsk -o /dev/null "https://127.0.0.1:${WEBMON_HTTPS_PORT}/webmonitor/login.php" || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
