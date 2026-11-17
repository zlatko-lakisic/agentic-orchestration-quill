"""Worker-hosted MCP tools (D14): address guard, license mapping, provider parsing,
the image ledger, and the MCP server as Reach's tunnel forwarder reaches it."""

from __future__ import annotations

import json

import pytest

from aoq_worker.mcp_host import CLIENT_IDS, SPECS, McpApp, WorkerMcpServers
from aoq_worker.web_tools import (
    PublicOnlyResolver,
    ToolError,
    WebTools,
    WebToolsConfig,
    check_public_url,
    commons_license_code,
    html_to_text,
    public_address,
)


def tools(searxng: str | None = None) -> WebTools:
    return WebTools(WebToolsConfig(searxng_url=searxng))


# ------------------------------------------------------------------ guard


@pytest.mark.parametrize(
    "addr, ok",
    [
        ("93.184.215.14", True),
        ("2606:4700::1111", True),
        ("10.0.0.5", False),
        ("192.168.1.1", False),
        ("172.16.3.4", False),
        ("127.0.0.1", False),
        ("169.254.169.254", False),
        ("::1", False),
        ("::ffff:10.0.0.1", False),
        ("fd00::1", False),
        ("0.0.0.0", False),
        ("224.0.0.1", False),
        ("not-an-ip", False),
    ],
)
def test_public_address(addr, ok):
    assert public_address(addr) is ok


class _Inner:
    def __init__(self, hosts):
        self.hosts = hosts

    async def resolve(self, host, port=0, family=0):
        return [{"hostname": host, "host": h, "port": port, "family": family, "proto": 0, "flags": 0} for h in self.hosts]

    async def close(self):
        pass


async def test_resolver_drops_private_answers():
    with pytest.raises(OSError):
        await PublicOnlyResolver(_Inner(["10.20.30.40"])).resolve("intranet.example.org", 443)
    mixed = await PublicOnlyResolver(_Inner(["10.20.30.40", "93.184.215.14"])).resolve("mixed.example.org", 443)
    assert [r["host"] for r in mixed] == ["93.184.215.14"]
    with pytest.raises(OSError):
        await PublicOnlyResolver(_Inner([])).resolve("127.0.0.1", 80)


@pytest.mark.parametrize(
    "url",
    [
        "file:///etc/passwd",
        "ftp://example.org/x",
        "https://user:pw@example.org/",
        "https:///nohost",
        # aiohttp skips the resolver for IP literals, so these must be refused up front
        "http://10.20.30.40/",
        "http://127.0.0.1:8080/",
        "http://[::1]/",
        "http://[::ffff:192.168.1.1]/",
        "http://169.254.169.254/latest/meta-data/",
    ],
)
def test_check_public_url_refuses(url):
    with pytest.raises(ToolError):
        check_public_url(url)


# ------------------------------------------------------------------ parsing


def test_html_to_text_skips_chrome():
    title, text = html_to_text(
        "<html><head><title>Bridge history</title><style>p{}</style></head><body><nav>Menu</nav>"
        "<p>The bridge  was built in 1566.</p><script>x()</script><p>It was rebuilt.</p><footer>Foot</footer></body></html>"
    )
    assert title == "Bridge history"
    assert text == "The bridge was built in 1566.\nIt was rebuilt."


@pytest.mark.parametrize(
    "raw, expected",
    [
        ("cc-by-sa-4.0", ("cc-by-sa", "4.0")),
        ("cc-by-2.0", ("cc-by", "2.0")),
        ("cc-by-3.0-de", ("cc-by", "3.0")),
        ("cc0", ("cc0", "")),
        ("pd", ("pdm", "")),
        ("cc-by-nc-2.0", None),
        ("gfdl", None),
        ("", None),
    ],
)
def test_commons_license_code(raw, expected):
    assert commons_license_code(raw) == expected


OPENVERSE = {
    "results": [
        {"id": "0a1b2c3d-1111-2222-3333-444455556666", "title": "Old bridge", "url": "https://live.staticflickr.com/1/a.jpg",
         "thumbnail": "https://api.openverse.org/v1/images/0a1b/thumb/", "foreign_landing_url": "https://www.flickr.com/photos/x/1",
         "license": "by", "license_version": "2.0", "license_url": "https://creativecommons.org/licenses/by/2.0/",
         "creator": "Jane Example", "attribution": '"Old bridge" by Jane Example is licensed under CC BY 2.0.', "width": 2048, "height": 1365},
        {"id": "nc-image", "title": "NC", "url": "https://example.org/nc.jpg", "foreign_landing_url": "https://example.org/nc",
         "license": "by-nc", "creator": "X", "attribution": "x"},
        {"id": "http-only", "title": "Plain http", "url": "http://example.org/a.jpg", "foreign_landing_url": "https://example.org/a",
         "license": "cc0"},
    ]
}

COMMONS = {
    "query": {
        "pages": {
            "4401": {"pageid": 4401, "title": "File:Stone bridge.jpg", "imageinfo": [{
                "url": "https://upload.wikimedia.org/a/ab/Stone_bridge.jpg", "thumburl": "https://upload.wikimedia.org/thumb/800px.jpg",
                "descriptionurl": "https://commons.wikimedia.org/wiki/File:Stone_bridge.jpg", "mime": "image/jpeg", "width": 4000, "height": 3000,
                "extmetadata": {"License": {"value": "cc-by-sa-4.0"}, "LicenseUrl": {"value": "https://creativecommons.org/licenses/by-sa/4.0"},
                                "Artist": {"value": "<a href=\"//commons.wikimedia.org/wiki/User:Jane\">Jane Example</a>"}}}]},
            "4402": {"pageid": 4402, "title": "File:Map.svg", "imageinfo": [{
                "url": "https://upload.wikimedia.org/map.svg", "mime": "image/svg+xml", "extmetadata": {"License": {"value": "cc0"}}}]},
            "4403": {"pageid": 4403, "title": "File:No author.jpg", "imageinfo": [{
                "url": "https://upload.wikimedia.org/na.jpg", "mime": "image/jpeg", "extmetadata": {"License": {"value": "cc-by-4.0"}}}]},
            "4404": {"pageid": 4404, "title": "File:Public.jpg", "imageinfo": [{
                "url": "https://upload.wikimedia.org/pd.jpg", "descriptionurl": "https://commons.wikimedia.org/wiki/File:Public.jpg",
                "mime": "image/jpeg", "extmetadata": {"License": {"value": "pd"}}}]},
        }
    }
}


async def test_image_search_keeps_only_reusable_licenses_and_fills_the_ledger(monkeypatch):
    t = tools()

    async def fake_get(url, max_bytes):
        body = OPENVERSE if "openverse" in url else COMMONS
        return json.dumps(body).encode(), "application/json", url

    monkeypatch.setattr(t, "_get_public", fake_get)
    out = await t.image_search("stone bridge", 10)
    ids = [i["image_id"] for i in out["images"]]
    assert set(ids) == {"openverse:0a1b2c3d-1111-2222-3333-444455556666", "commons:4401", "commons:4404"}
    assert ids[0].startswith("openverse:") and ids[1].startswith("commons:")  # providers interleaved
    ov = t.ledger.get("openverse:0a1b2c3d-1111-2222-3333-444455556666")
    assert ov.license["code"] == "cc-by" and ov.license["name"] == "CC BY 2.0" and ov.attribution.startswith('"Old bridge"')
    cm = t.ledger.get("commons:4401")
    assert cm.license["code"] == "cc-by-sa" and cm.author == "Jane Example"
    assert cm.attribution == '"Stone bridge.jpg" by Jane Example, CC BY-SA 4.0, via Wikimedia Commons'
    assert t.ledger.get("commons:4404").license["requires_attribution"] is False
    # tool output carries no URLs the model could swap; the ledger holds them
    assert all("url" not in i for i in out["images"])


async def test_fetch_image_refuses_ids_not_in_the_ledger():
    with pytest.raises(ToolError):
        await tools().fetch_image("openverse:never-returned")


async def test_web_search_needs_searxng():
    with pytest.raises(ToolError):
        await tools().web_search("bridge")


async def test_fetch_url_refuses_private_targets_before_connecting():
    t = tools()
    try:
        for url in ("http://127.0.0.1:8080/", "http://10.20.30.40/", "http://[::1]/", "http://169.254.169.254/latest/meta-data/"):
            with pytest.raises(ToolError):
                await t.fetch_url(url)
    finally:
        await t.close()


# ------------------------------------------------------------------ MCP server


class StubTools:
    def __init__(self):
        self.calls = []

    async def web_search(self, query, max_results=8, language=""):
        self.calls.append(("web_search", query))
        return {"query": query, "results": [{"title": "T", "url": "https://example.org/", "snippet": "S"}]}

    async def fetch_url(self, url, max_chars=20000):
        raise ToolError("Could not fetch")

    async def image_search(self, query, max_results=12):
        return {"query": query, "images": [], "errors": []}


async def test_mcp_protocol_round_trip():
    stub = StubTools()
    app = McpApp(SPECS[0], stub)  # type: ignore[arg-type]
    init = await app.handle_message({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-03-26"}})
    assert init["result"]["protocolVersion"] == "2025-03-26"
    assert init["result"]["capabilities"] == {"tools": {"listChanged": False}}
    assert await app.handle_message({"jsonrpc": "2.0", "method": "notifications/initialized"}) is None
    listed = await app.handle_message({"jsonrpc": "2.0", "id": 2, "method": "tools/list"})
    assert [t["name"] for t in listed["result"]["tools"]] == ["web_search"]
    called = await app.handle_message({"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "web_search", "arguments": {"query": "bridge"}}})
    assert called["result"]["isError"] is False
    assert json.loads(called["result"]["content"][0]["text"])["results"][0]["url"] == "https://example.org/"
    other = await app.handle_message({"jsonrpc": "2.0", "id": 4, "method": "tools/call", "params": {"name": "fetch_url", "arguments": {}}})
    assert other["error"]["code"] == -32602  # each alias serves only its own tool
    missing = await app.handle_message({"jsonrpc": "2.0", "id": 5, "method": "resources/list"})
    assert missing["error"]["code"] == -32601


async def test_tool_errors_go_back_to_the_agent():
    app = McpApp(SPECS[1], StubTools())  # type: ignore[arg-type]
    res = await app.handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "fetch_url", "arguments": {"url": "https://example.org"}}})
    assert res["result"]["isError"] is True and "Could not fetch" in res["result"]["content"][0]["text"]


async def test_reach_tunnel_forwarding_reaches_the_loopback_servers():
    from ao_reach.local_mcp_host import LocalMcpHost

    servers = WorkerMcpServers(StubTools())  # type: ignore[arg-type]
    host = LocalMcpHost()
    off = await servers.prepare(host, mcp_tunnel=False)
    assert off.mcps == [] and off.warnings and not host.is_running
    boot = await servers.prepare(host, mcp_tunnel=True)
    try:
        assert {m["id"] for m in boot.mcps} == CLIENT_IDS
        assert all(m["streamable_http"]["url"].startswith("tunnel://session-mcp/aoq_") for m in boot.mcps)
        body = json.dumps({"jsonrpc": "2.0", "id": 7, "method": "tools/call", "params": {"name": "web_search", "arguments": {"query": "bridge"}}}).encode()
        status, headers, out = await host.forward(alias="aoq_search", method="POST", path="/mcp", headers={"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}, body=body)
        assert status == 200 and "Mcp-Session-Id" in headers
        assert json.loads(out)["result"]["structuredContent"]["query"] == "bridge"
        note = json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}).encode()
        status, _, _ = await host.forward(alias="aoq_search", method="POST", path="/", headers={"Content-Type": "application/json"}, body=note)
        assert status == 202
        status, _, _ = await host.forward(alias="aoq_images", method="GET", path="/mcp", headers={}, body=b"")
        assert status == 405
    finally:
        await servers.stop()


async def test_tool_calls_are_reported_for_the_run_output():
    seen = []
    app = McpApp(SPECS[0], StubTools(), on_call=lambda *a: seen.append(a))  # type: ignore[arg-type]
    await app.handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "web_search", "arguments": {"query": "stari most"}}})
    app2 = McpApp(SPECS[1], StubTools(), on_call=lambda *a: seen.append(a))  # type: ignore[arg-type]
    await app2.handle_message({"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": {"name": "fetch_url", "arguments": {"url": "https://example.org"}}})
    assert seen == [("web_search", "stari most", "1 results"), ("fetch_url", "https://example.org", "error: Could not fetch")]


async def test_tools_accept_the_agent_frameworks_security_context():
    # CrewAI adds security_context to every call and rejects undeclared arguments.
    from aoq_worker.web_tools import TOOLS

    for name, tool in TOOLS.items():
        assert "security_context" in tool["inputSchema"]["properties"], name
        assert "type" not in tool["inputSchema"]["properties"]["security_context"], "untyped so nested data passes"
    app = McpApp(SPECS[0], StubTools())  # type: ignore[arg-type]
    res = await app.handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "web_search", "arguments": {"query": "q", "security_context": {"agent_fingerprint": {"uuid_str": "x"}}}}})
    assert res["result"]["isError"] is False


class ScriptedSearch(WebTools):
    """WebTools whose single SearXNG request is scripted; sleeps are recorded, not taken."""

    def __init__(self, answers):
        super().__init__(WebToolsConfig(searxng_url="https://searx.example.org"))
        self.answers = list(answers)
        self.requests = 0
        self.slept: list[float] = []

        async def sleep(seconds):
            self.slept.append(seconds)

        self.sleep = sleep

    async def _search_once(self, query, language):
        self.requests += 1
        return self.answers.pop(0)


HIT = [{"title": "Blagaj Tekke", "url": "https://example.org/tekke", "snippet": "A dervish house."}]
BLOCKED = [["duckduckgo", "CAPTCHA"], ["google cse", "too many requests"]]


async def test_empty_results_while_engines_are_blocked_are_retried_after_a_pause():
    t = ScriptedSearch([([], BLOCKED), (HIT, BLOCKED)])
    out = await t.web_search("Blagaj tekke")
    assert out["results"] == HIT and t.requests == 2 and t.slept == [8.0]


async def test_blocked_searches_give_up_with_a_note_after_the_retries():
    t = ScriptedSearch([([], BLOCKED)] * 3)
    out = await t.web_search("Blagaj tekke")
    assert out["results"] == [] and "rate-limiting" in out["note"] and t.slept == [8.0, 15.0]


async def test_empty_results_without_blocked_engines_are_not_retried():
    t = ScriptedSearch([([], [])])
    out = await t.web_search("nothing about this anywhere")
    assert out["results"] == [] and "note" not in out and t.requests == 1


async def test_repeated_queries_in_a_run_use_the_cache():
    t = ScriptedSearch([(HIT * 5, [])])
    first = await t.web_search("Blagaj Tekke", max_results=3)
    again = await t.web_search("  blagaj tekke ", max_results=2)
    assert t.requests == 1 and len(first["results"]) == 3 and len(again["results"]) == 2


async def test_tunnel_searches_stay_inside_aos_60_second_tool_limit():
    from aoq_worker.web_tools import SEARCH_REQUEST_TIMEOUT, TOOL_RETRY_DELAYS, TOOL_SEARCH_BUDGET

    worst = (1 + len(TOOL_RETRY_DELAYS)) * SEARCH_REQUEST_TIMEOUT + sum(TOOL_RETRY_DELAYS)
    assert TOOL_SEARCH_BUDGET < 60
    # The budget check stops retries that could not finish in time, even with slow requests.
    t = ScriptedSearch([([], BLOCKED)] * 3)
    clock = {"now": 0.0}

    async def slow_once(query, language):
        t.requests += 1
        clock["now"] += SEARCH_REQUEST_TIMEOUT
        return t.answers.pop(0)

    async def sleep(seconds):
        t.slept.append(seconds)
        clock["now"] += seconds

    t._search_once = slow_once
    t.sleep = sleep
    import asyncio

    loop = asyncio.get_running_loop()
    real_time = loop.time
    base = real_time()
    loop.time = lambda: base + clock["now"]
    try:
        await t.web_search("Blagaj tekke")
    finally:
        loop.time = real_time
    assert clock["now"] <= TOOL_SEARCH_BUDGET < worst + 1


async def test_patient_searches_wait_longer_and_blocked_empty_answers_are_not_cached():
    t = ScriptedSearch([([], BLOCKED)] * 3 + [(HIT, [])])
    out = await t.search_patiently("Blagaj tekke")
    assert out["results"] == [] and t.slept == [20.0, 60.0]
    again = await t.web_search("Blagaj tekke")
    assert again["results"] == HIT, "a blocked, empty answer is not cached"
