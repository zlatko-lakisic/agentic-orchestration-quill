"""AO connection from WordPress (D16): environment wins, certificate written privately."""

from __future__ import annotations

import os
import stat
import sys

import pytest

from aoq_worker import connection, contracts
from aoq_worker.settings import SettingsError, WorkerSettings

PEM_CERT = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n"
PEM_KEY = "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n"


def base(**kw) -> WorkerSettings:
    return WorkerSettings(wp_url="https://wordpress.example.com", wp_user="aoq-worker", wp_app_password="x", ao_url=kw.pop("ao_url", ""), **kw)


def conn(**kw) -> dict:
    out = {
        "ao_url": "https://ao.example.internal:8765",
        "ao_ipv4_only": True,
        "searxng_url": "https://search.example.internal",
        "user_agent": "ao-quill-worker (+https://wordpress.example.com/)",
        "mtls": {"cert_pem": PEM_CERT, "key_pem": PEM_KEY, "ca_pem": PEM_CERT},
    }
    out.update(kw)
    return out


def test_contract_accepts_the_shape():
    assert contracts.errors(conn(), "abilities/get-ao-connection.output") == []
    assert contracts.errors(conn(ao_url="http://ao.example.internal"), "abilities/get-ao-connection.output")


def test_wordpress_fills_what_the_environment_left_empty(tmp_path):
    s = connection.apply(base(), conn(), cache_dir=tmp_path / "mtls")
    assert s.ao_url == "https://ao.example.internal:8765"
    assert s.ao_ipv4_only and s.searxng_url == "https://search.example.internal"
    assert s.ao_mtls_dir == str(tmp_path / "mtls")
    assert (tmp_path / "mtls" / "key.pem").read_text() == PEM_KEY
    if sys.platform != "win32":
        assert stat.S_IMODE(os.stat(tmp_path / "mtls" / "key.pem").st_mode) == 0o600
        assert stat.S_IMODE(os.stat(tmp_path / "mtls").st_mode) == 0o700


def test_environment_wins(tmp_path):
    s = connection.apply(base(ao_url="https://pinned.example.internal:1", ao_mtls_dir="/pinned"), conn(), cache_dir=tmp_path / "m")
    assert s.ao_url == "https://pinned.example.internal:1"
    assert s.ao_mtls_dir == "/pinned"
    assert not (tmp_path / "m").exists()


def test_no_ao_url_anywhere_is_an_error(tmp_path):
    with pytest.raises(SettingsError):
        connection.apply(base(), conn(ao_url=None, mtls=None), cache_dir=tmp_path)


def test_wordpress_url_user_and_password_are_enough():
    s = WorkerSettings.from_env({"AOQ_WP_URL": "https://wordpress.example.com", "AOQ_WP_USER": "aoq-worker", "AOQ_WP_APP_PASSWORD": "p"})
    assert s.ao_url == ""
