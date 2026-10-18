#!/usr/bin/env python3
"""Builds the installable plugin zip: dist/extended-abilities-<version>.zip.

Only files tracked by git are packaged (no local leftovers), tests/ is left out, and the
repository LICENSE is added. The zip contains a single top-level folder "extended-abilities/",
as WordPress expects for "Plugins > Add New > Upload Plugin".

Usage:
  python3 -I bin/build_zip.py                         build the zip
  python3 -I bin/build_zip.py --check                 only check version consistency
  python3 -I bin/build_zip.py --expect-version 0.4.0  also require this version (release tags)
  python3 -I bin/build_zip.py --release-notes         print the CHANGELOG section of this version

Checks (always): the "Version:" header and EXAB_VERSION in extended-abilities.php agree, the
helper's SERVER_VERSION matches, and CHANGELOG.md has a section for the version.
"""

import argparse
import os
import re
import subprocess
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = "extended-abilities"
EXCLUDE_PREFIXES = (f"{PLUGIN}/tests/",)


def read(path):
    with open(os.path.join(ROOT, path), encoding="utf-8") as fh:
        return fh.read()


def versions():
    main = read(f"{PLUGIN}/{PLUGIN}.php")
    found = {
        "plugin header": re.search(r"^\s*\*\s*Version:\s*(\S+)", main, re.M),
        "EXAB_VERSION": re.search(r"define\(\s*'EXAB_VERSION',\s*'([^']+)'", main),
        "client SERVER_VERSION": re.search(r'^SERVER_VERSION = "([^"]+)"', read("client/exab_upload.py"), re.M),
    }
    missing = [k for k, m in found.items() if not m]
    if missing:
        sys.exit(f"Could not find: {', '.join(missing)}")
    return {k: m.group(1) for k, m in found.items()}


def check(expect=None):
    found = versions()
    if len(set(found.values())) != 1:
        sys.exit("Version mismatch: " + ", ".join(f"{k}={v}" for k, v in found.items()))
    version = next(iter(found.values()))
    if expect and expect != version:
        sys.exit(f"Expected version {expect}, but the code says {version}")
    if not re.search(rf"^## \[?{re.escape(version)}\]?", read("CHANGELOG.md"), re.M):
        sys.exit(f"CHANGELOG.md has no section for {version}")
    return version


def release_notes(version):
    """The body of the CHANGELOG section for a version (without its heading)."""
    text = read("CHANGELOG.md")
    match = re.search(rf"^## \[?{re.escape(version)}\]?.*?\n(.*?)(?=^## |\Z)", text, re.M | re.S)
    return match.group(1).strip() + "\n"


def tracked_files():
    out = subprocess.run(
        ["git", "-C", ROOT, "ls-files", "-z", "--", PLUGIN], check=True, capture_output=True
    ).stdout.decode()
    files = [f for f in out.split("\0") if f and not f.startswith(EXCLUDE_PREFIXES)]
    if not files:
        sys.exit("No tracked plugin files found")
    return sorted(files)


def build(version):
    os.makedirs(os.path.join(ROOT, "dist"), exist_ok=True)
    target = os.path.join(ROOT, "dist", f"{PLUGIN}-{version}.zip")
    files = tracked_files()
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in files:
            zf.write(os.path.join(ROOT, path), path)
        zf.write(os.path.join(ROOT, "LICENSE"), f"{PLUGIN}/LICENSE")
    print(f"{target}: {len(files) + 1} files")
    return target


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="only check versions and changelog")
    parser.add_argument("--expect-version", help="fail unless the code has this version")
    parser.add_argument("--release-notes", action="store_true", help="print this version's CHANGELOG section")
    args = parser.parse_args()

    version = check(args.expect_version)
    if args.release_notes:
        sys.stdout.write(release_notes(version))
        return
    print(f"version {version}: consistent")
    if not args.check:
        build(version)


if __name__ == "__main__":
    main()
