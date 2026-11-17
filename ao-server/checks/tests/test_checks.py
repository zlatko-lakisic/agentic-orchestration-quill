import json

from aoq_ao_checks import quotes, textchecks
from aoq_ao_checks.common import parse_input

PAGE = "<html><body><script>var x='skip me';</script><p>The Stone Bridge was completed in 1566 &amp; rebuilt in 2004.</p><p>It spans the “Silver” river.</p></body></html>"


def fake_fetch(url):
    if "missing" in url:
        raise OSError("404")
    from aoq_ao_checks.common import html_to_text

    return html_to_text(PAGE), 200


def test_exact_quote_verified_and_paraphrase_rejected():
    out = quotes.verify(
        {
            "sources": [{"source_id": "s1", "url": "https://example.org/a"}, {"source_id": "s2", "url": "https://example.org/missing"}],
            "claims": [
                {"claim_id": "c1", "source_id": "s1", "supporting_quote": "was completed in 1566 & rebuilt in 2004"},
                {"claim_id": "c2", "source_id": "s1", "supporting_quote": 'It spans the "Silver" river.'},
                {"claim_id": "c3", "source_id": "s1", "supporting_quote": "was finished in the year 1566"},
                {"claim_id": "c4", "source_id": "s2", "supporting_quote": "anything at all here"},
                {"claim_id": "c5", "source_id": "s1", "supporting_quote": "1566"},
                {"claim_id": "c6", "source_id": "s1", "supporting_quote": "var x='skip me'"},
                {"claim_id": "c7", "source_id": "s1", "supporting_quote": "the stone bridge was completed"},
            ],
        },
        fetch=fake_fetch,
    )
    verdict = {c["claim_id"]: c["verified"] for c in out["claims"]}
    assert verdict == {"c1": True, "c2": True, "c3": False, "c4": False, "c5": False, "c6": False, "c7": False}
    assert out["sources"][1]["fetched"] is False


def test_markdown_in_quote_is_tolerated():
    out = quotes.verify(
        {"sources": [{"source_id": "s1", "url": "https://example.org/a"}], "claims": [{"claim_id": "c1", "source_id": "s1", "supporting_quote": "The **Stone Bridge** was [completed](https://x) in 1566"}]},
        fetch=fake_fetch,
    )
    assert out["claims"][0]["verified"] is True


def test_entrypoint_parses_task_header():
    payload = {"sources": [], "claims": []}
    assert parse_input("AO Quill task: verify_quotes\n\n" + json.dumps(payload)) == payload
    assert json.loads(quotes.verify_quotes("AO Quill task: x\n\n" + json.dumps(payload))) == {"sources": [], "claims": []}


def test_structure_checks():
    good = textchecks.structure({"title": "T", "seo_title": "T", "seo_description": "d" * 140, "content_html": "<h2>A</h2><h3>B</h3><h2>C</h2>"})
    assert good["heading_order_ok"] and good["problems"] == [] and good["score"] == 1.0
    bad = textchecks.structure({"title": "T" * 90, "seo_title": "T", "seo_description": "short", "content_html": "<h1>X</h1><h2>A</h2><h4>B</h4>"})
    assert bad["h1_count"] == 1 and not bad["heading_order_ok"] and bad["score"] < 0.5


def test_originality_overlap():
    text = "one two three four five six seven eight nine ten"
    out = textchecks.originality(
        {"n": 5, "content_html": f"<p>{text}</p>", "references": [{"id": "1:2", "text": f"<p>{text}</p>"}, {"id": "1:3", "text": "unrelated words entirely here now"}], "source_urls": []}
    )
    assert out["max_overlap"] == 1.0 and out["worst_reference"] == "1:2"
    out = textchecks.originality({"n": 5, "content_html": f"<p>{text}</p>", "references": [{"id": "1:3", "text": "nothing in common at all really"}], "source_urls": ["https://example.org/missing"]}, fetch=fake_fetch)
    assert out["max_overlap"] == 0.0 and out["fetch_errors"]


def test_private_hosts_refused():
    import pytest

    from aoq_ao_checks.common import fetch_text

    with pytest.raises(ValueError):
        fetch_text("http://127.0.0.1/")
    with pytest.raises(ValueError):
        fetch_text("file:///etc/passwd")
    for url in ("http://0.0.0.0/", "http://[::ffff:10.20.30.40]/", "http://169.254.169.254/"):
        with pytest.raises(ValueError):
            fetch_text(url)


def test_redirects_to_private_hosts_are_refused():
    import pytest

    from aoq_ao_checks.common import _CheckedRedirects

    with pytest.raises(ValueError):
        _CheckedRedirects().redirect_request(None, None, 302, "Found", {}, "http://10.20.30.40/admin")
