"""Shared-worker busy presence (D34)."""

from __future__ import annotations

from aoq_worker import busy


def test_busy_payload_shape():
    p = busy.busy_payload("tgme", "post_run", "abcd1234 qa", started_at=1_700_000_000)
    assert p["site"] == "tgme"
    assert p["task"] == "post_run"
    assert "qa" in p["detail"]
    assert p["started_at"].endswith("Z")
    assert p["updated_at"].endswith("Z")


def test_load_sibling_endpoints_from_env_files(tmp_path, monkeypatch):
    main = tmp_path / "worker.env"
    main.write_text(
        "AOQ_WP_URL='https://tourguidemostar.com'\nAOQ_WP_USER='aoq-worker'\nAOQ_WP_APP_PASSWORD='x x x x'\n",
        encoding="utf-8",
    )
    sites = tmp_path / "sites.d"
    sites.mkdir()
    (sites / "tgs.env").write_text(
        "AOQ_WP_URL='https://tourguidesarajevo.com'\nAOQ_WP_USER='aoq-worker'\nAOQ_WP_APP_PASSWORD='y y y y'\n",
        encoding="utf-8",
    )
    monkeypatch.setenv("AOQ_MAIN_ENV", str(main))
    monkeypatch.setenv("AOQ_SITES_DIR", str(sites))
    monkeypatch.delenv("AOQ_WP_URL", raising=False)
    endpoints = busy.load_sibling_endpoints()
    names = {e.name for e in endpoints}
    assert names == {"main", "tgs"}
    urls = {e.wp_url for e in endpoints}
    assert "https://tourguidemostar.com" in urls
    assert "https://tourguidesarajevo.com" in urls
