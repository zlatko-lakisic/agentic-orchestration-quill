"""``aoq-worker language-site``: turn a site of the network into a translated copy of the base site
(docs/plans/new-language-site.md). WordPress work runs through WP-CLI and the plugin's
``tools/language-site.php``; every translation is done by AO's translate agent.

Steps: check, backup, language, export, translate, apply, words, link, verify. Re-running is safe:
what was copied is updated, and translations are cached by the hash of their English text, so only
new or changed texts go to AO again.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Any, Awaitable, Callable

STEPS = ["check", "backup", "language", "export", "translate", "apply", "words", "link", "verify"]
WEIGHTS = {"check": 2, "backup": 3, "language": 5, "export": 2, "translate": 70, "apply": 8, "words": 2, "link": 3, "verify": 5}
BATCH_ITEMS, BATCH_CHARS, HTML_CHUNK = 25, 2500, 3500
TRANSLATOR_AGENT = "client.aoq_translator"  # overlays/agent_providers/aoq_translator.yaml (TranslateGemma)
SKELETON = re.compile(r"<[^>]+>|\[[^\]\s][^\]]*\]")

STRINGS_PROMPT = """Translate the text of every item in the context from English into {lang}, for the website of a
tour guide agency in Mostar, Bosnia and Herzegovina. Keep names of people, places and landmarks in their
local form (Stari most, Kujundžiluk, Blagaj, Počitelj), the brand "Tour Guide Mostar", prices, numbers,
emoji, phone numbers, e-mail addresses and URLs. A text that is not English (a guest's quote in another
language) is returned unchanged. Labels stay as short as the English (menu items, buttons, tab names).
Give back every key with its translated text."""

HTML_PROMPT = """Translate the visible text of the HTML in the context from English into {lang}, for the website of a
tour guide agency in Mostar, Bosnia and Herzegovina. Return the same HTML: every tag with all its
attributes, every HTML comment (<!-- ... -->) and every shortcode in square brackets stays exactly as it
is and in the same order; only the text between tags is translated. Keep names of people, places and
landmarks in their local form, the brand "Tour Guide Mostar", prices, numbers, emoji, phone numbers and
e-mail addresses. Text that is not English stays unchanged. Do not add or leave out anything."""


class Progress:
    """Overall percentage across weighted steps, and a 'waiting on AO' ticker while a call runs."""

    def __init__(self, steps: list[str]) -> None:
        self.total = sum(WEIGHTS[s] for s in steps)
        self.done = 0.0
        self.step = ""
        self.tty = sys.stdout.isatty()
        self.started = time.monotonic()

    def pct(self) -> int:
        return min(100, int(100 * self.done / max(1, self.total)))

    def say(self, msg: str) -> None:
        if self.tty:
            sys.stdout.write("\r\033[K")
        print(f"[{self.pct():3d}%] {self.step}: {msg}", flush=True)

    def begin(self, step: str) -> None:
        self.step = step

    def advance(self, amount: float) -> None:
        self.done += amount

    async def wait_ao(self, what: str, call: Awaitable[Any]) -> Any:
        task = asyncio.ensure_future(call)
        start = time.monotonic()
        last = 0.0
        while True:
            done, _ = await asyncio.wait({task}, timeout=2)
            if done:
                if self.tty:
                    sys.stdout.write("\r\033[K")
                    sys.stdout.flush()
                return task.result()
            waited = time.monotonic() - start
            line = f"[{self.pct():3d}%] {self.step}: waiting on AO ({int(waited)} s) - {what}"
            if self.tty:
                sys.stdout.write("\r\033[K" + line[: shutil.get_terminal_size((120, 20)).columns - 1])
                sys.stdout.flush()
            elif waited - last >= 30:
                print(line, flush=True)
                last = waited


class WP:
    """WP-CLI on this server, for the target site."""

    def __init__(self, path: str, url: str, tool: Path) -> None:
        import shlex

        self.path, self.url, self.tool = path, url, tool
        # WP-CLI as the web server's user when wp-config.php is readable only by it, for example
        # AOQ_WP_CLI="sudo -n -u www-data /usr/local/bin/wp" (with a sudoers rule for that command).
        self.wp = shlex.split(os.environ.get("AOQ_WP_CLI") or "wp")

    def cli(self, *args: str, url: str | None = None, check: bool = True) -> str:
        cmd = [*self.wp, f"--path={self.path}", f"--url={url or self.url}", *args]
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=3600)
        if check and res.returncode != 0:
            raise RuntimeError(f"{' '.join(cmd[: len(self.wp) + 3])} ... failed: {(res.stderr or res.stdout)[-800:]}")
        return res.stdout

    def tool_call(self, action: str, options: dict, url: str | None = None) -> dict:
        with tempfile.TemporaryDirectory(prefix="aoq-ls-") as tmp:
            src, out = Path(tmp) / "in.json", Path(tmp) / "out.json"
            src.write_text(json.dumps(options, ensure_ascii=False), encoding="utf-8")
            out.write_text("", encoding="utf-8")
            # WP-CLI may run as another user (AOQ_WP_CLI): it reads in.json and writes out.json.
            os.chmod(tmp, 0o711)
            os.chmod(src, 0o644)
            os.chmod(out, 0o666)
            self.cli("eval-file", str(self.tool), action, str(src), str(out), url=url)
            data = json.loads(out.read_text(encoding="utf-8") or "{}")
        if isinstance(data, dict) and data.get("error"):
            raise RuntimeError(data["error"])
        return data


def _hash(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()[:16]


def skeleton(html: str) -> list[str]:
    return SKELETON.findall(html)


def chunks(html: str, limit: int = HTML_CHUNK) -> list[str]:
    """Split long HTML at block boundaries (blank lines), never inside a tag."""
    if len(html) <= limit:
        return [html]
    parts, cur = [], ""
    for block in re.split(r"(\n\n)", html):
        if cur and len(cur) + len(block) > limit:
            parts.append(cur)
            cur = ""
        cur += block
    if cur:
        parts.append(cur)
    return parts


def gemma_prompt(lang: str, code: str, text: str, src: str = "English", src_code: str = "en") -> str:
    """TranslateGemma's own prompt; the model expects exactly this form, with two blank lines."""
    # Without this line the model drops the placeholders (all 9 kept with it, 0 without; tested).
    keep = "Keep every placeholder such as {1} exactly as it is, in place. " if PLACEHOLDER.search(text) else ""
    return (
        f"You are a professional {src} ({src_code}) to {lang} ({code}) translator. Your goal is to accurately "
        f"convey the meaning and nuances of the original {src} text while adhering to {lang} grammar, "
        f"vocabulary, and cultural sensitivities.\n"
        f"Produce only the {lang} translation, without any additional explanations or commentary. "
        f"{keep}Please translate the following {src} text into {lang}:\n\n\n<{MARK}>\n{text}\n</{MARK}>"
    )


# AO wraps every call in its own instructions, which TranslateGemma translates too; the text is
# marked with a tag the model keeps, and the answer is what it put inside that tag.
MARK = "aoq-text"
MARKED = re.compile(rf"<{MARK}>\s*(.*?)\s*</{MARK}>", re.S)


BLOCKS = re.compile(r"\n\s*\n")


def unmark(answer: str, source: str) -> str:
    """The translation inside the mark; when the model left the mark out, as many blank-line
    separated blocks as the source has (AO's instructions come after a blank line)."""
    m = MARKED.search(answer)
    if m:
        return m.group(1).strip()
    answer = re.sub(rf"</?{MARK}>", "", answer).strip()
    n = len(BLOCKS.split(source.strip()))
    return "\n\n".join(BLOCKS.split(answer)[:n]).strip()


PLACEHOLDER = re.compile(r"\{(\d+)\}")


def mask(html: str) -> tuple[str, list[str]]:
    """HTML with every tag, comment and shortcode replaced by {0}, {1}, ... in order."""
    held: list[str] = []

    def hold(m: re.Match) -> str:
        held.append(m.group(0))
        return "{%d}" % (len(held) - 1)

    return SKELETON.sub(hold, html), held


def unmask(text: str, held: list[str]) -> str | None:
    """The placeholders put back; None unless every one is there exactly once and in order."""
    found = [int(n) for n in PLACEHOLDER.findall(text)]
    if found != list(range(len(held))):
        return None
    return PLACEHOLDER.sub(lambda m: held[int(m.group(1))], text)


def _tag_name(token: str) -> str:
    m = re.match(r"<\s*(/?\s*[\w:-]+|!--)", token) or re.match(r"\[\s*(/?[\w-]+)", token)
    return m.group(1).replace(" ", "").lower() if m else token


def restore_skeleton(got: str, want: list[str]) -> str | None:
    """Put the source's tags back when the model kept the same tags in the same order but changed an
    attribute (TranslateGemma sometimes adds class="..."). None when the structure itself differs."""
    have = skeleton(got)
    if len(have) != len(want) or [_tag_name(t) for t in have] != [_tag_name(t) for t in want]:
        return None
    it = iter(want)
    return SKELETON.sub(lambda _m: next(it), got)


def same_ending(source: str, out: str) -> str:
    """TranslateGemma ends titles and labels with a full stop the English does not have."""
    if out.endswith(".") and not out.endswith("..") and not source.rstrip().endswith((".", "!", "?", "…")):
        return out[:-1].rstrip()
    return out


def _clean(answer: str) -> str:
    text = answer.strip()
    fence = re.fullmatch(r"```[\w-]*\n(.*)\n```", text, re.S)
    return fence.group(1).strip() if fence else text


class AOOutage(Exception):
    """AO stayed unreachable through every reconnect (the session bridge dropped)."""


class GemmaFailed(Exception):
    """TranslateGemma could not translate a text, and the writer agent is not to take over."""


# Waits before each reconnect when the AO session bridge drops (night of 2026-11-15: one dropped
# bridge ended the German run). About 18 minutes in all, then the post is given up.
RECONNECT_WAITS = (30, 60, 120, 300, 600)


class Translator:
    """TranslateGemma through the aoq_translator agent; the writer's JSON path when that agent fails.

    A dropped AO connection is not an agent failure: the call waits, reconnects (``recover``) and
    is made again, and AOOutage is raised when AO stays away, with TranslateGemma still in use."""

    def __init__(
        self, client: Any, lang: str, progress: Progress, code: str = "", gemma: bool = True,
        recover: Callable[[], Awaitable[None]] | None = None, waits: tuple[float, ...] = RECONNECT_WAITS,
        task: str = "", source: tuple[str, str] = ("English", "en"), fallback: bool = True,
    ) -> None:
        self.client, self.lang, self.p = client, lang, progress
        self.source = source  # (name, code) of the language translated from
        # False: GemmaFailed instead of the writer agent's JSON path (a run's own fallback decides).
        self.fallback = fallback
        self.code = code or lang
        self.gemma = gemma and "translator" in getattr(client, "agents", {})
        self.calls = 0
        self.recover, self.waits = recover, waits
        self.task = task  # every call under this one task name (the daily post: translate:<lang>)
        self.records: list[Any] = []  # AO call records, for a run's stage report

    def _task(self, kind: str) -> str:
        return self.task or f"language-site:{kind}"

    def _outage(self, exc: Exception) -> bool:
        bridge = getattr(self.client, "bridge", None)
        return "bridge is not active" in str(exc) or (bridge is not None and getattr(bridge, "is_active", True) is False)

    async def _ao(self, what: str, make: Callable[[], Awaitable[Any]]) -> Any:
        """One AO call; ``make`` builds it again for each try, on the reconnected bridge."""
        for n in range(len(self.waits) + 1):
            try:
                return await self.p.wait_ao(what, make())
            except Exception as exc:
                if not self._outage(exc):
                    raise
                if n == len(self.waits):
                    raise AOOutage(str(exc)[:300]) from exc
                self.p.say(f"AO connection lost ({str(exc)[:150]}); reconnecting in {self.waits[n]:g} s")
                await asyncio.sleep(self.waits[n])
                if self.recover is not None:
                    try:
                        await self.recover()
                        self.p.say("reconnected to AO")
                    except Exception as again:  # noqa: BLE001 - the next try reports it
                        self.p.say(f"reconnect failed: {str(again)[:200]}")
        raise AssertionError("unreachable")

    async def _json(self, task: str, prompt: str, context: dict, schema: str, what: str) -> dict:
        self.calls += 1
        body = json.dumps(context, ensure_ascii=False)
        res = await self._ao(what, lambda: self.client.json_call("translate", task, prompt, body, schema))
        self.records.extend(getattr(res, "records", None) or [])
        return res.data

    async def _gemma(self, task: str, text: str, what: str) -> str | None:
        """One text through TranslateGemma; None when the agent failed (the JSON path takes over)."""
        if not self.gemma:
            return None
        for attempt in range(2):
            self.calls += 1
            prompt = gemma_prompt(self.lang, self.code, text, *self.source)
            try:
                answer, rec = await self._ao(what, lambda: self.client.text_call("translator", task, prompt))
                if rec is not None:
                    self.records.append(rec)
            except AOOutage:
                raise
            except Exception as exc:  # noqa: BLE001 - the agent itself failed: use the JSON path from now on
                self.p.say(f"translation agent failed ({str(exc)[:200]}); using the writer agent instead")
                self.gemma = False
                return None
            out = unmark(_clean(answer), text)
            if out:
                return _clean(out)
        return None

    async def strings(self, items: list[dict], what: str) -> dict[str, str]:
        if self.gemma:
            got: dict[str, str] = {}
            for n, it in enumerate(items, 1):
                out = await self._gemma(self._task("text"), it["text"], f"{what} ({n} of {len(items)})")
                if out is None and not self.gemma:
                    break
                if out:
                    got[it["key"]] = same_ending(it["text"], out)
            if self.gemma or len(got) == len(items):
                return got
            if not self.fallback:
                raise GemmaFailed("TranslateGemma stopped answering")
            rest = await self._strings_json([i for i in items if i["key"] not in got], what)
            return {**got, **rest}
        return await self._strings_json(items, what)

    async def _strings_json(self, items: list[dict], what: str) -> dict[str, str]:
        data = await self._json(
            self._task("strings"),
            STRINGS_PROMPT.format(lang=self.lang),
            {"items": [{"key": i["key"], "text": i["text"]} for i in items]},
            "string_batch",
            what,
        )
        got = {x["key"]: x["text"] for x in data["items"] if x.get("key") and isinstance(x.get("text"), str)}
        return {i["key"]: got[i["key"]] for i in items if got.get(i["key"], "").strip()}

    async def html(self, key: str, text: str, what: str) -> str:
        out = []
        parts = chunks(text)
        for n, part in enumerate(parts, 1):
            label = what + (f" (part {n} of {len(parts)})" if len(parts) > 1 else "")
            out.append(await self._html_part(part, label))
        return "".join(out)

    async def _html_part(self, part: str, what: str) -> str:
        if not re.search(r"[^\W\d_]{2,}", re.sub(r"<[^>]+>", " ", part)):
            return part
        want = skeleton(part)
        # Through AO, TranslateGemma drops HTML tags and translates shortcodes, so it gets the text
        # with every tag, comment and shortcode replaced by a numbered placeholder.
        masked, held = mask(part)
        for attempt in range(2):
            got = await self._gemma(self._task("html"), masked, what + (" (again)" if attempt else ""))
            if got is None:
                break
            back = unmask(got, held)
            if back is not None and skeleton(back) == want:
                return back
        if not self.gemma and not self.fallback:
            raise GemmaFailed(f"TranslateGemma failed on {what}")
        error = ""
        for attempt in range(0 if self.gemma else 2):
            ctx = {"html": part}
            if error:
                ctx["previous_problem"] = error
            data = await self._json(self._task("html"), HTML_PROMPT.format(lang=self.lang), ctx, "html_translation", what)
            got = data["html"]
            if skeleton(got) == want:
                return got
            error = "The tags, comments or shortcodes changed; keep every one exactly as in the source and in the same order."
        # Fallback: translate the text between tags only, so the markup cannot change.
        tokens = re.split(r"(<[^>]+>|\[[^\]\s][^\]]*\])", part)
        idx = [i for i, t in enumerate(tokens) if not SKELETON.fullmatch(t or "") and re.search(r"[^\W\d_]{2,}", t or "")]
        items = [{"key": str(i), "text": tokens[i]} for i in idx]
        for batch in _batches(items):
            done = await self.strings(batch, what + " (text by text)")
            for k, v in done.items():
                lead = re.match(r"^\s*", tokens[int(k)]).group(0)
                trail = re.search(r"\s*$", tokens[int(k)]).group(0)
                tokens[int(k)] = lead + v.strip() + trail
        return "".join(tokens)


def _batches(items: list[dict]) -> list[list[dict]]:
    out, cur, size = [], [], 0
    for it in items:
        if cur and (len(cur) >= BATCH_ITEMS or size + len(it["text"]) > BATCH_CHARS):
            out.append(cur)
            cur, size = [], 0
        cur.append(it)
        size += len(it["text"])
    if cur:
        out.append(cur)
    return out


class Deadline:
    """--until: no new text is started after this time (the one in progress is finished)."""

    def __init__(self, at: float) -> None:
        self.at, self.stopped = at, False

    @classmethod
    def parse(cls, value: str) -> "Deadline | None":
        if not value:
            return None
        from datetime import datetime

        when = datetime.fromisoformat(value)
        if when.tzinfo is None:
            raise SystemExit("--until needs a time zone, for example 2026-11-15T08:00:00-05:00")
        return cls(when.timestamp())


def out_of_time(deadline: "Deadline | None", p: Progress) -> bool:
    if deadline is None or deadline.stopped:
        return bool(deadline and deadline.stopped)
    if time.time() >= deadline.at:
        deadline.stopped = True
        p.say("the --until time is reached; no more texts are started")
    return deadline.stopped


def _cache_file(url: str, locale: str) -> Path:
    base = Path(os.environ.get("XDG_DATA_HOME") or Path.home() / ".local/share") / "ao-quill-worker" / "language-sites"
    base.mkdir(parents=True, exist_ok=True)
    slug = re.sub(r"[^a-z0-9]+", "-", url.lower()).strip("-")
    return base / f"{slug}-{locale}.json"


def _describe(key: str) -> str:
    kind, _, rest = key.partition(":")
    return {"trip": "tour", "page": "page", "mod": "home page setting", "term": "category", "widget": "widget", "menu": "menu", "wte": "booking label", "ui": "interface word", "testimonial": "testimonial", "post": "blog post", "site": "site title", "pkgcat": "price category"}.get(kind, kind) + " " + rest


async def run(settings: Any, args: Any) -> int:
    from . import ao_client, busy as busy_mod, connection
    from .ao_client import AOClient
    from .cli import _deps
    from .pipeline import heartbeat, models_by_agent
    from .wp_client import WordPressClient

    wp_path = args.wp_path or os.environ.get("AOQ_WP_PATH", "")
    if not wp_path or not Path(wp_path, "wp-config.php").exists():
        print("Pass --wp-path (the WordPress folder) or set AOQ_WP_PATH.", file=sys.stderr)
        return 2
    async with busy_mod.BusyHeartbeat("language_site", detail=(args.url or "")[:300]):
        return await _run_language_site(settings, args, wp_path)


async def _run_language_site(settings: Any, args: Any, wp_path: str) -> int:
    from . import ao_client, connection
    from .ao_client import AOClient
    from .cli import _deps
    from .pipeline import heartbeat, models_by_agent
    from .wp_client import WordPressClient

    tool = Path(wp_path) / "wp-content/plugins/ao-quill/tools/language-site.php"
    wp = WP(wp_path, args.url, tool)
    steps = STEPS if args.step == "all" else [args.step]
    p = Progress(steps)
    cache_path = _cache_file(args.url, args.locale)
    cache: dict = json.loads(cache_path.read_text(encoding="utf-8")) if cache_path.exists() else {}
    options = {"locale": args.locale, "posts": args.posts}
    deadline = Deadline.parse(getattr(args, "until", "") or "")
    outage = ""  # set when AO stayed away: what is translated is still copied, exit code 6

    p.begin("check")
    info = wp.tool_call("check", options)
    lang = info["language_name"]
    p.say(f"target {info['target_url']} (now {info['locale']}) from base {info['base_url']}, language {lang}")
    p.say("target has " + ", ".join(f"{k} {v}" for k, v in info["target"].items()))
    own = info["target"]["page"] + info["target"]["trip"] + info["target"]["post"] - info["target"]["from_base"]
    if "check" in steps:
        p.advance(WEIGHTS["check"])
    if own > 5 and not args.force and args.step == "all":
        p.say(f"the target has {own} posts, pages or tours of its own; they are kept, matching pages are updated. Run again with --force to continue.")
        return 3
    if args.step == "all" and not args.yes:
        reply = input(f"Make {info['target_url']} a {lang} copy of {info['base_url']}? [y/N] ").strip().lower()
        if reply not in ("y", "yes"):
            return 1

    if "backup" in steps:
        p.begin("backup")
        tables = wp.cli("db", "tables", "--scope=blog", "--format=csv").strip()
        folder = Path.home() / "aoq-backups"
        folder.mkdir(exist_ok=True)
        dest = folder / f"site-{info['target_blog']}-{time.strftime('%Y%m%d-%H%M%S')}.sql"
        # Exported to stdout and written here: WP-CLI may run as a user that cannot write this folder.
        dump = wp.cli("db", "export", "-", f"--tables={tables}")
        if "CREATE TABLE" not in dump:
            raise RuntimeError("the database export came back empty; nothing was changed")
        dest.write_text(dump, encoding="utf-8")
        dest.chmod(0o600)
        subprocess.run(["gzip", str(dest)], check=True)
        p.advance(WEIGHTS["backup"])
        p.say(f"saved {dest}.gz")

    if "language" in steps:
        p.begin("language")
        if info["locale"] != args.locale:
            wp.cli("language", "core", "install", args.locale, check=False)
            wp.cli("site", "switch-language", args.locale)
            p.say(f"site language set to {args.locale}")
        for kind in ("plugin", "theme"):
            wp.cli("language", kind, "install", "--all", args.locale, check=False)
        p.advance(WEIGHTS["language"])
        p.say("WordPress, theme and plugin language packs installed where they exist")

    if not ({"export", "translate", "apply", "words"} & set(steps)):
        items = []
    else:
        p.begin("export")
        exported = wp.tool_call("export", options)
        items = exported["items"]
        if "export" in steps:
            p.advance(WEIGHTS["export"])
        if args.posts:
            p.say(f"blog posts: {exported.get('posts', 0)} to translate, {exported.get('posts_skipped', 0)} newer ones skipped (already translated)")
        p.say(f"{len(items)} texts to translate")

    translations: dict[str, str] = {}
    if "translate" in steps or "apply" in steps or "words" in steps:
        todo = []
        for it in items:
            hit = cache.get(it["key"])
            if hit and hit.get("src") == _hash(it["text"]):
                translations[it["key"]] = hit["text"]
            else:
                todo.append(it)
        if "translate" in steps:
            p.begin("translate")
            p.say(f"{len(items) - len(todo)} cached, {len(todo)} to translate with AO")
            unit = WEIGHTS["translate"] / max(1, sum(len(i["text"]) for i in todo))
            if todo:
                async with WordPressClient(settings) as wpc:
                    settings = await connection.load(settings, wpc)
                    deps = _deps(settings, wpc)
                    hb, catalog, overlay = await heartbeat(deps, fresh=True)
                    if not hb["ao"]["reachable"]:
                        p.say(f"AO is not reachable: {hb['ao']['error']}")
                        return 4
                    config = await wpc.call("get-config", {})
                    bridge = await deps.open_bridge(config)

                    async def recover() -> None:
                        # The session bridge does not reconnect by itself: open a new one.
                        nonlocal bridge
                        try:
                            await bridge.stop()
                        except Exception:  # noqa: BLE001 - it is already gone
                            pass
                        bridge = await deps.open_bridge(config)
                        client.bridge = bridge

                    try:
                        agents = dict(config["agents"])
                        if any(str(a.get("id", "")).endswith("aoq_translator") for a in overlay):
                            agents["translator"] = TRANSLATOR_AGENT
                        client = AOClient(bridge, agents=agents, models=models_by_agent(catalog, overlay), priority=config["ao_priority"], timeout=float(config["stage_timeout_seconds"]))
                        tr = Translator(client, lang, p, code=args.locale.split("_")[0], gemma=not args.writer, recover=recover)
                        p.say("translating with " + (f"{TRANSLATOR_AGENT} ({client.models.get(TRANSLATOR_AGENT)})" if tr.gemma else agents["translate"]))

                        def save(key: str, src: str, text: str) -> None:
                            translations[key] = text
                            cache[key] = {"src": _hash(src), "text": text}
                            cache_path.write_text(json.dumps(cache, ensure_ascii=False, indent=0), encoding="utf-8")

                        async def short(batch: list[dict], what: str) -> None:
                            done = await tr.strings(batch, what)
                            for it in batch:
                                if it["key"] in done:
                                    save(it["key"], it["text"], done[it["key"]])
                                p.advance(unit * len(it["text"]))

                        async def long(it: dict, what: str) -> None:
                            save(it["key"], it["text"], await tr.html(it["key"], it["text"], what))
                            p.advance(unit * len(it["text"]))

                        # The site's own texts first, then blog posts one whole post at a time, so a
                        # run stopped by --until leaves complete posts that the apply step copies.
                        site = [i for i in todo if not i["key"].startswith("post:")]
                        posts: dict[str, list[dict]] = {}
                        for i in todo:
                            if i["key"].startswith("post:"):
                                posts.setdefault(i["key"].split(":")[1], []).append(i)
                        try:
                            for batch in _batches([i for i in site if not i["html"]]):
                                if out_of_time(deadline, p):
                                    break
                                what = f"{len(batch)} short texts ({_describe(batch[0]['key'])} ...)"
                                await short(batch, what)
                                p.say(f"translated {what}")
                            for it in [i for i in site if i["html"]]:
                                if out_of_time(deadline, p):
                                    break
                                await long(it, _describe(it["key"]))
                                p.say(f"translated {_describe(it['key'])}")
                        except AOOutage as exc:
                            outage = str(exc)
                            posts = {}
                        skipped_in_a_row = 0
                        for n, (pid, its) in enumerate(posts.items(), 1):
                            if out_of_time(deadline, p):
                                break
                            started = time.monotonic()
                            try:
                                plain = [i for i in its if not i["html"]]
                                if plain:
                                    await short(plain, f"blog post {pid} title")
                                for it in [i for i in its if i["html"]]:
                                    await long(it, f"blog post {pid}")
                            except AOOutage as exc:
                                # Skipped, not lost: the next run translates it (parts done are cached).
                                skipped_in_a_row += 1
                                p.say(f"blog post {pid} ({n} of {len(posts)}) skipped: AO stayed unreachable ({str(exc)[:150]})")
                                if skipped_in_a_row >= 2:
                                    outage = str(exc)
                                    break
                                continue
                            skipped_in_a_row = 0
                            took = int(time.monotonic() - started)
                            if "apply" in steps and f"post:{pid}:content" in translations:
                                # Published as soon as it is translated, not at the end of the run.
                                try:
                                    res = wp.tool_call("post", {**options, "post_ids": [int(pid)], "translations": translations})
                                    url = (res.get("posts") or {}).get(str(pid), {}).get("url", "")
                                    p.say(f"blog post {pid} ({n} of {len(posts)}) translated in {took} s and published: {url}")
                                except Exception as exc:  # noqa: BLE001 - the apply step copies it later
                                    p.say(f"blog post {pid} ({n} of {len(posts)}) translated in {took} s; publishing failed ({str(exc)[:200]}), the apply step tries again")
                            else:
                                p.say(f"translated blog post {pid} ({n} of {len(posts)}) in {took} s")
                    finally:
                        await bridge.stop()
            p.say(f"done: {len(translations)} of {len(items)} texts in {lang} ({tr.calls if todo else 0} AO calls)")

    if "apply" in steps:
        p.begin("apply")
        p.say("copying settings, media, tours, pages, menus and widgets, with the translations")
        res = wp.tool_call("apply", {**options, "translations": translations})
        for line in res.get("log", []):
            p.say(line)
        p.advance(WEIGHTS["apply"])

    if "words" in steps:
        p.begin("words")
        res = wp.tool_call("mo", {**options, "translations": translations})
        p.say(json.dumps(res, ensure_ascii=False))
        p.advance(WEIGHTS["words"])

    if "link" in steps:
        p.begin("link")
        res = wp.tool_call("link", options)
        p.advance(WEIGHTS["link"])
        p.say(f"{res.get('linked', 0)} pages and tours linked to the base site; language menus updated on every language site")

    if outage:
        p.done = p.total
        p.say(f"stopped: AO stayed unreachable after reconnecting ({outage[:200]}); what was translated is copied, run again to continue")
        return 6

    if deadline and deadline.stopped:
        p.done = p.total
        p.say(f"stopped at the --until time after {int(time.monotonic() - p.started)} s; what was translated is copied, run again to continue")
        return 5

    if "verify" in steps:
        p.begin("verify")
        pairs = wp.tool_call("pages", options)
        problems = await _verify(pairs, settings, p)
        p.advance(WEIGHTS["verify"])
        p.say(f"{len(pairs)} pages compared, {len(problems)} differences")
        for line in problems:
            print("   - " + line)

    p.done = p.total
    p.say(f"finished in {int(time.monotonic() - p.started)} s. Visitors may see cached pages for up to 30 minutes.")
    return 0


async def _verify(pairs: list[dict], settings: Any, p: Progress) -> list[str]:
    import aiohttp

    def shape(html: str) -> dict:
        return {
            "sections": len(re.findall(r"<section\b", html)),
            "h2": len(re.findall(r"<h2\b", html)),
            "images": len(re.findall(r"<img\b", html)),
            "menu items": len(re.findall(r'class="[^"]*menu-item\b', html)),
        }

    out = []
    async with aiohttp.ClientSession(headers={"User-Agent": getattr(settings, "user_agent", None) or "ao-quill-worker"}) as http:
        for pair in pairs:
            got = {}
            for side in ("base", "target"):
                async with http.get(pair[side] + ("&" if "?" in pair[side] else "?") + "nocache=1") as res:
                    got[side] = (res.status, shape(await res.text()))
            if got["target"][0] != 200:
                out.append(f"{pair['target']}: HTTP {got['target'][0]}")
                continue
            diff = [f"{k} {got['base'][1][k]} -> {got['target'][1][k]}" for k in ("sections", "h2", "menu items") if got["base"][1][k] != got["target"][1][k]]
            if diff:
                out.append(f"{pair['target']}: " + ", ".join(diff))
    return out
