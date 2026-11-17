"""Worker-hosted MCP servers, reached by AO through the Reach reverse tunnel (D14).

AO rewrites ``tunnel://session-mcp/<alias>`` to a loopback URL on the engine host and
relays each MCP HTTP request over the worker's own WebSocket. Reach hands the request
to ``LocalMcpHost.forward``, which posts it to ``127.0.0.1:<port>`` here. Nothing
listens on a non-loopback interface and AO never dials the worker.

Each MCP provider (search, fetch, images) is its own alias and port, so AO's catalog
and the WordPress settings can name them separately. The server is the minimal
streamable HTTP subset AO's client needs: JSON-RPC over POST with JSON responses
(no SSE stream; GET answers 405 as the spec allows).
"""

from __future__ import annotations

import json
import logging
import uuid
from dataclasses import dataclass
from typing import Any

from aiohttp import web

from . import APP_ID, __version__
from .web_tools import TOOLS, ToolError, WebTools, call_tool

LOG = logging.getLogger("aoq_worker.mcp")

PROTOCOL_VERSIONS = ("2025-06-18", "2025-03-26", "2024-11-05")


@dataclass(frozen=True)
class McpSpec:
    alias: str  # tunnel alias; the overlay ID is client.<alias>
    tool: str
    description: str

    @property
    def client_id(self) -> str:
        return f"client.{self.alias}"


SPECS = (
    McpSpec("aoq_search", "web_search", "AO Quill web search (SearXNG), served by the worker."),
    McpSpec("aoq_fetch", "fetch_url", "AO Quill page fetch with a public-address guard, served by the worker."),
    McpSpec("aoq_images", "image_search", "AO Quill licensed image search (Openverse, Wikimedia Commons), served by the worker."),
)
CLIENT_IDS = {s.client_id for s in SPECS}


def _result(req_id: Any, result: dict[str, Any]) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": req_id, "result": result}


def _error(req_id: Any, code: int, message: str) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": req_id, "error": {"code": code, "message": message}}


class McpApp:
    """One MCP server exposing one tool."""

    def __init__(self, spec: McpSpec, tools: WebTools, on_call: Any = None) -> None:
        self.spec = spec
        self.tools = tools
        self.on_call = on_call  # callable(tool, args, result_note): shows AO tool use in the run output
        self.session_id = uuid.uuid4().hex
        self.calls = 0

    async def handle_message(self, msg: Any) -> dict[str, Any] | None:
        if not isinstance(msg, dict) or msg.get("jsonrpc") != "2.0" or not isinstance(msg.get("method"), str):
            return _error(msg.get("id") if isinstance(msg, dict) else None, -32600, "Invalid request")
        method = msg["method"]
        req_id = msg.get("id")
        if req_id is None:  # notification (initialized, cancelled, ...)
            return None
        params = msg.get("params") if isinstance(msg.get("params"), dict) else {}
        if method == "initialize":
            asked = str(params.get("protocolVersion") or "")
            version = asked if asked in PROTOCOL_VERSIONS else PROTOCOL_VERSIONS[0]
            return _result(
                req_id,
                {
                    "protocolVersion": version,
                    "capabilities": {"tools": {"listChanged": False}},
                    "serverInfo": {"name": f"{APP_ID}-{self.spec.alias}", "version": __version__},
                    "instructions": self.spec.description,
                },
            )
        if method == "ping":
            return _result(req_id, {})
        if method == "tools/list":
            tool = TOOLS[self.spec.tool]
            return _result(req_id, {"tools": [{"name": self.spec.tool, **tool}]})
        if method == "tools/call":
            name = str(params.get("name") or "")
            args = params.get("arguments") if isinstance(params.get("arguments"), dict) else {}
            if name != self.spec.tool:
                return _error(req_id, -32602, f"Unknown tool {name}")
            self.calls += 1
            arg = str(args.get("query") or args.get("url") or "")[:200]
            LOG.info("AO called %s %r", name, arg)
            try:
                out = await call_tool(self.tools, name, args)
            except ToolError as exc:
                self._notify(name, arg, f"error: {exc}")
                return _result(req_id, {"content": [{"type": "text", "text": str(exc)}], "isError": True})
            except Exception as exc:  # noqa: BLE001 - tool failures go back to the agent, not up the stack
                LOG.warning("tool %s failed", name, exc_info=True)
                self._notify(name, arg, f"failed: {exc}")
                return _result(req_id, {"content": [{"type": "text", "text": f"{name} failed: {exc}"}], "isError": True})
            n = len(out.get("results") or out.get("images") or []) if isinstance(out, dict) else 0
            self._notify(name, arg, f"{n} results" if name != "fetch_url" else f"{len(str(out.get('text') or ''))} chars")
            return _result(
                req_id,
                {"content": [{"type": "text", "text": json.dumps(out, ensure_ascii=False)}], "structuredContent": out, "isError": False},
            )
        return _error(req_id, -32601, f"Method not found: {method}")

    def _notify(self, tool: str, arg: str, note: str) -> None:
        if self.on_call is not None:
            try:
                self.on_call(tool, arg, note)
            except Exception:  # noqa: BLE001 - display only
                pass

    async def http(self, request: web.Request) -> web.StreamResponse:
        if request.method == "GET":
            return web.Response(status=405, headers={"Allow": "POST"})
        if request.method == "DELETE":
            return web.Response(status=200)
        if request.method != "POST":
            return web.Response(status=405, headers={"Allow": "POST"})
        try:
            payload = json.loads(await request.read() or b"null")
        except ValueError:
            return web.json_response(_error(None, -32700, "Parse error"), status=400)
        headers = {"Mcp-Session-Id": self.session_id}
        if isinstance(payload, list):
            replies = [r for r in [await self.handle_message(m) for m in payload] if r is not None]
            if not replies:
                return web.Response(status=202, headers=headers)
            return web.json_response(replies, headers=headers)
        reply = await self.handle_message(payload)
        if reply is None:
            return web.Response(status=202, headers=headers)
        return web.json_response(reply, headers=headers)


class WorkerMcpServers:
    """Starts one loopback HTTP server per spec; used as a Reach SessionMcpBootstrap."""

    def __init__(self, tools: WebTools, specs: tuple[McpSpec, ...] = SPECS) -> None:
        self.tools = tools
        self.specs = specs
        self.apps: dict[str, McpApp] = {}
        self._runners: list[web.AppRunner] = []
        self.ports: dict[str, int] = {}
        self.tunnel_available: bool | None = None
        self.on_call: Any = None  # set by the pipeline to show tool use in the run output

    async def start(self) -> None:
        for spec in self.specs:
            if spec.alias in self.ports:
                continue
            mcp = McpApp(spec, self.tools, on_call=lambda *a: self.on_call and self.on_call(*a))
            app = web.Application(client_max_size=1024 * 1024)
            app.router.add_route("*", "/{tail:.*}", mcp.http)
            runner = web.AppRunner(app, access_log=None)
            await runner.setup()
            site = web.TCPSite(runner, "127.0.0.1", 0)
            await site.start()
            sock = site._server.sockets[0]  # type: ignore[union-attr]
            self.ports[spec.alias] = int(sock.getsockname()[1])
            self.apps[spec.alias] = mcp
            self._runners.append(runner)

    async def stop(self) -> None:
        for runner in self._runners:
            await runner.cleanup()
        self._runners.clear()
        self.ports.clear()
        self.apps.clear()

    def overlay_entries(self) -> list[dict[str, Any]]:
        return [
            {
                "id": s.client_id,
                "description": s.description,
                "streamable_http": {"url": f"tunnel://session-mcp/{s.alias}", "headers": {}},
            }
            for s in self.specs
        ]

    # Reach SessionMcpBootstrap protocol
    async def prepare(self, host: Any, *, mcp_tunnel: bool, config: Any = None) -> Any:
        from ao_reach.mcp_bootstrap import SessionMcpBootstrapResult

        self.tunnel_available = bool(mcp_tunnel)
        if not mcp_tunnel:
            return SessionMcpBootstrapResult(warnings=["AO has the MCP tunnel off (AGENTIC_SERVE_MCP_TUNNEL)."])
        await self.start()
        for alias, port in self.ports.items():
            host.attach_loopback_alias(alias, port)
        return SessionMcpBootstrapResult(mcps=self.overlay_entries(), active_tunnel_bare_ids=[s.alias for s in self.specs])
