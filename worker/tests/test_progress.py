"""Test runs from the Generation tab, live progress and AO output, catalog caching."""

from __future__ import annotations

from aoq_worker.pipeline import run_once

from .fakes import FakeBridge, FakeCatalog, FakeWP, deps, good_responses


class StreamingBridge(FakeBridge):
    """A fake bridge that streams a couple of output chunks during every call, like AO does."""

    chunk_listener = None

    async def direct_agent(self, **kw):
        if self.chunk_listener is not None:
            self.chunk_listener("(progress) thinking... ")
            self.chunk_listener("done. ")
        return await super().direct_agent(**kw)


async def run(wp, bridge=None, **kw):
    bridge = bridge or FakeBridge(good_responses())
    d = deps(wp, bridge)
    for k, v in kw.items():
        setattr(d, k, v)
    return await run_once(d), bridge


def progress_reports(wp):
    return [p for n, p in wp.calls if n == "report-progress"]


async def test_test_run_builds_a_preview_and_never_commits():
    wp = FakeWP(trigger="test")
    outcome, _ = await run(wp)
    assert outcome.status == "succeeded", outcome.reason
    assert "commit-post" not in wp.names()
    finish = dict(wp.calls)["finish-run"]
    assert finish["status"] == "succeeded"
    preview = finish["result"]["preview"]["posts"]
    assert [p["language"] for p in preview] == ["en", "bs"]
    assert all("{{c:" not in p["content"] for p in preview)


async def test_scheduled_run_sends_no_preview():
    wp = FakeWP()
    outcome, _ = await run(wp)
    assert outcome.status == "succeeded", outcome.reason
    assert dict(wp.calls)["finish-run"]["result"]["preview"] is None


async def test_progress_reaches_every_step_and_ends_before_finish():
    wp = FakeWP(trigger="test")
    await run(wp)
    reports = progress_reports(wp)
    assert reports, "no progress was reported"
    last = reports[-1]
    assert last["step"] == last["steps"] == 14 and last["label"] == "done"
    assert wp.names().index("finish-run") > max(i for i, n in enumerate(wp.names()) if n == "report-progress")


async def test_ao_output_is_forwarded_live_and_with_the_answer():
    wp = FakeWP(trigger="test")
    await run(wp, StreamingBridge(good_responses()))
    text = "".join(o["text"] for r in progress_reports(wp) for o in r["output"])
    tasks = {o["task"] for r in progress_reports(wp) for o in r["output"]}
    assert "(progress) thinking... done." in text  # streamed chunks
    assert "[answer after" in text  # the final answer of each call
    assert {"site_profile", "topics", "research"} <= tasks


async def test_idle_polls_reuse_the_catalog_and_runs_fetch_it_fresh(tmp_path):
    fetches = []
    cat = FakeCatalog()

    async def fetch():
        fetches.append(1)
        return cat

    idle = FakeWP(claim=False)
    for _ in range(3):
        await run(idle, catalog_cache=tmp_path / "catalog.json", fetch_catalog=fetch)
    assert len(fetches) == 1, "idle polls must reuse the cached catalog"
    assert all(p["ao"]["catalog"] for n, p in idle.calls if n == "claim-run")

    busy = FakeWP()
    outcome, _ = await run(busy, catalog_cache=tmp_path / "catalog.json", fetch_catalog=fetch)
    assert outcome.status == "succeeded", outcome.reason
    assert len(fetches) == 2, "a claimed run fetches the live catalog"


async def test_output_entries_carry_their_step():
    wp = FakeWP(trigger="test")
    await run(wp, StreamingBridge(good_responses()))
    entries = [o for r in progress_reports(wp) for o in r["output"]]
    steps = {o["step"] for o in entries}
    assert {"site_profile", "topics", "research", "draft"} <= steps
    assert all(o["step"] != "topics" or o["task"].startswith(("topics", "novelty")) for o in entries)
