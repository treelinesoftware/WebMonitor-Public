# Upgrading WebMonitor on Rocky Linux

Use the self-contained `rocky-upgrade.sh` on an existing installation, not
`rocky-install.sh`. Copy the upgrade script from the release you want to install
to the server, then run:

```sh
sudo bash rocky-upgrade.sh
```

The script preserves the existing `config.php` and database credentials and
does not reset the database. It first saves a private backup under
`/var/backups/webmonitor/`, then updates application files and runs pending
migrations. Migrations may change the database schema or data, and a release
may change application behavior (for example, automatic device naming). It
installs a package or adds a WebMonitor background service only when a release
needs one and it is missing (currently `nmap` and the `webmonitor-vuln` timer
for vulnerability scans); it does not change other packages, existing
services, certificates, or agent installations. Existing files not
included in the release are left in place. Application files in the release,
including `.htaccess`, are replaced; custom edits to those files must be reapplied.
The script restores the installation's configured SELinux labels after copying.

The database backup first uses the credentials in `config.php`. If MariaDB
refuses them for `mysqldump`, the script retries as the MariaDB `root` user over
the local socket (the default on Rocky). If `config.php`'s credentials do not
work at all, the script stops before changing anything; fix `DB_HOST`,
`DB_USER` and `DB_PASS` there and run it again.

If Apache reports it cannot read `.htaccess` after an earlier upgrade, restore
the labels on the installed application (adjust the path if customized):

```sh
sudo restorecon -Rv /var/www/html/webmonitor
```

If access is still denied, check `/var/log/httpd/error_log` and the system
SELinux audit log for the specific denial. This command does not change the
database or application configuration.

Each backup contains `application.tar.gz` (including `config.php`),
`database.sql`, and, if present, `state.tar.gz` (the contents of
`/var/lib/webmonitor`, including the SSH keys used for switch backups). These
files contain secrets and should be stored securely. Keep a separate backup
outside the server as well.

The database backup is an InnoDB snapshot; monitoring and user activity can
continue during the upgrade, so changes made after the snapshot are not in it.
Avoid concurrent schema changes and use a maintenance window if a consistent
point-in-time recovery is required. The application may briefly serve mixed
versions while files are copied and migrations run.

If the upgrade fails, the script stops and reports the backup directory.
Migrations can partially apply because MariaDB DDL is not transactional; do not
assume retrying or replacing files alone will undo them. Stop application
activity and consult the backup before restoring the application, state, and
database together. Restoring the database also discards changes made since the
backup. Preserve the backed-up `config.php`: its database password is also
used to decrypt saved switch credentials.

For each new release, regenerate both self-contained scripts from the repository:

```sh
python3 deploy/build-install-script.py
```

The upgrade script defaults to `/var/www/html/webmonitor`,
`/var/backups/webmonitor`, and `/var/lib/webmonitor`. For installations using
different paths, set `WEBMONITOR_APP_DIR`, `WEBMONITOR_BACKUP_DIR`, and
`WEBMONITOR_STATE_DIR` when invoking the script as root.
