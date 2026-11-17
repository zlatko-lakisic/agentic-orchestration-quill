#!/usr/bin/env bash
# PHP syntax check of every plugin file (needs php on PATH or Docker).
set -euo pipefail
cd "$(dirname "$0")/../ao-quill"
if command -v php >/dev/null; then
  find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
else
  docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash -c "find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l"
fi
