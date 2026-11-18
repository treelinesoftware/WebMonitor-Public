#!/usr/bin/env python3
"""Isolated tests for the generated upgrade script; no server services are used."""
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest


ROOT = Path(__file__).resolve().parent.parent
UPGRADE = ROOT / "deploy" / "rocky-upgrade.sh"


class UpgradeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.app = root / "app"
        self.state = root / "state"
        self.backups = root / "backups"
        self.bin = root / "bin"
        self.units = root / "units"
        for directory in (self.app / "cli", self.state, self.bin, self.units):
            directory.mkdir(parents=True)
        self.config = """<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'webmonitor');
define('DB_USER', 'webmonitor');
define('DB_PASS', 'secret"\\\\password');
define('CUSTOM_SETTING', 'keep');
"""
        (self.app / "config.php").write_text(self.config)
        (self.app / "index.php").write_text("old application")
        (self.app / "cli" / "migrate.php").write_text("old migration runner")
        (self.state / "key").write_text("saved key")
        self.stub("id", 'echo 0\n')
        self.stub("chown", 'exit 0\n')
        self.stub("dnf", 'printf "%s\\n" "$*" >> "$PKG_LOG"\n')
        self.stub("systemctl", 'printf "%s\\n" "$*" >> "$SYSTEMCTL_LOG"\n')
        self.stub("restorecon", '''printf '%s\\n' "$*" >> "$RESTORECON_LOG"
if [ "${FAIL_RESTORECON:-0}" = 1 ]; then echo "restorecon failed" >&2; exit 1; fi
''')
        self.stub("php", '''if [ "$1" = "-r" ]; then
  case "$2" in *"new PDO"*)
    if [ "${FAIL_DB_CONNECT:-0}" = 1 ]; then echo "Database connection with config.php failed: denied" >&2; exit 1; fi
    exit 0 ;;
  esac
  exec /usr/bin/php "$@"
fi
if [ "${FAIL_MIGRATION:-0}" = 1 ]; then echo "migration failed" >&2; exit 1; fi
echo "migrations checked"
''')
        self.stub("mysqldump", '''case "$1" in
  --defaults-file=*)
    test -s "${1#--defaults-file=}" || exit 3
    if [ "${FAIL_DUMP:-0}" = 1 ]; then echo "dump failed" >&2; exit 1; fi
    printf '%s\\n' '-- database backup' ;;
  --no-defaults)
    [ "$2 $3" = "--protocol=socket --user=root" ] || exit 2
    if [ "${FAIL_ROOT_DUMP:-0}" = 1 ]; then echo "root dump failed" >&2; exit 1; fi
    printf '%s\\n' '-- root database backup' ;;
  *) exit 2 ;;
esac
''')
        self.env = os.environ | {
            "PATH": f"{self.bin}:{os.environ['PATH']}",
            "WEBMONITOR_APP_DIR": str(self.app),
            "WEBMONITOR_STATE_DIR": str(self.state),
            "WEBMONITOR_BACKUP_DIR": str(self.backups),
            "RESTORECON_LOG": str(root / "restorecon.log"),
            "WEBMONITOR_SYSTEMD_DIR": str(self.units),
            "PKG_LOG": str(root / "pkg.log"),
            "SYSTEMCTL_LOG": str(root / "systemctl.log"),
        }

    def stub(self, name, body):
        path = self.bin / name
        path.write_text("#!/bin/sh\n" + body)
        path.chmod(0o755)

    def run_upgrade(self, **env):
        return subprocess.run(
            ["bash", str(UPGRADE)], env=self.env | env,
            capture_output=True, text=True, check=False,
        )

    def backup(self):
        return next(self.backups.glob("upgrade-*"))

    def test_backup_and_upgrade_preserve_config_and_state(self):
        result = self.run_upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.app / "config.php").read_text(), self.config)
        self.assertEqual((self.state / "key").read_text(), "saved key")
        self.assertIn('id="header-clock"', (self.app / "index.php").read_text())
        self.assertEqual(Path(self.env["RESTORECON_LOG"]).read_text(), f"-R -v {self.app}\n")
        self.assertEqual((self.app / ".htaccess").stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.app.stat().st_mode & 0o777, 0o755)
        backup = self.backup()
        self.assertEqual((backup / "database.sql").read_text(), "-- database backup\n")
        with tarfile.open(backup / "application.tar.gz") as archive:
            self.assertEqual(archive.extractfile("./config.php").read().decode(), self.config)
            self.assertEqual(archive.extractfile("./index.php").read().decode(), "old application")
        with tarfile.open(backup / "state.tar.gz") as archive:
            self.assertEqual(archive.extractfile("./key").read().decode(), "saved key")
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        again = self.run_upgrade()
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertEqual(len(list(self.backups.glob("upgrade-*"))), 2)
        self.assertEqual((self.app / "config.php").read_text(), self.config)

    def test_adds_scan_service_and_nmap_once(self):
        self.stub("nmap", 'exit 0\n')   # already installed: no package install
        result = self.run_upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f"ExecStart=/usr/bin/php {self.app}/cli/vulnscan.php", (self.units / "webmonitor-vuln.service").read_text())
        self.assertTrue((self.units / "webmonitor-vuln.timer").is_file())
        self.assertFalse(Path(self.env["PKG_LOG"]).exists())
        self.assertIn("enable --now webmonitor-vuln.timer", Path(self.env["SYSTEMCTL_LOG"]).read_text())
        Path(self.env["SYSTEMCTL_LOG"]).unlink()
        again = self.run_upgrade()
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertFalse(Path(self.env["SYSTEMCTL_LOG"]).exists(), "existing service left alone")

    def test_adds_speedtest_service_once(self):
        self.stub("nmap", 'exit 0\n')
        result = self.run_upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f"ExecStart=/usr/bin/php {self.app}/cli/speedtest.php --if-due", (self.units / "webmonitor-speedtest.service").read_text())
        self.assertTrue((self.units / "webmonitor-speedtest.timer").is_file())
        self.assertIn("enable --now webmonitor-speedtest.timer", Path(self.env["SYSTEMCTL_LOG"]).read_text())
        Path(self.env["SYSTEMCTL_LOG"]).unlink()
        again = self.run_upgrade()
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertFalse(Path(self.env["SYSTEMCTL_LOG"]).exists(), "existing service left alone")

    def test_restarts_flow_collector_only_when_changed(self):
        self.stub("nmap", 'exit 0\n')
        (self.app / "cli" / "flow-collector.py").write_text("old collector")
        result = self.run_upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("restart webmonitor-flow.service", Path(self.env["SYSTEMCTL_LOG"]).read_text())
        Path(self.env["SYSTEMCTL_LOG"]).unlink()
        again = self.run_upgrade()
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertFalse(Path(self.env["SYSTEMCTL_LOG"]).exists(), "unchanged collector not restarted")

    def test_installs_missing_nmap(self):
        env_path = ":".join(p for p in self.env["PATH"].split(":") if not Path(p, "nmap").exists())
        result = self.run_upgrade(PATH=env_path)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(Path(self.env["PKG_LOG"]).read_text(), "install -y nmap\n")

    def test_failed_database_backup_does_not_replace_files(self):
        result = self.run_upgrade(FAIL_DUMP="1", FAIL_ROOT_DUMP="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("root dump failed", result.stderr)
        self.assertEqual((self.app / "index.php").read_text(), "old application")
        self.assertEqual((self.app / "config.php").read_text(), self.config)

    def test_rejected_dump_credentials_fall_back_to_root_socket(self):
        result = self.run_upgrade(FAIL_DUMP="1")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Retrying the backup as the MariaDB root user", result.stdout)
        self.assertEqual((self.backup() / "database.sql").read_text(), "-- root database backup\n")

    def test_bad_config_credentials_stop_before_any_change(self):
        result = self.run_upgrade(FAIL_DB_CONNECT="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("do not work. Nothing was changed", result.stderr)
        self.assertFalse((self.backup() / "database.sql").exists())
        self.assertEqual((self.app / "index.php").read_text(), "old application")
        self.assertEqual((self.app / "config.php").read_text(), self.config)

    def test_failed_migration_reports_existing_backup(self):
        result = self.run_upgrade(FAIL_MIGRATION="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("migration failed", result.stderr)
        self.assertIn(str(self.backup()), result.stderr)
        self.assertTrue((self.backup() / "database.sql").is_file())
        self.assertEqual((self.app / "config.php").read_text(), self.config)

    def test_failed_relabel_stops_before_migration(self):
        result = self.run_upgrade(FAIL_RESTORECON="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("restorecon failed", result.stderr)
        self.assertIn(str(self.backup()), result.stderr)
        self.assertNotIn("Applying pending database migrations", result.stdout)
        self.assertEqual((self.app / "config.php").read_text(), self.config)


if __name__ == "__main__":
    unittest.main()
