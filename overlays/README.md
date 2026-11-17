# Session overlay

The worker registers these agents with AO Reach as `client.aoq_*` for the length of a run. Each one is a role, short instructions, a harness profile and a model reference; the model always runs on the AO server.

| Agent | Default model | Used for |
|---|---|---|
| `aoq_analyst` | `qwen2.5:14b-instruct` | Site profile, style profiles, topics, novelty, research, outline, taxonomy, image shortlist |
| `aoq_writer` | `qwen2.5:14b-instruct` | Draft, revisions, translation |
| `aoq_editor` | `phi4:latest` | QA passes, back translation, translation QA |
| `aoq_structurer` | `qwen2.5:14b-instruct` | Turning tool and vision output into JSON |
| `aoq_vision` | `gemma4:e4b` | Image judging |

Notes:

- The editor must stay on a different provider family than the writer. The plugin refuses settings that break this.
- AO's JSON mode ignores agent role and backstory (`docs/ao-gaps.md` item 4), so the instructions that matter are in the worker's prompts. The text here applies to tool and vision calls.
- Any agent from the AO server catalog can replace these per stage in the plugin settings. Prefer catalog agents when they fit.

Every agent sets `chat_timeout_sec: 3600`: AO's default per-call Ollama limit is 600 s, too short for a slow GPU host. The structurer uses a non-thinking model (`qwen2.5:14b-instruct`) because it only reformats text into JSON; thinking models spend most of their time reasoning, and AO's JSON path cannot switch thinking off.
