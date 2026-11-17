"""Thin client boundaries: the worker reaches WordPress and AO, plus the public web only
through the tools it serves to AO over the Reach tunnel (D14). Contracts stay in sync
between docs, plugin and worker, and the capability doc matches the plugin."""

from __future__ import annotations

import ast
import json
import socket
import subprocess
import sys
from pathlib import Path

import pytest

from aoq_worker import contracts
from aoq_worker.families import is_vision, provider_family
from aoq_worker.settings import SettingsError, WorkerSettings
from aoq_worker.wp_client import flatten_query

ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "worker" / "src" / "aoq_worker"

NETWORK_MODULES = {"socket", "ssl", "http", "urllib", "requests", "httpx", "urllib3", "ftplib", "smtplib", "subprocess", "websockets", "websocket"}
# The only modules allowed to reach the network, and what they may use.
ALLOWED = {
    "wp_client.py": {"aiohttp", "ssl"},
    "ao_client.py": {"ao_reach", "aiohttp", "socket", "ssl"},  # aiohttp/socket/ssl only for the IPv4 switch and enrollment
    # Tools AO calls through the tunnel: SearXNG, public pages, image APIs (D14).
    "web_tools.py": {"aiohttp", "socket", "ssl"},
    # Loopback-only MCP servers the Reach tunnel forwards to.
    "mcp_host.py": {"aiohttp", "ao_reach"},
    # language-site: WP-CLI on this server (subprocess) and the verify step reading the public pages.
    "language_site.py": {"aiohttp", "subprocess"},
}


def _imports(path: Path) -> set[str]:
    tree = ast.parse(path.read_text(encoding="utf-8"))
    out: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            out.update(a.name for a in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module and node.level == 0:
            out.add(node.module)
    return out


def test_only_the_client_modules_reach_the_network():
    for path in PKG.rglob("*.py"):
        imports = _imports(path) - {"urllib.parse"}  # URL parsing only, no I/O
        tops = {i.split(".")[0] for i in imports}
        allowed = ALLOWED.get(path.name, set())
        for top in tops & (NETWORK_MODULES | {"aiohttp", "ao_reach"}):
            assert top in allowed, f"{path.relative_to(ROOT)} imports {top}"


def test_dependency_list_is_minimal():
    text = (ROOT / "worker" / "pyproject.toml").read_text(encoding="utf-8")
    deps = text.split("dependencies = [", 1)[1].split("]", 1)[0]
    names = sorted(line.strip().strip('",').split(" ")[0].split(">")[0].split("<")[0] for line in deps.splitlines() if line.strip().startswith('"'))
    assert names == ["aiohttp", "ao-reach", "jsonschema"]


async def test_full_run_makes_no_socket_connections(monkeypatch):
    from .test_pipeline import run

    def refuse(*a, **k):
        raise AssertionError("network access during a fake run")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    outcome, _, _ = await run()
    assert outcome.status == "succeeded", outcome.reason


def test_contracts_are_in_sync():
    res = subprocess.run([sys.executable, str(ROOT / "scripts" / "build_contracts.py"), "--check"], capture_output=True, text=True)
    assert res.returncode == 0, res.stdout + res.stderr


def _fixtures():
    base = ROOT / "docs" / "contracts" / "fixtures"
    return sorted(base.rglob("*.json"))


@pytest.mark.parametrize("path", _fixtures(), ids=lambda p: p.parent.name + "/" + p.name)
def test_shared_fixtures(path: Path):
    name = path.parent.relative_to(ROOT / "docs" / "contracts" / "fixtures").as_posix()
    errors = contracts.errors(json.loads(path.read_text(encoding="utf-8")), name)
    if path.name.startswith("valid-"):
        assert errors == []
    else:
        assert errors


def test_capability_doc_matches_plugin():
    caps = json.loads((ROOT / "wordpress-plugin" / "ao-quill" / "capabilities.json").read_text(encoding="utf-8"))
    doc = (ROOT / "docs" / "capability-status.md").read_text(encoding="utf-8")
    for cap in caps:
        row = next((line for line in doc.splitlines() if f"`{cap['id']}`" in line), None)
        assert row is not None, f"{cap['id']} missing from docs/capability-status.md"
        assert f"| {cap['status']} |" in row, f"{cap['id']} status differs: {row}"


def test_settings_require_https_and_secrets():
    with pytest.raises(SettingsError):
        WorkerSettings.from_env({})
    env = {"AOQ_WP_URL": "http://wordpress.example.com", "AOQ_WP_USER": "aoq-worker", "AOQ_WP_APP_PASSWORD": "x", "AOQ_AO_URL": "https://ao.example.internal:8765"}
    with pytest.raises(SettingsError):
        WorkerSettings.from_env(env)
    env["AOQ_WP_URL"] = "https://wordpress.example.com/"
    s = WorkerSettings.from_env(env)
    assert s.wp_url == "https://wordpress.example.com" and s.wp_host == "wordpress.example.com"


def test_flatten_query():
    assert flatten_query("input", {"run_id": "x", "page": 2, "flag": True, "none": None}) == [("input[run_id]", "x"), ("input[page]", "2"), ("input[flag]", "1")]


def test_families():
    assert provider_family("anthropic", "claude-3-5-haiku-20241022") == "anthropic"
    assert provider_family("openai", "gpt-4o-mini") == "openai"
    assert provider_family("ollama", "qwen3:8b") == "qwen"
    assert provider_family("ollama", "gemma3") == "gemma"
    assert provider_family("deterministic", None) == "deterministic"
    assert is_vision("qwen2.5vl") and not is_vision("qwen3")
    assert is_vision("gemma4:e4b") and not is_vision("qwen3.5:9b")
    assert provider_family("ollama", "qwen3.5:27b") == "qwen" and provider_family("ollama", "gemma4:26b") == "gemma"


async def test_wp_client_refuses_media_from_other_hosts():
    from aoq_worker.wp_client import WordPressClient

    s = WorkerSettings(wp_url="https://wordpress.example.com", wp_user="u", wp_app_password="p", ao_url="https://ao.example.internal:8765")
    client = WordPressClient(s, session=object())  # type: ignore[arg-type]
    with pytest.raises(ValueError):
        await client.fetch_media("https://elsewhere.example.org/a.jpg")


async def test_ipv4_only_applies_to_the_ao_host(monkeypatch):
    import aiohttp.connector as connector

    from aoq_worker.ao_client import force_ipv4_for

    seen = []

    class Recorder:
        def __init__(self, *a, **k):
            pass

        async def resolve(self, host, port=0, family=socket.AF_INET):
            seen.append((host, family))
            return []

        async def close(self):
            pass

    monkeypatch.setattr(connector, "DefaultResolver", Recorder)
    force_ipv4_for("ao.example.internal")
    force_ipv4_for("ao.example.internal")  # idempotent
    r = connector.DefaultResolver()
    await r.resolve("AO.example.internal", 443, socket.AF_UNSPEC)
    await r.resolve("wordpress.example.com", 443, socket.AF_UNSPEC)
    await r.resolve("ao.example.internal", 443, socket.AF_INET6)
    assert seen == [
        ("AO.example.internal", socket.AF_INET),
        ("wordpress.example.com", socket.AF_UNSPEC),
        ("ao.example.internal", socket.AF_INET6),
    ]


def test_ipv4_only_setting():
    env = {"AOQ_WP_URL": "https://wordpress.example.com", "AOQ_WP_USER": "u", "AOQ_WP_APP_PASSWORD": "p", "AOQ_AO_URL": "https://ao.example.internal:8765"}
    assert WorkerSettings.from_env(env).ao_ipv4_only is False
    assert WorkerSettings.from_env({**env, "AOQ_AO_IPV4_ONLY": "1"}).ao_ipv4_only is True


def test_worker_knows_every_plugin_ability():
    import re

    from aoq_worker.wp_client import ABILITIES

    php = (ROOT / "wordpress-plugin" / "ao-quill" / "includes" / "class-aoq-abilities.php").read_text(encoding="utf-8")
    block = php.split("public static function definitions()", 1)[1].split("public static function register()", 1)[0]
    plugin = set(re.findall(r"'([a-z-]+)'\s*=>\s*array\(", block))
    assert plugin and plugin == set(ABILITIES), (sorted(plugin), sorted(ABILITIES))
