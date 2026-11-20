#!/usr/bin/env python3
"""Regenerates the embedded application files in the Rocky deployment scripts.

rocky-install.sh and rocky-upgrade.sh are self-contained. Run this after
changing any app file so both scripts match the repo. They never include
WebMonitor Pro. When the private WebMonitor-Pro repository is checked out as
pro/ (ignored by this repository), rocky-install-pro.sh and
rocky-upgrade-pro.sh are made alongside them with pro/ included; they are
ignored too and never committed or published:

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
PRO_SCRIPT = ROOT / "deploy" / "rocky-install-pro.sh"
PRO_UPGRADE_SCRIPT = ROOT / "deploy" / "rocky-upgrade-pro.sh"
PRO_DIR = "pro/"
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
    files = [f for f in out if f and f not in EXCLUDE and not f.startswith(EXCLUDE_DIRS)
             and (ROOT / f).is_file()]  # skips deletions not yet committed
    files.append("config.php")  # generated from config.example.php
    # Root files first, then each directory, alphabetically within each.
    return sorted(set(files), key=lambda f: ("/" in f, f))


# Files in the Pro repository that are not part of the deployed app.
PRO_EXCLUDE = {".gitignore", "README.md"}
PRO_EXCLUDE_DIRS = ("deploy/", "tests/")


def pro_files() -> list[str]:
    """pro/ files to deploy (its tracked and new files, plus the untracked pro-key.php)."""
    pro = ROOT / PRO_DIR
    if not (pro / "pro.php").is_file():
        return []
    out = subprocess.run(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard"],
        cwd=pro, check=True, capture_output=True, text=True,
    ).stdout.split("\n")
    files = [f for f in out if f and f not in PRO_EXCLUDE and not f.startswith(PRO_EXCLUDE_DIRS)]
    if (pro / "pro-key.php").is_file():
        files.append("pro-key.php")
    return sorted({PRO_DIR + f for f in files}, key=lambda f: (f.count("/") > 1, f))


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


def embed(script_path: Path, files: list[str], out_path: Path) -> None:
    """Writes script_path's text to out_path with these files embedded."""
    text = script_path.read_text()
    if script_path == SCRIPT:
        try:
            head, rest = text.split(START, 1)
            _, tail = rest.split(END, 1)
        except ValueError:
            sys.exit("Could not find the [4/6] section markers in rocky-install.sh")
        body = 'mkdir -p "$APP_DIR"\n\n' + "\n".join(block(f) for f in files) + "\n"
        out_path.write_text(head + START + body + END + tail)
    else:
        try:
            head, rest = text.split(UPGRADE_START, 1)
            _, tail = rest.split(UPGRADE_END, 1)
        except ValueError:
            sys.exit("Could not find the embedded file section markers in rocky-upgrade.sh")
        files = [f for f in files if f != "config.php"]
        body = "\n".join(block(f) for f in files) + "\n"
        out_path.write_text(head + UPGRADE_START + body + UPGRADE_END + tail)
    print(f"Embedded {len(files)} files into {out_path.relative_to(ROOT)}")


def main() -> None:
    # rocky-install.sh / rocky-upgrade.sh are published: never with WebMonitor Pro.
    public = [f for f in app_files() if not f.startswith(PRO_DIR)]
    embed(SCRIPT, public, SCRIPT)
    embed(UPGRADE_SCRIPT, public, UPGRADE_SCRIPT)
    pro = pro_files()
    if not pro:
        print("No pro/ checkout: the -pro scripts were not made.")
        return
    if PRO_DIR + "pro-key.php" not in pro:
        print("Warning: pro/pro-key.php is missing (run bash pro/deploy/set-pro-password.sh); Pro can't be unlocked.")
    for src, dst in ((SCRIPT, PRO_SCRIPT), (UPGRADE_SCRIPT, PRO_UPGRADE_SCRIPT)):
        embed(src, public + pro, dst)
        dst.chmod(0o755)


if __name__ == "__main__":
    main()
