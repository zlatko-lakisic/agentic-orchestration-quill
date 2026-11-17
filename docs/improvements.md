# Improvements backlog

Ideas worth doing that are not scheduled yet. Each entry says what, why, and what to watch for.

## QA: do not re-run checks that already passed

**What:** in a QA revision round, re-run only the checks that failed in the previous round (and the cheap
deterministic structure and originality checks, which run on the AO server in about a second). Checks that
already passed (for example grammar, style, SEO) are not asked again in every round.

**Why:** with phi4 each model check takes 20 to 50 seconds, so a round of four model checks costs 2 to 4
minutes. In test run df73a0e9 grammar, style and SEO passed in every one of the four rounds; only facts and
originality failed, so most of each round's time went to re-confirming checks that were already fine.

**Watch for:** a revision can break something that passed (a rewritten sentence can introduce a grammar
error, cutting unsupported sentences can push SEO length or structure out of range). Options:

- Re-run every check once more on the final draft only, before the run moves on, so the post that is
  committed has passed everything in its final form.
- Or re-run a passed check when the revision changed a large share of the text (for example more than a
  quarter of its sentences), measured by a text diff in the worker.

Keep the deterministic checks every round: they are fast and catch structural damage from a revision.
