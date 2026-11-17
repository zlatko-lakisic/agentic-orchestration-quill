#!/usr/bin/env bash
# AO Quill worker installer. Manual step, run once on the machine that will run the
# worker (it can be the WordPress server). Safe to run again: it upgrades the worker
# and keeps the existing settings.
#
#   bash install.sh --wp-url https://wordpress.example.com [--cron]
#   bash install.sh --add-site NAME --wp-url https://other.example.com [--wp-path /var/www/...]
#
# --add-site: one worker serves several sites on one AO server. Each poll asks every site in
# turn under one lock, so their runs never use AO at the same time.
#
# With --cron the worker polls every minute, so test runs started from the Test run
# tab begin within a minute. Idle polls cost one WordPress request; the AO catalog is
# refreshed at most every 15 minutes.
#
# The worker only needs the WordPress URL and an Application Password. The AO URL,
# search settings and the AO client certificate are kept in WordPress (Network Admin,
# AO Quill, AO connection) and read at every poll.
# Installs into ~/.local/share/ao-quill-worker (code, venv, logs) and ~/.config/aoq
# (settings, mode 600/700). Nothing is written inside the web root. The Application
# Password is read from a hidden prompt.
set -euo pipefail
umask 077

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOME_DIR="${AOQ_HOME:-${XDG_DATA_HOME:-$HOME/.local/share}/ao-quill-worker}"
CONFIG_DIR="${AOQ_CONFIG:-${XDG_CONFIG_HOME:-$HOME/.config}/aoq}"
WP_URL="" CRON=0 ADD_SITE="" WP_PATH=""

usage() { sed -n '2,13p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }
die() { echo "install: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --wp-url) WP_URL="$2"; shift 2 ;;
    --ao-url|--searxng-url|--user-agent|--ca-file|--ipv4-only)
      die "$1 is now set in WordPress: Network Admin, AO Quill, AO connection" ;;
    --cron) CRON=1; shift ;;
    --add-site) ADD_SITE="$2"; shift 2 ;;
    --wp-path) WP_PATH="$2"; shift 2 ;;
    --home) HOME_DIR="$2"; shift 2 ;;
    --config) CONFIG_DIR="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
done

ENV_FILE="$CONFIG_DIR/worker.env"

if [ -n "$ADD_SITE" ]; then
  # Another WordPress site for the installed worker (same AO server): its URL, worker user and
  # Application Password in sites.d/NAME.env. The worker then polls it with the main site.
  [[ "$ADD_SITE" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || die "site name: lowercase letters, digits and hyphens (for example tgs)"
  [ "$ADD_SITE" != main ] || die "\"main\" is the site in $ENV_FILE"
  [ -x "$HOME_DIR/run.sh" ] || die "install the worker first (install.sh --wp-url ...), then add sites"
  [ -n "$WP_URL" ] || die "--add-site needs --wp-url https://..."
  SITE_FILE="$CONFIG_DIR/sites.d/$ADD_SITE.env"
  mkdir -p "$CONFIG_DIR/sites.d"; chmod 700 "$CONFIG_DIR/sites.d"
  touch "$SITE_FILE"; chmod 600 "$SITE_FILE"
  q="'\\''"
  kv() { local tmp; tmp="$(mktemp "$CONFIG_DIR/sites.d/.env.XXXXXX")"; grep -v -E "^$1=" "$SITE_FILE" > "$tmp" || true; printf "%s='%s'\n" "$1" "${2//\'/$q}" >> "$tmp"; mv "$tmp" "$SITE_FILE"; chmod 600 "$SITE_FILE"; }
  kv AOQ_WP_URL "${WP_URL%/}"
  grep -q "^AOQ_WP_USER=" "$SITE_FILE" || kv AOQ_WP_USER aoq-worker
  kv AOQ_WP_PATH "$WP_PATH"  # set even when empty, so the main site's folder is never used for this one
  if ! grep -q "^AOQ_WP_APP_PASSWORD=" "$SITE_FILE"; then
    [ -t 0 ] || die "run it in a terminal: it asks for the site's Application Password"
    echo "Create it on that site with: wp user application-password create aoq-worker \"ao-quill worker\" --porcelain"
    read -r -s -p "Application Password for aoq-worker on ${WP_URL%/} (input hidden): " pw; echo
    [ -n "$pw" ] || die "no password entered"
    kv AOQ_WP_APP_PASSWORD "$pw"; unset pw
  fi
  echo "site $ADD_SITE: $SITE_FILE"
  "$HOME_DIR/run.sh" --site "$ADD_SITE" check || echo "check failed: fix the URL or password in $SITE_FILE, then: $HOME_DIR/run.sh --site $ADD_SITE check"
  exit 0
fi

echo "== 1. Python"
PY="$(command -v python3 || true)"
[ -n "$PY" ] || die "python3 not found"
"$PY" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)' || die "Python 3.11 or later is needed ($("$PY" --version))"
"$PY" -c 'import ensurepip, venv' 2>/dev/null || die "Python venv support is missing. On Ubuntu: sudo apt install python3-venv (or python3.12-venv)"
echo "$("$PY" --version) ok"

echo "== 2. Worker code and virtual environment in $HOME_DIR"
mkdir -p "$HOME_DIR"
[ -x "$HOME_DIR/venv/bin/python" ] || "$PY" -m venv "$HOME_DIR/venv"
"$HOME_DIR/venv/bin/python" -m pip install --quiet --upgrade pip
"$HOME_DIR/venv/bin/python" -m pip install --quiet --upgrade "$SRC"
OVERLAYS="$SRC/overlays"
[ -d "$OVERLAYS/agent_providers" ] || OVERLAYS="$SRC/../overlays"  # repository layout
[ -d "$OVERLAYS/agent_providers" ] || die "overlays not found next to the worker"
rm -rf "$HOME_DIR/overlays"
cp -R "$OVERLAYS" "$HOME_DIR/overlays"
echo "worker $("$HOME_DIR/venv/bin/aoq-worker" version) installed"

echo "== 3. Settings in $ENV_FILE"
mkdir -p "$CONFIG_DIR"
chmod 700 "$CONFIG_DIR"
touch "$ENV_FILE"
chmod 600 "$ENV_FILE"

get() {  # value of KEY, with the single quotes set_kv adds removed
  local v
  v="$(grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- || true)"
  v="${v#\'}"; v="${v%\'}"
  printf '%s' "${v//\'\\\'\'/\'}"
}
set_kv() {  # values are single-quoted so run.sh can source the file safely
  local key="$1" value="$2" tmp q="'\\''"
  tmp="$(mktemp "$CONFIG_DIR/.env.XXXXXX")"
  grep -v -E "^$key=" "$ENV_FILE" > "$tmp" || true
  printf "%s='%s'\n" "$key" "${value//\'/$q}" >> "$tmp"
  mv "$tmp" "$ENV_FILE"
  chmod 600 "$ENV_FILE"
}
ask() {  # ask KEY "question" [default]; keeps an existing value unless a flag gave a new one
  local key="$1" q="$2" def="${3:-}" cur ans
  cur="$(get "$key")"
  [ -n "$cur" ] && return 0
  if [ -t 0 ]; then read -r -p "$q${def:+ [$def]}: " ans; else ans=""; fi
  ans="${ans:-$def}"
  [ -n "$ans" ] && set_kv "$key" "$ans"
  return 0
}

[ -n "$WP_URL" ] && set_kv AOQ_WP_URL "${WP_URL%/}"
# The WordPress folder, for WP-CLI (language-site): this script lives in wp-content/plugins/ao-quill/worker.
WP_ROOT="$(cd "$SRC/../../../.." 2>/dev/null && pwd || true)"
[ -n "$WP_ROOT" ] && [ -f "$WP_ROOT/wp-config.php" ] && set_kv AOQ_WP_PATH "$WP_ROOT"
ask AOQ_WP_URL "WordPress main site URL (https)"
ask AOQ_WP_USER "WordPress worker user" "aoq-worker"
set_kv AOQ_OVERLAY_DIR "$HOME_DIR/overlays"
# AO settings now live in WordPress; values left here from older installs would override them.
for key in AOQ_AO_URL AOQ_AO_MTLS_DIR AOQ_AO_IPV4_ONLY AOQ_SEARXNG_URL AOQ_HTTP_USER_AGENT; do
  if [ -n "$(get "$key")" ]; then
    sed -i "/^$key=/d" "$ENV_FILE"
    echo "removed $key from $ENV_FILE (now read from WordPress)"
  fi
done

if [ -z "$(get AOQ_WP_APP_PASSWORD)" ]; then
  if [ -t 0 ]; then
    echo "Create it with: wp user application-password create aoq-worker \"ao-quill worker\" --porcelain"
    read -r -s -p "Application Password for $(get AOQ_WP_USER) (input hidden, empty to skip): " pw; echo
    [ -n "$pw" ] && set_kv AOQ_WP_APP_PASSWORD "$pw"
    unset pw
  fi
fi
[ -n "$(get AOQ_WP_APP_PASSWORD)" ] || echo "note: no Application Password yet; run this script again to add it"

echo "== 4. Run script"
cat > "$HOME_DIR/run.sh" <<EOF
#!/usr/bin/env bash
# Runs the worker once with the settings in $ENV_FILE. Overlapping runs are skipped.
#
# One worker can serve several WordPress sites that share one AO server: each extra site has its
# own settings in $CONFIG_DIR/sites.d/<name>.env (install.sh --add-site). A poll (run, the default)
# asks every site in turn, under one lock, so only one post run uses AO at a time. Other commands
# act on the main site, or on one site with --site NAME (for example: run.sh --site tgs check).
set -euo pipefail
exec 9>"$HOME_DIR/run.lock"
SITE=""
if [ "\${1:-}" = "--site" ]; then SITE="\${2:?--site needs a name}"; shift 2; fi
if [ "\${1:-run}" = "language-site" ]; then
  # Waits for a post run in progress instead of skipping (the cron poll skips).
  flock -n 9 || { echo "waiting for the worker run in progress to finish ..."; flock 9; }
else
  flock -n 9 || exit 0
fi
cd "$HOME_DIR"
SITES_DIR="$CONFIG_DIR/sites.d"

one() {  # one NAME FILE COMMAND...: the main settings, then the site's own over them
  local name="\$1" file="\$2"; shift 2
  (
    set -a; . "$ENV_FILE"; [ "\$file" = "$ENV_FILE" ] || . "\$file"; set +a
    export AOQ_SITE_LABEL="\$name"
    # Sibling busy broadcasts (D34): every configured WP site learns the worker is alive elsewhere.
    export AOQ_MAIN_ENV="$ENV_FILE"
    export AOQ_SITES_DIR="\$SITES_DIR"
    exec "$HOME_DIR/venv/bin/aoq-worker" "\$@"
  )
}

[ \$# -gt 0 ] || set -- run
if [ -n "\$SITE" ] && [ "\$SITE" != main ]; then
  [ -f "\$SITES_DIR/\$SITE.env" ] || { echo "no site \$SITE (\$SITES_DIR/\$SITE.env)"; exit 2; }
  one "\$SITE" "\$SITES_DIR/\$SITE.env" "\$@"
  exit \$?
fi
if [ -n "\$SITE" ] || [ "\$1" != run ] || [ -z "\$(ls "\$SITES_DIR"/*.env 2>/dev/null)" ]; then
  one main "$ENV_FILE" "\$@"
  exit \$?
fi
# Poll every site; a due run on one site runs to the end before the next site is asked.
rc=0
for file in "$ENV_FILE" "\$SITES_DIR"/*.env; do
  name=main; [ "\$file" = "$ENV_FILE" ] || name="\$(basename "\$file" .env)"
  set +e
  one "\$name" "\$file" "\$@" 2>&1 | sed -u "s/^/[\$name] /"
  status=\${PIPESTATUS[0]}
  set -e
  [ "\$status" = 0 ] || rc=1
done
exit \$rc
EOF
chmod 700 "$HOME_DIR/run.sh"
install -m 700 "$SRC/translate-nightly.sh" "$HOME_DIR/translate-nightly.sh"  # nightly language-site runs (see its header)
echo "$HOME_DIR/run.sh [run|check|run --dry-run --out DIR|enroll --ca-file ao-ca.pem|language-site --url URL --locale LOCALE]"

if [ "$CRON" = 1 ]; then
  echo "== 5. Cron (every minute; idle polls are one WordPress request)"
  line="* * * * * $HOME_DIR/run.sh run >> $HOME_DIR/worker.log 2>&1 # ao-quill-worker"
  { crontab -l 2>/dev/null | grep -v '# ao-quill-worker' || true; echo "$line"; } | crontab -
  echo "added: $line"
  if [ -f "$CONFIG_DIR/nightly.env" ]; then
    # The nightly translation carries on after a reboot inside its window (translate-nightly.sh --resume).
    boot="@reboot sleep 120; $HOME_DIR/translate-nightly.sh --resume >> $HOME_DIR/translate-nightly.log 2>&1 # aoq-translate-nightly-reboot"
    { crontab -l 2>/dev/null | grep -v '# aoq-translate-nightly-reboot' || true; echo "$boot"; } | crontab -
    echo "added: $boot"
  fi
else
  echo "== 5. Cron not changed (add --cron to schedule the worker)"
fi

echo "== Check"
"$HOME_DIR/run.sh" check || echo "check failed. WordPress settings: $ENV_FILE. AO settings and certificate: Network Admin, AO Quill, AO connection. Then: $HOME_DIR/run.sh check"
