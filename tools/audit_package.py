#!/usr/bin/env python3
"""Read-only release-source audit. Python 3 stdlib + Git; never creates an archive.

Default: inspect immutable HEAD blobs. --worktree includes non-ignored new files for
pre-commit validation. Pattern checks complement, never replace, human secret review.
No network requests, no source writes, no Moodle or Google execution.
"""

import argparse
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[1]


def git(*args):
    """Read Git data without invoking a shell."""
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--ref", default="HEAD", help="Commit to audit (default HEAD)")
    group.add_argument("--worktree", action="store_true", help="Include pending source changes")
    args = parser.parse_args()
    if args.worktree:
        names = git("ls-files", "-z", "--cached", "--others", "--exclude-standard")
        source = "worktree (not an immutable release)"
    else:
        source = git("rev-parse", "--verify", args.ref + "^{commit}").decode().strip()
        names = git("ls-tree", "-rz", "--name-only", source)
    files = sorted(set(names.decode().rstrip("\0").split("\0")))
    errors = []
    texts = {}
    forbidden = {".git", "vendor", "node_modules", ".env", "moodledata", "dataroot",
                 "__pycache__", ".phpunit.cache", ".phpunit.result.cache", "coverage",
                 "cache", "localcache", "temp", "tmp", "sessions"}
    sensitive = re.compile(
        r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|"
        r"AIza[0-9A-Za-z_-]{30,}|GOCSPX-[0-9A-Za-z_-]{20,}|"
        r"ya29\.[0-9A-Za-z._-]{20,}|gh[pousr]_[0-9A-Za-z]{30,}|"
        r"[0-9]{8,}-[0-9a-z]{20,}\.apps\.googleusercontent\.com"
    )
    assignments = re.compile(
        r"(?i)['\"]?(?:client_secret|access_token|refresh_token|private_key)['\"]?"
        r"\s*(?:=>|=|:)\s*['\"]([^'\"\r\n]{16,})['\"]"
    )
    staging = re.compile(r"moodle[-]pruebas|motorepuestos[b]iker", re.I)
    for name in files:
        path = PurePosixPath(name)
        parts = set(path.parts)
        if (not name or path.is_absolute() or ".." in parts or parts & forbidden
                or any(p.startswith(".env.") for p in parts)
                or re.search(r"\.(?:zip|sql|sqlite3?|db|log|tmp|bak|pyc|csv)$|~$", name, re.I)
                or re.search(r"(?:junit|test-results|phpunit-results).*\.xml$", name, re.I)
                or path.name in {"config.php", "phpunit.xml"}):
            errors.append(f"Forbidden package path: {name}")
        if args.worktree:
            actual = ROOT / name
            if not actual.is_file() or actual.is_symlink():
                errors.append(f"Missing/nonregular source: {name}")
                continue
            data = actual.read_bytes()
        else:
            data = git("show", f"{source}:{name}")
        try:
            text = data.decode("utf-8-sig")
        except UnicodeDecodeError:
            errors.append(f"Binary content needs manual package review: {name}")
            continue
        texts[name] = text
        for lineno, line in enumerate(text.splitlines(), 1):
            if sensitive.search(line) or assignments.search(line) or staging.search(line):
                # Never print the candidate value: logs must not become a credential leak.
                errors.append(f"Sensitive/staging candidate: {name}:{lineno}")
    required = {"version.php", "lib.php", "mod_form.php", "view.php", "settings.php",
                "health.php", "legacy.php", "db/install.xml", "db/upgrade.php", "db/tasks.php",
                "db/access.php", "db/caches.php", "classes/privacy/provider.php",
                "lang/en/tupmeet.php", "lang/es/tupmeet.php", "lang/es_mx/tupmeet.php"}
    errors.extend(f"Missing required file: {name}" for name in sorted(required - set(files)))
    for name in files:
        if (name == "poc_meet_first.php" or name.startswith("classes/local/poc/")
                or name in {"classes/form/poc_setup_form.php", "tests/poc_test.php"}):
            errors.append(f"Removed PoC file present: {name}")
    # Markdown inline file links only; no network checks and no execution of linked content.
    links = 0
    for name, text in texts.items():
        if not name.endswith(".md"):
            continue
        for target in re.findall(r"\]\(([^\s)]+)(?:\s+['\"][^)]*)?\)", text):
            parsed = urlsplit(target.strip("<>"))
            if parsed.scheme or parsed.netloc or not parsed.path:
                continue
            relative = unquote(parsed.path)
            if relative.startswith("/"):
                errors.append(f"Nonportable absolute documentation path: {name}")
                continue
            candidate = (ROOT / PurePosixPath(name).parent / relative).resolve()
            try:
                key = candidate.relative_to(ROOT).as_posix()
            except ValueError:
                errors.append(f"Documentation link escapes repository: {name} -> {relative}")
                continue
            links += 1
            if key not in files and not any(f.startswith(key.rstrip("/") + "/") for f in files):
                errors.append(f"Missing documentation target: {name} -> {relative}")
    print(json.dumps({"source": source, "files": len(files), "relative_links": links,
                      "errors": errors, "result": "FAIL" if errors else "PASS",
                      "scope": "Names, high-confidence credential patterns and relative file links; manual review required"},
                     ensure_ascii=False, indent=2))
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
