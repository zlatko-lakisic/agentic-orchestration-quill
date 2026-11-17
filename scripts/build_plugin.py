#!/usr/bin/env python3
"""Build the installable plugin zip with the worker bundled in ``ao-quill/worker/``.

    python scripts/build_plugin.py            # writes out/ao-quill-<version>.zip

Only committed files go in (``git archive``), so local settings, certificates,
virtual environments and tests never ship. The worker folder holds Python source,
overlays and install.sh; nothing in it is executed by the web server.
"""

from __future__ import annotations

import io
import re
import subprocess
import sys
import tarfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "wordpress-plugin/ao-quill"
SKIP = ("tests/", "vendor/", "composer.json", "composer.lock", "phpunit.xml.dist", ".phpunit")


def committed(path: str) -> tarfile.TarFile:
    data = subprocess.run(["git", "-c", "core.autocrlf=false", "-c", "core.eol=lf", "archive", "--format=tar", "HEAD", path], cwd=ROOT, check=True, capture_output=True).stdout
    return tarfile.open(fileobj=io.BytesIO(data))


def main() -> int:
    version = re.search(r"define\( 'AOQ_VERSION', '([^']+)' \)", (ROOT / PLUGIN / "ao-quill.php").read_text(encoding="utf-8")).group(1)
    out = ROOT / "out" / f"ao-quill-{version}.zip"
    out.parent.mkdir(exist_ok=True)
    count = 0
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        def add(member: tarfile.TarInfo, tar: tarfile.TarFile, arcname: str) -> None:
            nonlocal count
            if not member.isfile():
                return
            info = zipfile.ZipInfo(arcname, date_time=(2026, 1, 1, 0, 0, 0))
            info.external_attr = (0o755 if arcname.endswith(".sh") else 0o644) << 16
            z.writestr(info, tar.extractfile(member).read())
            count += 1

        with committed(PLUGIN) as tar:
            for m in tar.getmembers():
                rel = m.name[len(PLUGIN) + 1 :]
                if rel and not rel.startswith(SKIP):
                    add(m, tar, f"ao-quill/{rel}")
        with committed("worker") as tar:
            for m in tar.getmembers():
                rel = m.name[len("worker/") :]
                if rel and not rel.startswith(("tests/",)):
                    add(m, tar, f"ao-quill/worker/{rel}")
        with committed("overlays") as tar:
            for m in tar.getmembers():
                add(m, tar, f"ao-quill/worker/{m.name}")
    print(f"{out.relative_to(ROOT)}: {count} files")
    return 0


if __name__ == "__main__":
    sys.exit(main())
