# AO Quill worker

Thin AO Reach client. It polls WordPress, runs the fixed pipeline against AO, applies gates, and hands one bundle to the plugin. It also serves web search, page fetch and licensed image search to AO through the Reach reverse MCP tunnel (`docs/decisions.md` D14); every model call still runs on AO. See `docs/architecture.md`.

```bash
python -m venv .venv && .venv/bin/pip install -e ".[dev]"
.venv/bin/aoq-worker check
.venv/bin/aoq-worker run --dry-run --out ./out
.venv/bin/pytest
```

Configuration comes from environment variables (`deploy/README.md`) and from the plugin.

The plugin zip bundles this folder (`scripts/build_plugin.py`); `install.sh` installs it on a server with a virtual environment, settings in `~/.config/aoq`, AO enrollment (`aoq-worker enroll --ca-file`) and an optional 15-minute cron entry. See `docs/setup.md`.
