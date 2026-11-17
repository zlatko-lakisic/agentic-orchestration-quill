"""AO side of the worker. The only module that imports ao_reach.

Every stage call is one ``direct_agent`` call with a JSON Schema. When a stage also
needs server tools (search, fetch) or images, AO's JSON mode ignores them, so the
call is split (docs/decisions.md D3): the tool or vision call returns prose, then
the structurer agent turns that prose into JSON under the schema.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Protocol

from . import APP_ID, contracts
from .errors import AOError, ContractError
from .families import is_vision, provider_family
from .mcp_host import CLIENT_IDS as CLIENT_MCP_IDS
from .settings import WorkerSettings

PRIORITY_TIERS = {"background", "low", "normal"}  # AO named tiers; realtime clients (voice) rank above all of them.


class Bridge(Protocol):
    session_overlay: bool

    async def direct_agent(self, **kwargs: Any) -> dict[str, Any]: ...

    async def stop(self, *, clear_remote: bool = True) -> None: ...


@dataclass
class CallRecord:
    role: str
    agent_id: str
    model: str | None
    elapsed_ms: int


@dataclass
class CallResult:
    data: dict[str, Any]
    records: list[CallRecord] = field(default_factory=list)
    prose: str = ""  # the agent's own answer, for tool and image calls (before the structurer)


def task_header(task: str) -> str:
    """First line of every prompt, so AO logs and test fakes can tell calls apart."""
    return f"AO Quill task: {task}\n\n"


MIN_AGENT_ANSWER_CHARS = 80
AGENT_AGAIN = (
    "\n\nYour previous answer was cut off; it was only: {previous!r}. Use the tools as needed, then give your"
    " complete answer as plain text."
)
STRUCTURE_ATTEMPTS = 2
STRUCTURE_RETRY = (
    "\n\nYour previous JSON was rejected: {error}\nFix only what the error names. When the material has no"
    " value for a field, leave the whole item out. Never invent URLs, IDs, quotes or numbers to fill a field."
)


STRUCTURE_INSTRUCTION = (
    "Turn the material in the context into JSON that matches the schema. Copy URLs, IDs and quotes exactly"
    " as they appear. Do not add anything that is not in the material. Leave out any item you have no real"
    " data for instead of filling it with empty values."
)


def _is_format_error(exc: Exception) -> bool:
    if isinstance(exc, ContractError):
        return True
    text = str(exc).lower()
    return "unexpected format" in text or "direct_agent_format" in text or "jsonschema" in text


class AOClient:
    def __init__(
        self,
        bridge: Bridge,
        *,
        agents: dict[str, str],
        models: dict[str, str | None],
        priority: str,
        timeout: float,
        on_output: Callable[[str, str, str], None] | None = None,
    ) -> None:
        self.bridge = bridge
        self.on_output = on_output
        self.current_task = ""
        self.agents = agents
        self.models = models
        self.priority = priority if priority in PRIORITY_TIERS else "background"
        self.timeout = timeout

    async def _direct(self, role: str, text: str, context: str, **extra: Any) -> tuple[str, CallRecord]:
        agent_id = self.agents[role]
        task = text.split("\n", 1)[0].removeprefix("AO Quill task: ").strip()
        self.current_task = task  # lets tool calls arriving through the tunnel be shown under this task
        started = time.monotonic()
        # Logged at the start of every call, so the Generation tab knows which sub-step is
        # running even while a model produces no visible output (thinking, long JSON).
        self._emit(task, agent_id, "\n[started]\n")
        live = self.on_output is not None and hasattr(self.bridge, "chunk_listener")
        if live:
            # AO streams the agent's output while it works; show it as it arrives.
            self.bridge.chunk_listener = lambda chunk: self.on_output(task, agent_id, chunk)  # type: ignore[misc]
        try:
            res = await self.bridge.direct_agent(
                agent_provider_id=agent_id,
                text=text,
                context=context,
                priority=self.priority,
                timeout=self.timeout,
                **extra,
            )
        except TimeoutError as exc:
            self._emit(task, agent_id, f"\n[timed out after {self.timeout:.0f} s]\n")
            raise AOError(role, f"{agent_id} timed out after {self.timeout:.0f} s") from exc
        except Exception as exc:  # noqa: BLE001 - ReachRunError and transport errors
            # AO puts the exact jsonschema error in ReachRunError.detail; the message alone only says
            # "unexpected format", so the detail is kept for the retry prompt and the run log.
            message = f"{agent_id} failed: {exc}"
            detail = str(getattr(exc, "detail", "") or "")
            if getattr(exc, "code", None) == "direct_agent_format" and detail:
                message += f" [direct_agent_format: {detail[:800]}]"
            self._emit(task, agent_id, f"\n[failed: {message}]\n")
            raise AOError(role, message) from exc
        finally:
            if live:
                self.bridge.chunk_listener = None
        elapsed = time.monotonic() - started
        answer = str(res.get("text") or "")
        # Whole answers up to 40,000 characters, so the Generation tab can show them in full.
        shown = answer if len(answer) <= 40000 else answer[:40000] + "\n[...]"
        self._emit(task, agent_id, f"\n[answer after {elapsed:.0f} s]\n{shown}\n")
        record = CallRecord(role, agent_id, self.models.get(agent_id), int(elapsed * 1000))
        return answer, record

    def _emit(self, task: str, agent_id: str, text: str) -> None:
        if self.on_output is not None:
            self.on_output(task, agent_id, text)

    @staticmethod
    def _parse(raw: str, schema_name: str, role: str) -> dict[str, Any]:
        """Strict parse for deterministic agents: their output must already be exactly valid."""
        try:
            data = json.loads(raw.strip())
        except json.JSONDecodeError as exc:
            raise ContractError(role, f"AO returned invalid JSON for {schema_name}: {exc}") from exc
        return contracts.validate(data, f"stages/{schema_name}", stage=role)

    def _accept(self, raw: str, schema_name: str, role: str, task: str) -> dict[str, Any]:
        """Parse and prune an answer against the strict contract; report what was removed."""
        try:
            data = json.loads(raw.strip())
        except json.JSONDecodeError as exc:
            raise ContractError(role, f"AO returned invalid JSON for {schema_name}: {exc}") from exc
        data, dropped = contracts.prune(data, f"stages/{schema_name}", stage=role)
        if dropped:
            self._emit(task, "worker", f"\n[worker removed {len(dropped)} invalid part(s): {', '.join(dropped[:12])}]\n")
        return data

    async def json_call(
        self,
        role: str,
        task: str,
        prompt: str,
        context: str,
        schema_name: str,
        *,
        mcp_ids: list[str] | None = None,
        images: list[dict[str, Any]] | None = None,
        retries: int = 1,
        reference: str | None = None,
    ) -> CallResult:
        """One stage call that must return JSON valid under ``stages/<schema_name>``.

        ``reference`` (for tool-using calls) is data the structurer needs besides the agent's prose,
        such as a list of valid IDs; the structurer otherwise sees only the prose.

        AO gets a loose schema (contracts.model_schema); the worker prunes the answer against the
        strict one. When the JSON is rejected, by AO or by the worker, only the JSON-producing call
        is repeated, with the error, so a slow agent and tool phase is not thrown away.
        """
        schema = contracts.model_schema(schema_name)
        records: list[CallRecord] = []
        uses_tools = bool(mcp_ids or images)
        prose = ""
        if uses_tools:
            extra: dict[str, Any] = {}
            if mcp_ids:
                extra["mcp_provider_ids"] = list(mcp_ids)
            if images:
                extra["images"] = list(images)
            prose, rec = await self._direct(role, task_header(task) + prompt, context, **extra)
            records.append(rec)
            if len(prose.strip()) < MIN_AGENT_ANSWER_CHARS:
                # A tool-using agent sometimes ends with a garbled tool call as its whole answer
                # (test run 19 answered just "function"); there is nothing to structure, so the
                # agent runs once more.
                self._emit(task, "worker", f"\n[agent answer too short ({len(prose.strip())} characters); asking again]\n")
                prose, rec = await self._direct(
                    role, task_header(task + ":again") + prompt + AGENT_AGAIN.format(previous=prose.strip()[:80]), context, **extra
                )
                records.append(rec)
                if len(prose.strip()) < MIN_AGENT_ANSWER_CHARS:
                    raise ContractError(role, f"The agent's answer was too short to use: {prose.strip()[:80]!r}")
        attempts = (STRUCTURE_ATTEMPTS if uses_tools else retries + 1)
        error = ""
        last: Exception | None = None
        for attempt in range(1, attempts + 1):
            hint = STRUCTURE_RETRY.format(error=error[:900]) if error else ""
            try:
                if uses_tools:
                    name = task + (":structure" if attempt == 1 else f":structure{attempt}")
                    material = prose if not reference else (
                        prose + "\n\nReference data (take IDs only from here, never any other number):\n" + reference
                    )
                    raw, rec = await self._direct(
                        "structurer", task_header(name) + STRUCTURE_INSTRUCTION + hint, material,
                        response_format={"type": "json_object"}, json_schema=schema,
                    )
                else:
                    name = task if attempt == 1 else f"{task}:retry{attempt}"
                    raw, rec = await self._direct(
                        role, task_header(name) + prompt + hint, context,
                        response_format={"type": "json_object"}, json_schema=schema,
                    )
                records.append(rec)
                return CallResult(self._accept(raw, schema_name, role, task), records, prose)
            except (AOError, ContractError) as exc:
                if not _is_format_error(exc):
                    raise
                last, error = exc, str(exc)
        assert last is not None
        raise last

    async def text_call(self, role: str, task: str, prompt: str, context: str = "") -> tuple[str, CallRecord]:
        """One call that answers in plain text (no JSON), for models with their own prompt format."""
        return await self._direct(role, task_header(task) + prompt, context)

    async def deterministic(self, role: str, task: str, payload: dict[str, Any], schema_name: str) -> CallResult:
        """Call a deterministic AO agent (no model). Input and output are JSON text."""
        raw, rec = await self._direct(role, task_header(task) + json.dumps(payload, ensure_ascii=False), "")
        body = raw.strip()
        start = body.find("{")
        if start > 0:  # tolerate the engine echoing a prefix; the JSON object itself must be intact
            body = body[start:]
        return CallResult(self._parse(body, schema_name, role), [rec])


# ------------------------------------------------------------------ catalog


def overlay_agents(overlay_dir: str) -> list[dict[str, Any]]:
    """Overlay agents as the session will register them (client.* IDs)."""
    from ao_reach.overlay_packer import OverlayPacker

    pack = OverlayPacker().pack(overlay_dir)
    return pack.agents


def catalog_for_wordpress(catalog: Any | None, overlay: list[dict[str, Any]]) -> dict[str, Any]:
    """Shape the AO catalog plus overlay agents for the claim-run heartbeat."""
    agents: list[dict[str, Any]] = []
    mcps: list[str] = []
    generated_at = None
    if catalog is not None:
        generated_at = catalog.generated_at
        for entry in catalog.agents:
            agents.append(
                {
                    "id": entry.id,
                    "family": provider_family(entry.type, entry.model, entry.id),
                    "label": (entry.role or entry.description or entry.planner_hint or "")[:300],
                    "deterministic": (entry.type or "").lower() == "deterministic",
                    "vision": is_vision(entry.model, entry.type),
                    "model": (entry.model or "")[:200] or None,
                }
            )
        mcps = [m.id for m in catalog.mcps if m.id]
    # The worker's own MCPs (search, fetch, images) reach AO through the Reach tunnel.
    mcps.extend(sorted(CLIENT_MCP_IDS - set(mcps)))
    for raw in overlay:
        agents.append(
            {
                "id": str(raw["id"]),
                "family": provider_family(raw.get("type"), raw.get("model"), str(raw["id"])),
                "label": str(raw.get("role") or "")[:300],
                "deterministic": str(raw.get("type") or "").lower() == "deterministic",
                "vision": is_vision(raw.get("model"), raw.get("type")),
                "model": str(raw.get("model") or "")[:200] or None,
            }
        )
    return {"agents": agents, "mcps": mcps, "generated_at": generated_at}


def models_by_agent(catalog: Any | None, overlay: list[dict[str, Any]]) -> dict[str, str | None]:
    out: dict[str, str | None] = {}
    if catalog is not None:
        out.update({e.id: e.model for e in catalog.agents})
    out.update({str(a["id"]): a.get("model") for a in overlay})
    return out


def force_ipv4_for(host: str) -> None:
    """Resolve ``host`` to IPv4 addresses only, in every aiohttp connector created after this.

    AO Reach builds its own aiohttp connectors, so the switch sits in aiohttp's default
    resolver. Other hosts keep normal resolution. Used when a stale IPv6 or dual-stack
    answer for the AO host exists on the worker machine (AOQ_AO_IPV4_ONLY=1).
    """
    import socket

    import aiohttp.connector as connector

    name = host.lower()
    current = connector.DefaultResolver
    hosts = getattr(current, "aoq_ipv4_hosts", None)
    if hosts is not None:
        hosts.add(name)
        return

    class IPv4ForHosts(current):  # type: ignore[misc, valid-type]
        aoq_ipv4_hosts = {name}

        async def resolve(self, host: str, port: int = 0, family: int = socket.AF_INET) -> Any:
            if family == socket.AF_UNSPEC and host.lower() in self.aoq_ipv4_hosts:
                family = socket.AF_INET
            return await super().resolve(host, port, family)

    connector.DefaultResolver = IPv4ForHosts


def reach_config(settings: WorkerSettings, config: dict[str, Any] | None = None) -> Any:
    from urllib.parse import urlparse

    from ao_reach.connection_config import ReachConnectionConfig
    from ao_reach.mtls import ReachMtlsConfig

    if settings.ao_ipv4_only:
        force_ipv4_for(urlparse(settings.ao_url).hostname or "")

    kwargs: dict[str, Any] = {
        "base_url": settings.ao_url,
        "app_id": APP_ID,
        "headers": dict(settings.ao_headers),
        "ttl_seconds": 86400,  # long runs on a slow AO must not lose the session overlay
        "question_id_prefix": "aoq",
        "dynamic_planning": False,
    }
    if settings.ao_mtls_pem:
        cert, key, ca = settings.ao_mtls_pem
        kwargs["mtls"] = ReachMtlsConfig(client_cert_pem=cert, client_key_pem=key, ca_pem=ca)
    elif settings.ao_mtls_dir:
        kwargs["mtls"] = ReachMtlsConfig(material_dir=settings.ao_mtls_dir)
    if config is not None:
        mcp = [config["mcp"]["search"], config["mcp"]["fetch"]]
        if config["mcp"].get("stock_images"):
            mcp.append(config["mcp"]["stock_images"])
        kwargs["allowed_agent_provider_ids"] = sorted(set(config["agents"].values()))
        kwargs["allowed_mcp_provider_ids"] = sorted(set(mcp))
        kwargs["allowed_skill_ids"] = None
    return ReachConnectionConfig(**kwargs)


async def enroll(
    *, ao_url: str, token: str, material_dir: str, ca_pem: str, common_name: str = "ao-quill-worker", ipv4_only: bool = False
) -> None:
    """One-time mTLS enrollment: AO signs a client certificate for this worker.

    The server is verified against ``ca_pem`` (AO's CA) with hostname checks on, so a
    machine answering on the network in AO's place cannot take the token.
    """
    import socket
    import ssl
    from urllib.parse import urlparse

    import aiohttp
    from ao_reach.mtls_enroller import ReachMtlsEnroller

    if ipv4_only:
        force_ipv4_for(urlparse(ao_url).hostname or "")
    ctx = ssl.create_default_context(cadata=ca_pem)
    family = socket.AF_INET if ipv4_only else 0
    async with aiohttp.ClientSession(connector=aiohttp.TCPConnector(ssl=ctx, family=family)) as session:
        await ReachMtlsEnroller(session=session).enroll(
            base_url=ao_url.rstrip("/"), enroll_token=token, material_dir=material_dir, common_name=common_name, ca_pem=ca_pem
        )


async def fetch_catalog(settings: WorkerSettings) -> Any:
    from ao_reach.catalog_client import ReachCatalogClient

    return await ReachCatalogClient().fetch(reach_config(settings))


def _bridge_class() -> type:
    from ao_reach.session_bridge import SessionBridge

    class VersionAwareBridge(SessionBridge):  # type: ignore[misc, valid-type]
        """Keeps the engine version from the hello frame, which SessionBridge drops."""

        engine_version: str | None = None
        chunk_listener: Any = None  # set by AOClient during a call to receive AO output live

        def _on_run_chunk(self, msg: dict[str, Any]) -> None:
            super()._on_run_chunk(msg)
            listener = self.chunk_listener
            if listener is not None and str(msg.get("stream") or "stdout") == "stdout":
                text = str(msg.get("text") or "")
                if text:
                    try:
                        listener(text)
                    except Exception:  # noqa: BLE001 - output display must never break a run
                        pass

        def _on_message(self, raw: str | bytes) -> None:  # noqa: D401
            try:
                msg = json.loads(raw)
                if isinstance(msg, dict) and msg.get("type") == "hello":
                    self.engine_version = str(msg.get("version") or "") or None
            except (TypeError, ValueError):
                pass
            super()._on_message(raw)

    return VersionAwareBridge


async def open_bridge(settings: WorkerSettings, config: dict[str, Any], mcp_servers: Any = None) -> Any:
    """Connect, register the overlay agents and, when given, the worker-hosted MCPs (D14)."""
    if not (Path(settings.overlay_dir) / "agent_providers").is_dir():
        raise AOError("connect", f"overlay directory not found: {settings.overlay_dir}")
    bridge = _bridge_class()()
    await bridge.start(config=reach_config(settings, config), overlay_root=settings.overlay_dir, mcp_bootstrap=mcp_servers)
    return bridge
