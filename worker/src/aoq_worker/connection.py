"""AO connection from WordPress (D16).

The plugin keeps the AO URL, search settings and the mTLS client certificate; the
worker fetches them with aoq/get-ao-connection at the start of every poll. Values
set in the environment win, so containers can still pin them.

The certificate is written to a private cache folder (0700, files 0600) because AO
Reach reads mTLS material from files; it is rewritten on every poll, so a certificate
replaced in WordPress takes effect on the next poll.
"""

from __future__ import annotations

import os
from dataclasses import replace
from pathlib import Path
from typing import Any

from .settings import SettingsError, WorkerSettings


def default_cache_dir() -> Path:
    base = os.environ.get("XDG_CACHE_HOME") or os.path.join(os.path.expanduser("~"), ".cache")
    return Path(os.environ.get("AOQ_MTLS_CACHE_DIR") or os.path.join(base, "aoq", "mtls"))


def _write_private(path: Path, text: str) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(text if text.endswith("\n") else text + "\n")
    os.chmod(path, 0o600)


def write_mtls(mtls: dict[str, str], directory: Path) -> Path:
    directory.mkdir(parents=True, exist_ok=True)
    os.chmod(directory, 0o700)
    _write_private(directory / "cert.pem", mtls["cert_pem"])
    _write_private(directory / "key.pem", mtls["key_pem"])
    _write_private(directory / "ca.pem", mtls["ca_pem"])
    return directory


def apply(settings: WorkerSettings, conn: dict[str, Any], cache_dir: Path | None = None) -> WorkerSettings:
    """Fill what the environment left empty from the WordPress connection."""
    out = replace(settings)
    if not out.ao_url and conn.get("ao_url"):
        out.ao_url = str(conn["ao_url"]).rstrip("/")
    if not out.ao_ipv4_only and conn.get("ao_ipv4_only"):
        out.ao_ipv4_only = True
    if not out.searxng_url and conn.get("searxng_url"):
        out.searxng_url = str(conn["searxng_url"]).rstrip("/")
    if not out.user_agent and conn.get("user_agent"):
        out.user_agent = str(conn["user_agent"])
    if not (out.ao_mtls_dir or out.ao_mtls_pem) and conn.get("mtls"):
        out.ao_mtls_dir = str(write_mtls(conn["mtls"], cache_dir or default_cache_dir()))
    if not out.ao_url:
        raise SettingsError("No AO URL: set it in WordPress (Network Admin, AO Quill, AO connection) or AOQ_AO_URL")
    return out


async def load(settings: WorkerSettings, wp: Any, cache_dir: Path | None = None) -> WorkerSettings:
    conn = await wp.call("get-ao-connection", {})
    return apply(settings, conn, cache_dir)
