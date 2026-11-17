# Operations

## Reading the run log

Network Admin, Settings, AO Quill, Run log. One row per run: date, trigger (scheduled or manual), status, links to the posts, and the failure reason. Open a run to see every stage attempt with duration, the AO agents and models used, scores against thresholds, revision count and failure reason.

Statuses:

| Status | Meaning |
|---|---|
| claimed | The worker took the run and has not reported yet |
| running | Stages are reporting |
| succeeded | Posts were committed in every language |
| failed | A gate or error stopped the run; nothing was written |
| expired | The worker stopped reporting before the lease ran out |

Common failure reasons:

| Reason starts with | What to do |
|---|---|
| `Configuration problems` | Fix what the Worker and AO status tab lists |
| `connect: AO does not provide` | Install what is missing on the AO server (`ao-server/INSTALL.md`) |
| `verify_quotes: Only N claims` | Sources did not contain the quoted text. Usually transient; lower the minimum only if it keeps happening |
| `qa: QA below threshold` | The draft did not pass after the revision limit. Check the scores; consider a stronger drafting agent before lowering thresholds |
| `images: Only N images passed` | The media library has too few fitting images without text, logos, watermarks or people |
| `wordpress: commit-post` | The plugin refused or rolled back the commit; the message says why |

## Re-running

The schedule runs once per day and does not retry a failed run. To retry today, press Run now on the Worker and AO status tab; the next worker poll (within a minute) starts it. There is no daily cap: Run now always starts a run (after any run in progress finishes), and the scheduled run happens once a day whatever other runs or posts were made that day.

## Changing the run time

Change it in Settings. The next poll uses it; nothing needs to be redeployed.

## Rolling back a post

AI posts are only ever created, never edited, by AO Quill. To remove one:

1. Open the run in the Run log and follow the language links.
2. Trash the post on every language site. Filter the posts list by Origin, AI generated, to find them.
3. Copied images are attachments with `_aoq_run_id` set to the run ID; delete them from each site's media library if no longer needed.
4. The translation link rows stay in the network table and are harmless; they no longer match any post.

## Status panel

Worker and AO status shows the last worker heartbeat and versions, whether AO was reachable, the AO engine version and capabilities from the last run, problems blocking a run, the capability status table, and the exact settings used on the last run.

## Worker logs

The worker logs to stdout. A poll with nothing due prints `{"status": "idle"}`; a run prints its run ID, status and reason.
