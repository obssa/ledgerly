#!/usr/bin/env bash
# Runs ON the cPanel server: puts the previous release back live. Called by the deploy workflow when the
# smoke test of a new release fails, or by hand: `bash ~/ledgerly/deploy/rollback.sh`.
#
# Code only. Migrations are not reversed (the ledger schema refuses rollbacks by design); every migration is
# written so the previous release still runs against the newer schema.
set -euo pipefail

ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"
WEB_APP_ROOT="${LEDGERLY_WEB_APP_ROOT:-$HOME/ledgerly-web}"

PREVIOUS="$(cat "$ROOT/shared/previous-release" 2>/dev/null || true)"
[ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ] || { echo "No previous release to go back to."; exit 1; }
FAILED="$(readlink "$ROOT/current" || true)"

ln -sfn "$PREVIOUS" "$ROOT/current.next" && mv -Tf "$ROOT/current.next" "$ROOT/current"
mkdir -p "$WEB_APP_ROOT/tmp" && touch "$WEB_APP_ROOT/tmp/restart.txt"
(cd "$ROOT/current/api" && "$PHP" artisan queue:restart --no-interaction) || true

echo "Rolled back: $(basename "$FAILED") -> $(basename "$PREVIOUS")"
