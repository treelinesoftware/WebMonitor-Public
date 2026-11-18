#!/usr/bin/env python3
"""Regenerates the embedded application files in the Rocky deployment scripts.

rocky-install.sh and rocky-upgrade.sh are self-contained. Run this after
changing any app file so both scripts match the repo:

    python3 deploy/build-install-script.py

Only the embedded file sections are rewritten; everything else is left untouched.
"""
import base64
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "deploy" / "rocky-install.sh"
UPGRADE_SCRIPT = ROOT / "deploy" / "rocky-upgrade.sh"
EOF_MARK = "WEBMON_FILE_EOF"
PASS_PLACEHOLDER = "__WEBMON_DB_PASS__"

START = 'echo "==> [4/6] Writing application files to ${APP_DIR}"\n'
END = 'sed -i "s/__WEBMON_DB_PASS__/${DB_PASS}/" "$APP_DIR/config.php"\n'
UPGRADE_START = '# BEGIN EMBEDDED APPLICATION FILES\n'
UPGRADE_END = '# END EMBEDDED APPLICATION FILES\n'

# Repo files that are not part of the deployed app.
EXCLUDE = {".gitignore", ".gitattributes", "config.example.php", "WebMonitor.code-workspace", "README.md", "LICENSE",
           ".dockerignore", ".env.example", "compose.yaml", "compose.bridge.yaml", "compose.dev.yaml"}
EXCLUDE_DIRS = ("deploy/", ".claude/", "docker/")
BINARY_SUFFIXES = {".ico", ".png", ".jpg", ".gif", ".webp"}


def app_files() -> list[str]:
    out = subprocess.run(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard"],
        cwd=ROOT, check=True, capture_output=True, text=True,
    ).stdout.split("\n")
    files = [f for f in out if f and f not in EXCLUDE and not f.startswith(EXCLUDE_DIRS)]
    files.append("config.php")  # generated from config.example.php
    # Root files first, then each directory, alphabetically within each.
    return sorted(set(files), key=lambda f: ("/" in f, f))


def source_text(rel: str) -> str:
    if rel == "config.php":
        text = (ROOT / "config.example.php").read_text()
        if "'change-me'" not in text:
            sys.exit("config.example.php must keep DB_PASS as 'change-me'")
        return text.replace("'change-me'", f"'{PASS_PLACEHOLDER}'")
    return (ROOT / rel).read_text()


def block(rel: str) -> str:
    target = f'"$APP_DIR/{rel}"'
    lines = [f'mkdir -p "$(dirname {target})"']
    if Path(rel).suffix.lower() in BINARY_SUFFIXES:
        data = base64.b64encode((ROOT / rel).read_bytes()).decode()
        wrapped = "\n".join(data[i:i + 76] for i in range(0, len(data), 76))
        lines.append(f"base64 -d > {target} <<'{EOF_MARK}'\n{wrapped}\n{EOF_MARK}")
    else:
        text = source_text(rel)
        if EOF_MARK in text:
            sys.exit(f"{rel} contains the heredoc marker {EOF_MARK}")
        if not text.endswith("\n"):
            text += "\n"
        lines.append(f"cat > {target} <<'{EOF_MARK}'\n{text}{EOF_MARK}")
    return "\n".join(lines) + "\n"


def main() -> None:
    script = SCRIPT.read_text()
    try:
        head, rest = script.split(START, 1)
        _, tail = rest.split(END, 1)
    except ValueError:
        sys.exit("Could not find the [4/6] section markers in rocky-install.sh")

    files = app_files()
    body = 'mkdir -p "$APP_DIR"\n\n' + "\n".join(block(f) for f in files) + "\n"
    SCRIPT.write_text(head + START + body + END + tail)
    print(f"Embedded {len(files)} files into {SCRIPT.relative_to(ROOT)}")

    upgrade = UPGRADE_SCRIPT.read_text()
    try:
        head, rest = upgrade.split(UPGRADE_START, 1)
        _, tail = rest.split(UPGRADE_END, 1)
    except ValueError:
        sys.exit("Could not find the embedded file section markers in rocky-upgrade.sh")
    upgrade_files = [f for f in files if f != "config.php"]
    body = "\n".join(block(f) for f in upgrade_files) + "\n"
    UPGRADE_SCRIPT.write_text(head + UPGRADE_START + body + UPGRADE_END + tail)
    print(f"Embedded {len(upgrade_files)} files into {UPGRADE_SCRIPT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
