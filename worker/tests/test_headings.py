"""Heading hierarchy: H2 for main parts only (post 5647 had one H2 per dish)."""

from __future__ import annotations

from types import SimpleNamespace

from aoq_worker.stages.draft import insert_section
from aoq_worker.stages.qa import heading_check


def _ctx(html):
    return SimpleNamespace(draft={"content_html": html})


def test_a_flat_list_of_h2_fails_and_a_hierarchy_passes():
    flat = "".join(f"<h2>Dish {i}</h2><p>Text.</p>" for i in range(9))
    res = heading_check(_ctx(flat))
    assert not res.passed and res.issues and "<h3>" in res.issues[0]["fix"]
    five_no_h3 = "".join(f"<h2>Part {i}</h2><p>Text.</p>" for i in range(5))
    assert not heading_check(_ctx(five_no_h3)).passed
    nested = "<h2>Must-try dishes</h2>" + "".join(f"<h3>Dish {i}</h3><p>Text.</p>" for i in range(5)) + "<h2>Where to eat</h2><p>Text.</p>"
    res = heading_check(_ctx(nested))
    assert res.passed and res.score == 1.0


def test_expansion_goes_under_the_named_h2():
    post = "<p>Intro.</p><h2>Must-try dishes</h2><h3>Ćevapi</h3><p>a</p><h2>Where to eat</h2><p>b</p>"
    out = insert_section(post, "<h3>Klepe</h3><p>new</p>", "must-try  dishes")
    assert out.index("<h3>Klepe</h3>") < out.index("<h2>Where to eat</h2>") and out.index("<h3>Klepe</h3>") > out.index("<h3>Ćevapi</h3>")
    out = insert_section(post, "<h2>Markets</h2><p>new</p>", "")
    assert out.index("<h2>Markets</h2>") < out.index("<h2>Where to eat</h2>"), "a new main part goes before the last H2 as before"


def test_expansion_past_six_h2_nests_under_an_existing_section():
    from aoq_worker.stages.draft import insert_section

    post = "".join(f"<h2>Part {i}</h2><p>Text {i}.</p>" for i in range(1, 7))  # already six H2
    out = insert_section(post, "<h2>New part</h2><p>More.</p><h3>Detail</h3><p>Even more.</p>")
    assert out.count("<h2") == 6
    assert "<h3>New part</h3>" in out and "<h4>Detail</h4>" in out


def test_expansion_within_the_limit_keeps_its_h2():
    from aoq_worker.stages.draft import insert_section

    post = "<h2>Intro</h2><p>a</p><h2>End</h2><p>b</p>"
    out = insert_section(post, "<h2>Middle</h2><p>c</p>")
    assert out.count("<h2") == 3 and "<h2>Middle</h2>" in out


def test_normalize_heading_levels_demotes_h1_and_fills_skips():
    from aoq_worker.stages.draft import normalize_heading_levels

    html, n = normalize_heading_levels("<h1>Title</h1><p>a</p><h2>A</h2><h4>Skip</h4><p>b</p>")
    assert n >= 1
    assert "<h1" not in html.lower()
    assert "<h2>Title</h2>" in html or html.lower().startswith("<h2")
    assert "<h3>Skip</h3>" in html
    assert "<h4>Skip</h4>" not in html


def test_dedupe_h2_merges_duplicates_and_folds_title():
    from aoq_worker.stages.draft import dedupe_h2_sections

    html = (
        "<p>Intro.</p>"
        "<h2>Tips for Visiting</h2><p>One.</p>"
        "<h2>Tips for Visiting</h2><p>Two.</p>"
        "<h2>Exploring Sarajevo’s Culinary Markets</h2><p>Repeat intro.</p>"
        "<h2>Historical Significance</h2><p>Keep.</p>"
    )
    out, detail = dedupe_h2_sections(html, title="Exploring Sarajevo’s Culinary Markets")
    assert detail["merged"] == 1
    assert detail["folded_title"] == 1
    assert out.lower().count("<h2") == 2
    assert "One." in out and "Two." in out and "Repeat intro." in out
    assert "Exploring Sarajevo" not in out.split("<h2>")[1]  # title H2 removed


def test_insert_section_demotes_duplicate_h2_titles():
    post = "<h2>Tips for Visiting</h2><p>a</p><h2>Where to eat</h2><p>b</p>"
    out = insert_section(post, "<h2>Tips for Visiting</h2><p>more tips</p><h2>Markets</h2><p>c</p>", "")
    assert out.count("<h2>Tips for Visiting</h2>") == 1
    assert "<h3>Tips for Visiting</h3>" in out
    assert "<h2>Markets</h2>" in out


def test_heading_structure_problems_are_mechanical():
    from aoq_worker.stages.draft import _needs_rewrite
    from aoq_worker.stages.qa import PassResult

    structure = PassResult(
        "structure",
        0.6,
        0.8,
        False,
        [{"problem": "Headings skip a level or do not start at H2.", "fix": "fix"}],
    )
    assert not _needs_rewrite([structure])
    other = PassResult("structure", 0.6, 0.8, False, [{"problem": "The body has no headings.", "fix": "fix"}])
    assert _needs_rewrite([other])
