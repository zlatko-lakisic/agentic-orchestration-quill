#!/usr/bin/env bash
# Nightly translation of the base site's blog posts into the language sites, inside a time window.
# Each night it continues where the last one stopped: posts already translated are skipped.
#
# Settings in ~/.config/aoq/nightly.env:
#   SITES="https://example.com/de/=de_DE https://example.com/ar/=ar"   # done in this order
#   WINDOW_TZ="America/New_York"   WINDOW_END="08:00"                   # start: 00:xx in WINDOW_TZ
#   PAUSE_UTC="07:00"   # AO Quill's daily post (Schedule time, UTC); the translation pauses for it
#   POSTS=100000        # posts per language per night (default: as many as fit)
#
# Cron (both lines; the script runs only when it is 00:xx in WINDOW_TZ, so summer time works):
#   0 4,5 * * * <home>/translate-nightly.sh >> <home>/translate-nightly.log 2>&1
# After a reboot inside the window it carries on (install.sh --cron adds this when nightly.env exists):
#   @reboot sleep 120; <home>/translate-nightly.sh --resume >> <home>/translate-nightly.log 2>&1
# Run now, outside the window:  translate-nightly.sh --now
set -uo pipefail
HOME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="${XDG_CONFIG_HOME:-$HOME/.config}/aoq/nightly.env"
[ -f "$CONF" ] || { echo "missing $CONF"; exit 2; }
set -a; . "$CONF"; set +a
WINDOW_TZ="${WINDOW_TZ:-America/New_York}" WINDOW_END="${WINDOW_END:-08:00}" PAUSE_UTC="${PAUSE_UTC:-}" POSTS="${POSTS:-100000}"
[ -n "${SITES:-}" ] || { echo "SITES is empty in $CONF"; exit 2; }

MODE="${1:-}"
case "$MODE" in
  --now) ;;
  --resume)
    # After a reboot (cron @reboot): carry on only when the server came back inside tonight's window
    # (it rebooted at 09:01 UTC, mid-window, three days running and nothing restarted the work).
    [ "$(date +%s)" -lt "$(TZ="$WINDOW_TZ" date -d "$WINDOW_END" +%s)" ] || exit 0 ;;
  *) [ "$(TZ="$WINDOW_TZ" date +%H)" = "00" ] || exit 0 ;;
esac
exec 8>"$HOME_DIR/translate-nightly.lock"
flock -n 8 || { echo "$(date -u +%FT%TZ) already running"; exit 0; }

log() { echo "$(date -u +%FT%TZ) $*"; }
[ "$MODE" = --resume ] && log "server started inside the window: resuming"
end=$(TZ="$WINDOW_TZ" date -d "$WINDOW_END" +%s)
[ "$end" -gt "$(date +%s)" ] || end=$(TZ="$WINDOW_TZ" date -d "tomorrow $WINDOW_END" +%s)
iso() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%S+00:00; }

# One part of the night: every site in turn until the time is up (exit 5) or all are done.
part() {
  local until="$1" pair url locale rc
  for pair in $SITES; do
    url="${pair%%=*}" locale="${pair##*=}"
    [ "$(date +%s)" -lt "$until" ] || return 5
    log "translating $url ($locale) until $(iso "$until")"
    "$HOME_DIR/run.sh" language-site --url "$url" --locale "$locale" --posts "$POSTS" --until "$(iso "$until")" --yes --force
    rc=$?
    log "$url: exit $rc"
    [ "$rc" = 5 ] && return 5
  done
  return 0
}

pause=""
if [ -n "$PAUSE_UTC" ]; then
  pause=$(date -u -d "$PAUSE_UTC" +%s)
  [ "$pause" -gt "$(date +%s)" ] || pause=$(date -u -d "tomorrow $PAUSE_UTC" +%s)
  [ "$pause" -lt "$end" ] || pause=""
fi
log "night window until $(iso "$end")${pause:+, pause for the daily post at $(iso "$pause")}"
if [ -n "$pause" ]; then
  part $((pause - 300))
  rc=$?
  # Let the cron poll start the daily post; run.sh then waits for it to finish.
  now=$(date +%s); [ "$now" -lt $((pause + 120)) ] && sleep $((pause + 120 - now))
  [ "$rc" = 5 ] && part "$end"
else
  part "$end"
fi
# Keep a week of the nightly backups.
find "$HOME/aoq-backups" -name 'site-*.sql.gz' -mtime +7 -delete 2>/dev/null || true
log "night done"
