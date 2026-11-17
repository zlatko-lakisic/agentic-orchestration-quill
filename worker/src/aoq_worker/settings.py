"""Worker settings from the environment. Everything else comes from WordPress."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlparse


class SettingsError(ValueError):
    pass


def _default_overlay_dir() -> str:
    here = Path(__file__).resolve()
    for parent in here.parents:
        candidate = parent / "overlays"
        if (candidate / "agent_providers").is_dir():
            return str(candidate)
    return "/app/overlays"


def _pem(env: dict[str, str]) -> tuple[str, str, str] | None:
    keys = ("AOQ_AO_MTLS_CERT_PEM", "AOQ_AO_MTLS_KEY_PEM", "AOQ_AO_MTLS_CA_PEM")
    present = [bool(env.get(k)) for k in keys]
    if not any(present):
        return None
    if not all(present):
        raise SettingsError("Set all of " + ", ".join(keys) + " for inline mTLS material")
    return (env[keys[0]], env[keys[1]], env[keys[2]])


@dataclass
class WorkerSettings:
    wp_url: str
    wp_user: str
    wp_app_password: str
    ao_url: str
    wp_ca_file: str | None = None
    wp_ca_pem: str | None = None
    ao_mtls_dir: str | None = None
    ao_mtls_pem: tuple[str, str, str] | None = None  # (client cert, client key, CA), inline
    ao_headers: dict[str, str] = field(default_factory=dict)
    overlay_dir: str = field(default_factory=_default_overlay_dir)
    http_timeout: float = 120.0
    searxng_url: str | None = None  # web_search backend for the worker-hosted MCP (D14)
    searxng_ca_file: str | None = None
    user_agent: str | None = None  # sent by fetch_url and image_search
    ao_ipv4_only: bool = False  # resolve the AO host to IPv4 addresses only

    @classmethod
    def from_env(cls, env: dict[str, str] | None = None) -> WorkerSettings:
        env = dict(os.environ if env is None else env)
        missing = [k for k in ("AOQ_WP_URL", "AOQ_WP_USER", "AOQ_WP_APP_PASSWORD") if not env.get(k)]
        if missing:
            raise SettingsError("Missing environment variables: " + ", ".join(missing))
        headers: dict[str, str] = {}
        if env.get("AOQ_AO_HEADERS_JSON"):
            raw = json.loads(env["AOQ_AO_HEADERS_JSON"])
            if not isinstance(raw, dict):
                raise SettingsError("AOQ_AO_HEADERS_JSON must be a JSON object")
            headers = {str(k): str(v) for k, v in raw.items()}
        out = cls(
            wp_url=env["AOQ_WP_URL"].rstrip("/"),
            wp_user=env["AOQ_WP_USER"],
            wp_app_password=env["AOQ_WP_APP_PASSWORD"],
            ao_url=(env.get("AOQ_AO_URL") or "").rstrip("/"),  # usually from WordPress (D16)
            wp_ca_file=env.get("AOQ_WP_CA_FILE") or None,
            wp_ca_pem=env.get("AOQ_WP_CA_PEM") or None,
            ao_mtls_dir=env.get("AOQ_AO_MTLS_DIR") or None,
            ao_mtls_pem=_pem(env),
            ao_headers=headers,
            overlay_dir=env.get("AOQ_OVERLAY_DIR") or _default_overlay_dir(),
            http_timeout=float(env.get("AOQ_HTTP_TIMEOUT") or 120),
            searxng_url=(env.get("AOQ_SEARXNG_URL") or "").rstrip("/") or None,
            searxng_ca_file=env.get("AOQ_SEARXNG_CA_FILE") or None,
            user_agent=env.get("AOQ_HTTP_USER_AGENT") or None,
            ao_ipv4_only=env.get("AOQ_AO_IPV4_ONLY") == "1",
        )
        if out.searxng_url and urlparse(out.searxng_url).scheme not in ("https", "http"):
            raise SettingsError("AOQ_SEARXNG_URL must be an http or https URL")
        if urlparse(out.wp_url).scheme != "https" and env.get("AOQ_ALLOW_HTTP") != "1":
            raise SettingsError("AOQ_WP_URL must use https (set AOQ_ALLOW_HTTP=1 only for a local test site)")
        return out

    @property
    def wp_host(self) -> str:
        return urlparse(self.wp_url).netloc
