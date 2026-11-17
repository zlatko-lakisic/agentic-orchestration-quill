"""Command line: ``aoq-worker run [--dry-run] [--out DIR]``, ``aoq-worker check`` and
``aoq-worker language-site --url URL --locale LOCALE``."""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import sys

from . import APP_NAME, __version__
from .settings import SettingsError, WorkerSettings


def _deps(settings: WorkerSettings, wp, tools=None):
    from . import ao_client
    from .mcp_host import WorkerMcpServers
    from .pipeline import Dependencies

    servers = WorkerMcpServers(tools) if tools is not None else None
    return Dependencies(
        wp=wp,
        fetch_catalog=lambda: ao_client.fetch_catalog(settings),
        overlay_agents=lambda: ao_client.overlay_agents(settings.overlay_dir),
        open_bridge=lambda config: ao_client.open_bridge(settings, config, servers),
        web_tools=tools,
        mcp_servers=servers,
        catalog_cache=_cache_dir() / "catalog.json",
    )


def _cache_dir():
    import os
    from pathlib import Path

    base = os.environ.get("XDG_CACHE_HOME") or os.path.join(os.path.expanduser("~"), ".cache")
    return Path(base) / "aoq"


def _web_tools(settings: WorkerSettings):
    from .web_tools import DEFAULT_USER_AGENT, WebTools, WebToolsConfig

    return WebTools(
        WebToolsConfig(
            searxng_url=settings.searxng_url,
            user_agent=settings.user_agent or DEFAULT_USER_AGENT,
            searxng_ca_file=settings.searxng_ca_file,
        )
    )


async def _run(settings: WorkerSettings, dry_run: bool, out: str) -> int:
    from . import dryrun
    from .pipeline import run_once
    from .wp_client import WordPressClient

    from . import connection

    async with WordPressClient(settings) as wp:
        settings = await connection.load(settings, wp)
        async with _web_tools(settings) as tools:
            written: list[str] = []

            def on_done(ctx, outcome):
                if dry_run:
                    written.append(str(dryrun.write(ctx, outcome, out)))

            outcome = await run_once(_deps(settings, wp, tools), dry_run=dry_run, on_done=on_done)
    print(json.dumps({"run_id": outcome.run_id, "status": outcome.status, "reason": outcome.reason, "output": written[0] if written else None}))
    return 0 if outcome.status in ("idle", "succeeded") else 1


async def _check(settings: WorkerSettings) -> int:
    from .pipeline import heartbeat
    from .wp_client import WordPressClient

    from . import connection

    async with WordPressClient(settings) as wp:
        settings = await connection.load(settings, wp)
        tools = _web_tools(settings)
        hb, _, _ = await heartbeat(_deps(settings, wp))
        config = await wp.call("get-config", {})
        search: dict = {"configured": bool(settings.searxng_url), "ok": False, "error": None}
        if settings.searxng_url:
            try:
                res = await tools.web_search("weather", 1)
                search["ok"] = bool(res["results"])
                if not search["ok"]:
                    search["error"] = "no results"
            except Exception as exc:  # noqa: BLE001 - reported, not raised
                search["error"] = str(exc)[:300]
        await tools.close()
    print(json.dumps({"ao": {"reachable": hb["ao"]["reachable"], "error": hb["ao"]["error"]}, "wordpress": "ok", "search": search, "languages": [l["code"] for l in config["languages"]]}))
    uses_search = str(config["mcp"]["search"]).startswith("client.")
    return 0 if hb["ao"]["reachable"] and (search["ok"] or not uses_search) else 1


def _enroll(settings: WorkerSettings, args: argparse.Namespace) -> int:
    """aoq-worker enroll: AO signs a client certificate, which is stored in WordPress (D16).

    The AO URL comes from WordPress (or AOQ_AO_URL). AO's CA comes from --ca-file, or
    from the certificate already stored in WordPress when re-enrolling. The one-time
    token is read from a hidden prompt. Nothing stays on this server afterwards.
    """
    import getpass
    import shutil
    import tempfile

    from . import ao_client
    from .wp_client import WordPressClient

    async def main() -> int:
        async with WordPressClient(settings) as wp:
            conn = await wp.call("get-ao-connection", {})
            ao_url = settings.ao_url or conn.get("ao_url")
            if not ao_url:
                print("Set the AO URL in WordPress first (Network Admin, AO Quill, AO connection).", file=sys.stderr)
                return 2
            if args.ca_file:
                ca_pem = open(args.ca_file, encoding="utf-8").read()
            elif conn.get("mtls"):
                ca_pem = conn["mtls"]["ca_pem"]
            else:
                print("Pass --ca-file with AO's CA certificate (PEM).", file=sys.stderr)
                return 2
            token = getpass.getpass("One-time AO enroll token (input hidden): ").strip()
            if not token:
                print("No token entered.", file=sys.stderr)
                return 2
            tmp = tempfile.mkdtemp(prefix="aoq-enroll-")
            try:
                await ao_client.enroll(
                    ao_url=ao_url, token=token, material_dir=tmp, ca_pem=ca_pem, common_name=args.name,
                    ipv4_only=settings.ao_ipv4_only or bool(conn.get("ao_ipv4_only")),
                )
                read = lambda name: open(f"{tmp}/{name}", encoding="utf-8").read()  # noqa: E731
                saved = await wp.call("save-ao-certificate", {"cert_pem": read("cert.pem"), "key_pem": read("key.pem"), "ca_pem": read("ca.pem")})
            finally:
                shutil.rmtree(tmp, ignore_errors=True)
            print(json.dumps({"enrolled": True, "stored_in": "wordpress", "subject": saved["subject"], "expires_at": saved["expires_at"]}))
            return 0

    return asyncio.run(main())


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="aoq-worker", description=f"{APP_NAME} worker {__version__}")
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("run", help="claim and run today's post if one is due")
    run.add_argument("--dry-run", action="store_true", help="read the real site and use real AO, write nothing to WordPress")
    run.add_argument("--out", default="out", help="dry-run output directory (default ./out)")
    sub.add_parser("check", help="check WordPress and AO connectivity")
    sub.add_parser("version", help="print the version")
    ls = sub.add_parser("language-site", help="make a network site a translated copy of the base site (AO translates)")
    ls.add_argument("--url", required=True, help="the target site, for example https://tourguidemostar.com/de/")
    ls.add_argument("--locale", required=True, help="the WordPress locale it should be, for example de_DE")
    ls.add_argument("--step", default="all", choices=["all", "check", "backup", "language", "export", "translate", "apply", "words", "link", "verify"], help="run one step only (default: all)")
    ls.add_argument("--posts", type=int, default=0, help="also translate this many blog posts, newest first, skipping posts already translated on the target (default 0)")
    ls.add_argument("--wp-path", default="", help="the WordPress folder (default: AOQ_WP_PATH)")
    ls.add_argument("--yes", action="store_true", help="do not ask before changing the site")
    ls.add_argument("--force", action="store_true", help="continue even if the target has many pages of its own")
    ls.add_argument("--until", default="", help="start no new text after this time (ISO with zone, e.g. 2026-11-15T08:00:00-05:00); what is done is copied, exit code 5")
    ls.add_argument("--writer",action="store_true", help="translate with the writer agent (JSON) instead of TranslateGemma")
    enroll = sub.add_parser("enroll", help="one-time mTLS enrollment with AO (token read from a hidden prompt)")
    enroll.add_argument("--ca-file", help="AO's CA certificate (PEM); defaults to the CA already stored in WordPress")
    enroll.add_argument("--name", default="ao-quill-worker", help="client certificate name (default ao-quill-worker)")
    args = parser.parse_args(argv)

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    if args.command == "version":
        print(__version__)
        return 0
    try:
        settings = WorkerSettings.from_env()
    except SettingsError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    if args.command == "enroll":
        return _enroll(settings, args)
    if args.command == "check":
        return asyncio.run(_check(settings))
    if args.command == "language-site":
        from . import language_site

        return asyncio.run(language_site.run(settings, args))
    return asyncio.run(_run(settings, args.dry_run, args.out))


if __name__ == "__main__":
    raise SystemExit(main())
