"""Fixed stage order. No dynamic planning: runs are repeatable and auditable.

    claim -> config -> connect AO -> inventory -> site profile -> style profiles ->
    topics -> novelty -> research + quote verification -> outline -> draft ->
    taxonomy -> images -> QA loop -> translate + per-language QA -> commit -> finish

Any failure ends the run, reports the reason, and writes no post.
"""

from __future__ import annotations

import asyncio
import contextlib
import json
import logging
import time
import uuid
from pathlib import Path
from dataclasses import dataclass
from typing import Any, Awaitable, Callable

from . import __version__
from .ao_client import AOClient, catalog_for_wordpress, models_by_agent
from .mcp_host import CLIENT_IDS as CLIENT_MCP_IDS
from .context import RunContext
from .errors import RunFailure, WordPressError
from .reporter import Reporter
from .stages import commit, draft, images, inventory, novelty, outline, qa, research, site_profile, style_profile, taxonomy, topics, translate

LOG = logging.getLogger("aoq_worker")

AO_REACH_VERSION = "0.19.0"
TOPIC_ROUNDS = 3  # topics -> novelty rounds before a run with no new topic fails

# What a step leaves for the next ones. Saved after every step (save-checkpoint), so a test
# run can be resumed from a step and a finished one published later (the bundle).
STATE_FIELDS = (
    "candidates", "topic", "topic_round", "rejected_topics", "sources", "claims", "outline", "draft",
    "taxonomy", "taxonomy_removed", "images", "image_texts", "translations", "translated", "checked", "scores", "bundle", "inline_count", "instagram",
)
DICT_FIELDS = ("claims", "taxonomy", "taxonomy_removed", "image_texts", "translations", "translated", "checked", "instagram")
# Always run again on resume: they read the live site and are quick.
FRESH_STAGES = ("inventory", "site_profile", "style_profile")


def snapshot(ctx: RunContext) -> dict[str, Any]:
    return json.loads(json.dumps({f: getattr(ctx, f) for f in STATE_FIELDS}, ensure_ascii=False, default=str))


def restore(ctx: RunContext, state: dict[str, Any] | str) -> None:
    if isinstance(state, str):
        state = json.loads(state)
    for f in STATE_FIELDS:
        if f in state:
            value = state[f]
            # Checkpoints saved before the state travelled as text came back from PHP with
            # empty objects turned into empty lists.
            if f in DICT_FIELDS and value == []:
                value = {}
            if f in ("image_texts", "taxonomy_removed") and isinstance(value, dict):
                value = {k: ({} if v == [] else v) for k, v in value.items()}
            setattr(ctx, f, value)


async def save_checkpoint(ctx: RunContext, step: str, completed: list[str]) -> None:
    if ctx.dry_run:
        return
    try:
        state = json.dumps(snapshot(ctx), ensure_ascii=False)
        await ctx.wp.call("save-checkpoint", {"run_id": ctx.run_id, "step": step, "completed": completed, "state": state})
    except Exception:  # noqa: BLE001 - a lost checkpoint must not fail the run
        LOG.warning("could not save the checkpoint after %s", step, exc_info=True)

STAGES: list[Callable[[RunContext], Awaitable[Any]]] = [
    inventory.run,
    site_profile.run,
    style_profile.run,
    topics.run,
    novelty.run,
    research.run,
    outline.run,
    draft.run,
    taxonomy.run,
    images.run,
    qa.run,
    translate.run,
    commit.run,
]


@dataclass
class Dependencies:
    """Everything that reaches the network, injectable for tests."""

    wp: Any  # WordPressClient-like: call(), fetch_media()
    fetch_catalog: Callable[[], Awaitable[Any]]
    overlay_agents: Callable[[], list[dict[str, Any]]]
    open_bridge: Callable[[dict[str, Any]], Awaitable[Any]]
    web_tools: Any = None  # WebTools: the tools the worker serves to AO over the Reach tunnel
    mcp_servers: Any = None  # WorkerMcpServers: stopped when the run ends
    catalog_cache: Any = None  # Path: AO catalog reused by idle polls for CATALOG_TTL seconds


@dataclass
class Outcome:
    run_id: str | None
    status: str  # idle, succeeded, failed
    reason: str | None
    ctx: RunContext | None


CATALOG_TTL = 900  # the worker polls every minute; AO's catalog is fetched at most every 15 minutes when idle


def _cached_ao(path: Any) -> dict[str, Any] | None:
    try:
        data = json.loads(Path(path).read_text(encoding="utf-8"))
        if time.time() - float(data["at"]) <= CATALOG_TTL and data["ao"]["reachable"]:
            return data["ao"]
    except (OSError, ValueError, KeyError, TypeError):
        pass
    return None


async def heartbeat(deps: Dependencies, *, fresh: bool = False) -> tuple[dict[str, Any], Any, list[dict[str, Any]]]:
    """Worker identity and AO reachability for claim-run. Returns (heartbeat, catalog, overlay);
    catalog is None when a recent cached report was reused (idle polls)."""
    overlay = deps.overlay_agents()
    worker = {"version": __version__, "ao_reach_version": AO_REACH_VERSION}
    if not fresh and deps.catalog_cache is not None:
        cached = _cached_ao(deps.catalog_cache)
        if cached is not None:
            return {"worker": worker, "ao": cached}, None, overlay
    try:
        catalog = await deps.fetch_catalog()
        ao = {"reachable": True, "error": None, "catalog": catalog_for_wordpress(catalog, overlay)}
    except Exception as exc:  # noqa: BLE001 - reported to WordPress, run still claimed and then fails at connect
        catalog = None
        ao = {"reachable": False, "error": str(exc)[:1000], "catalog": None}
    if deps.catalog_cache is not None and ao["reachable"]:
        try:
            Path(deps.catalog_cache).parent.mkdir(parents=True, exist_ok=True)
            Path(deps.catalog_cache).write_text(json.dumps({"at": time.time(), "ao": ao}), encoding="utf-8")
        except OSError:
            LOG.warning("could not write the catalog cache", exc_info=True)
    return {"worker": worker, "ao": ao}, catalog, overlay


def _stock_enabled(cfg: dict[str, Any]) -> bool:
    return any(s["id"] == "stock" and s["enabled"] for s in cfg["image_sources"])


def capabilities(ctx: RunContext, bridge: Any, catalog: Any) -> list[dict[str, Any]]:
    agent_ids = {a.id for a in catalog.agents} if catalog is not None else set()
    mcp_ids = {m.id for m in catalog.mcps} if catalog is not None else set()
    tunnel = bool(getattr(bridge, "mcp_tunnel", False))
    cfg = ctx.config

    def mcp(pid: str) -> bool:
        # Worker-hosted MCPs exist only while AO has the tunnel on (D14).
        return (tunnel and pid in CLIENT_MCP_IDS) if pid.startswith("client.") else pid in mcp_ids

    wanted = [cfg["mcp"]["search"], cfg["mcp"]["fetch"]]
    if _stock_enabled(cfg):
        wanted.append(cfg["mcp"].get("stock_images") or "stock_images")
    out = [{"id": "session_overlay", "advertised": bool(getattr(bridge, "session_overlay", False))}]
    if any(pid.startswith("client.") for pid in wanted):
        out.append({"id": "mcp_tunnel", "advertised": tunnel})
    out.extend({"id": f"mcp:{pid}", "advertised": mcp(pid)} for pid in wanted)
    return out + [
        {"id": f"agent:{cfg['agents']['quote_verifier']}", "advertised": cfg["agents"]["quote_verifier"] in agent_ids},
        {"id": f"agent:{cfg['agents']['text_checks']}", "advertised": cfg["agents"]["text_checks"] in agent_ids},
    ]


def finish_payload(ctx: RunContext, status: str, reason: str | None) -> dict[str, Any]:
    agents: list[dict[str, Any]] = []
    for entry in ctx.reporter.log:
        for a in entry["agents"]:
            if a not in agents:
                agents.append(a)
    return {
        "run_id": ctx.run_id,
        "status": status,
        "failure_reason": reason,
        "result": {
            "posts": (ctx.commit_result or {}).get("posts", []),
            "scores": ctx.scores,
            "agents": agents,
            "sources": [{"source_id": s["source_id"], "url": s["url"], "title": s.get("title", "")} for s in ctx.sources],
            "usage": None,  # AO does not return usage to Reach clients (docs/ao-gaps.md).
            "ao": ctx.ao_info,
            "preview": ctx.preview if ctx.test else None,
        },
    }


TRANSLATOR_AGENT = "client.aoq_translator"  # overlays/agent_providers/aoq_translator.yaml (TranslateGemma)


def with_translator(agents: dict[str, str], overlay: Any) -> dict[str, str]:
    """The run's agents, plus TranslateGemma for translations when the overlay has it."""
    out = dict(agents)
    if any(str(a.get("id", "")).endswith("aoq_translator") for a in overlay or []):
        out.setdefault("translator", TRANSLATOR_AGENT)
    return out


async def instagram_job(deps: Dependencies, job: dict, hb: dict, catalog: Any, overlay: Any) -> Outcome:
    """Instagram generation tab (D30): write a caption for one WordPress post and report it.
    WordPress makes the image and, for a post job, posts it."""
    LOG.info("Instagram caption job %s (%s)", job["job_id"], job["mode"])
    bridge = None
    report: dict[str, Any] = {"job_id": job["job_id"], "status": "failed"}
    try:
        if not hb["ao"]["reachable"]:
            raise RunFailure("connect", f"AO catalog unreachable: {hb['ao']['error']}")
        config = await deps.wp.call("get-config", {})
        if catalog is None:
            hb, catalog, overlay = await heartbeat(deps, fresh=True)
        bridge = await deps.open_bridge(config)
        client = AOClient(
            bridge,
            agents=config["agents"],
            models=models_by_agent(catalog, overlay),
            priority=config["ao_priority"],
            timeout=float(config["stage_timeout_seconds"]),
        )
        caption, _ = await commit.write_caption(client, config.get("instagram") or {}, job["language"], job["title"], job["excerpt"], job["text"])
        report.update(status="ok", caption=caption["caption"][:2200], hashtags=caption["hashtags"])
    except RunFailure as exc:
        report["error"] = f"{exc.stage}: {exc.reason}"[:2000]
    except Exception as exc:  # noqa: BLE001 - reported to WordPress
        LOG.exception("Instagram job failed")
        report["error"] = f"{type(exc).__name__}: {exc}"[:2000]
    finally:
        if bridge is not None:
            with contextlib.suppress(Exception):
                await bridge.stop()
        if deps.mcp_servers is not None:
            with contextlib.suppress(Exception):
                await deps.mcp_servers.stop()
    await deps.wp.call("report-instagram", report)
    return Outcome(None, "succeeded" if report["status"] == "ok" else "failed", report.get("error"), None)


async def run_once(deps: Dependencies, *, dry_run: bool = False, on_done: Callable[[RunContext, Outcome], None] | None = None) -> Outcome:
    from . import busy as busy_mod

    hb, catalog, overlay = await heartbeat(deps, fresh=dry_run)
    test = False
    resume = None
    if dry_run:
        run_id = str(uuid.uuid4())
        config = await deps.wp.call("get-config", {})
    else:
        # run.sh holds a lock while a run is in progress, so this check-in means none is.
        claim = await deps.wp.call("claim-run", {**hb, "idle": True})
        if not claim["run"] and claim.get("instagram"):
            async with busy_mod.BusyHeartbeat("instagram", detail=claim["instagram"].get("job_id", "")[:36]):
                return await instagram_job(deps, claim["instagram"], hb, catalog, overlay)
        if not claim["run"]:
            LOG.info("no run due")
            await busy_mod.broadcast(None)  # idle poll: clear sibling Busy banners
            return Outcome(None, "idle", None, None)
        run_id = claim["run"]["run_id"]
        test = claim["run"]["trigger"] == "test"
        resume = claim["run"].get("resume")
        config = await deps.wp.call("get-config", {"run_id": run_id})
        if catalog is None:  # the heartbeat reused a cached report; a run needs the live catalog
            hb, catalog, overlay = await heartbeat(deps, fresh=True)

    if dry_run:
        class _NoBusy:
            def update(self, detail: str) -> None:
                pass

        return await _run_claimed(deps, hb, catalog, overlay, run_id, test, resume, config, dry_run, on_done, _NoBusy())
    async with busy_mod.BusyHeartbeat("post_run", detail=f"{run_id[:8]} connect") as busy:
        return await _run_claimed(deps, hb, catalog, overlay, run_id, test, resume, config, dry_run, on_done, busy)


async def _run_claimed(
    deps: Dependencies,
    hb: dict,
    catalog: Any,
    overlay: Any,
    run_id: str,
    test: bool,
    resume: Any,
    config: dict,
    dry_run: bool,
    on_done: Callable[[RunContext, Outcome], None] | None,
    busy: Any,
) -> Outcome:
    ctx = RunContext(run_id=run_id, dry_run=dry_run, config=config, wp=deps.wp, reporter=Reporter(run_id, deps.wp, dry_run=dry_run), web=deps.web_tools, test=test)
    steps = ["connect", *[s.__module__.rsplit(".", 1)[-1] for s in STAGES]]
    flusher = asyncio.create_task(ctx.reporter.run_flusher())
    if deps.mcp_servers is not None:
        # AO's tool calls through the tunnel appear in the run output under the current task.
        deps.mcp_servers.on_call = lambda tool, arg, note: ctx.reporter.output(
            getattr(ctx.ao, "current_task", "") or ctx.reporter.label, "worker tools", f"\n[tool {tool}: {arg!r} -> {note}]\n"
        )
    bridge = None
    status, reason = "succeeded", None
    try:
        ctx.reporter.progress(0, len(steps), "connect")
        async with ctx.reporter.stage("connect") as rec:
            if not hb["ao"]["reachable"]:
                raise RunFailure("connect", f"AO catalog unreachable: {hb['ao']['error']}")
            bridge = await deps.open_bridge(config)
            ctx.ao = AOClient(
                bridge,
                agents=with_translator(config["agents"], overlay),
                models=models_by_agent(catalog, overlay),
                priority=config["ao_priority"],
                timeout=float(config["stage_timeout_seconds"]),
                on_output=ctx.reporter.output,
            )
            ctx.ao_info = {"engine_version": getattr(bridge, "engine_version", None), "capabilities": capabilities(ctx, bridge, catalog)}
            rec.detail = ctx.ao_info
            missing = [c["id"] for c in ctx.ao_info["capabilities"] if not c["advertised"]]
            if missing:
                raise RunFailure("connect", "AO does not provide: " + ", ".join(missing))
        ctx.profiles = await deps.wp.call("get-profiles", {} if dry_run else {"run_id": run_id})
        names = steps[1:]
        resume_at = names.index(resume["from_step"]) if resume and resume["from_step"] in names else -1
        completed: list[str] = []
        i = 1
        while i <= len(STAGES):
            stage = STAGES[i - 1]
            name = names[i - 1]
            busy.update(f"{run_id[:8]} {name}")
            ctx.reporter.progress(i, len(steps), "preview" if stage is commit.run and (dry_run or test) else steps[i])
            if resume and name not in FRESH_STAGES and i - 1 < resume_at:
                # Resuming: this step's results come from the earlier run's checkpoint.
                if not completed or completed[-1] in FRESH_STAGES:
                    restore(ctx, resume["state"])
                async with ctx.reporter.stage(name) as rec:
                    rec.detail = {"reused_from_run": resume["resumed_from"]}
                completed.append(name)
                # The reused results are this run's too, so it can itself be resumed from here.
                await save_checkpoint(ctx, name, completed)
                i += 1
                continue
            if resume and name not in FRESH_STAGES and i - 1 == resume_at and not any(c not in FRESH_STAGES for c in completed):
                restore(ctx, resume["state"])  # resuming from the first resumable step itself
            # A step with parts (translate: one language at a time) saves the checkpoint after each
            # part, so a run stopped halfway is resumed from the part it was on.
            ctx.save_progress = lambda name=name: save_checkpoint(ctx, name, list(completed))
            await stage(ctx)
            if stage is novelty.run and ctx.topic is None:
                # Nothing new among the candidates: go back to topics with the rejected titles.
                if ctx.topic_round >= TOPIC_ROUNDS:
                    raise RunFailure("novelty", f"No topic candidate was judged new in {TOPIC_ROUNDS} rounds of topics.")
                ctx.topic_round += 1
                i = STAGES.index(topics.run) + 1
                continue
            if name not in completed:
                completed.append(name)
            await save_checkpoint(ctx, name, completed)
            i += 1
        ctx.reporter.progress(len(steps), len(steps), "done")
    except RunFailure as exc:
        status, reason = "failed", f"{exc.stage}: {exc.reason}"
    except WordPressError as exc:
        status, reason = "failed", f"wordpress: {exc}"
    except Exception as exc:  # noqa: BLE001 - any other error still fails the run cleanly
        LOG.exception("run failed")
        status, reason = "failed", f"{type(exc).__name__}: {exc}"
    finally:
        if bridge is not None:
            try:
                await bridge.stop()
            except Exception:  # noqa: BLE001
                LOG.warning("bridge stop failed", exc_info=True)
        if deps.mcp_servers is not None:
            try:
                await deps.mcp_servers.stop()
            except Exception:  # noqa: BLE001
                LOG.warning("MCP server stop failed", exc_info=True)
        flusher.cancel()
        with contextlib.suppress(asyncio.CancelledError):
            await flusher
        await ctx.reporter.flush()

    outcome = Outcome(run_id, status, reason, ctx)
    if on_done is not None:
        on_done(ctx, outcome)
    if not dry_run:
        await deps.wp.call("finish-run", finish_payload(ctx, status, reason))
    return outcome
