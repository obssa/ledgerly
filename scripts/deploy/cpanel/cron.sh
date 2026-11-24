#!/usr/bin/env bash
# Ledgerly's two cron jobs on the cPanel account, run by provision.sh and by every release.sh.
#
#   bash cron.sh            ROOT and PHP come from LEDGERLY_ROOT / PHP_BIN, as in release.sh
#
# Each job runs at most once at a time and for a bounded time. Without that, one run that never ends (a mail
# hand-off that stalls, a lock wait) is joined by another every minute or hour, and on shared hosting the account's
# process limit is reached: then nothing on the account can start, other apps included, and deploys fail with
# "fork: Resource temporarily unavailable". So:
#   flock -n    a run that finds the previous one still going does nothing (one process, gone at once)
#   timeout     a run that outlives its time is stopped (TERM, then KILL); its transaction rolls back
#
# The crontab is shared with every other app on this account (bakery-ops). Only lines that run Ledgerly's own
# commands from this ROOT are replaced; every other line stays as it is. If the crontab cannot be read for any
# reason other than "there is none yet", or flock/timeout are missing, nothing is changed.
set -euo pipefail

ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

SCHEDULE_CMD="artisan schedule:run"
QUEUE_CMD="artisan queue:work"
# The scheduler: hourly and daily commands, each finishing in seconds; stopped after 55 minutes at the latest so
# the next hour's run is never blocked by it.
SCHEDULE_LINE="* * * * * cd $ROOT/current/api && flock -n $ROOT/shared/schedule.lock timeout -k 30 3300 $PHP artisan schedule:run >/dev/null 2>&1"
# The queue: one worker, which stops when the queue is empty or after 50 seconds; one job may take up to 10 minutes
# (filling the demo company), after which the worker is stopped and the job is tried again later (3 tries).
QUEUE_LINE="* * * * * cd $ROOT/current/api && flock -n $ROOT/shared/queue.lock timeout -k 30 600 $PHP artisan queue:work --stop-when-empty --max-time=50 --tries=3 >/dev/null 2>&1"

for tool in flock timeout crontab; do
  command -v "$tool" >/dev/null 2>&1 || { echo "cron: '$tool' is not available here: the crontab is left as it is"; exit 0; }
done

if ! current="$(crontab -l 2>&1)"; then
  case "$current" in
    *"no crontab"*) current="" ;;
    *) echo "cron: could not read the crontab ($current): not touching it"; exit 0 ;;
  esac
fi

# Ours: a line that runs one of our two commands from this ROOT. The host may have rewritten it when it was saved
# (it puts `sleep $((RANDOM % 60)) &&` in front, to spread load), so lines are matched by what they run.
ours() { [[ $1 == *"cd $ROOT/current/api && "* && $1 == *"$2"* ]]; }

wanted=0 out="" have_schedule="" have_queue=""
while IFS= read -r line || [ -n "$line" ]; do
  if ours "$line" "$SCHEDULE_CMD"; then
    if [[ -z $have_schedule && $line == *"${SCHEDULE_LINE#\* \* \* \* \* }"* ]]; then have_schedule=1; out+="$line"$'\n'; else wanted=1; fi
    continue
  fi
  if ours "$line" "$QUEUE_CMD"; then
    if [[ -z $have_queue && $line == *"${QUEUE_LINE#\* \* \* \* \* }"* ]]; then have_queue=1; out+="$line"$'\n'; else wanted=1; fi
    continue
  fi
  out+="$line"$'\n'
done <<<"$current"
[ -z "$current" ] && out=""
[ -n "$have_schedule" ] || { out+="$SCHEDULE_LINE"$'\n'; wanted=1; }
[ -n "$have_queue" ] || { out+="$QUEUE_LINE"$'\n'; wanted=1; }

if [ "$wanted" -eq 0 ]; then
  echo "cron: up to date"
  exit 0
fi
mkdir -p "$ROOT/shared"
[ -f "$ROOT/shared/crontab.before-ledgerly" ] || printf '%s\n' "$current" > "$ROOT/shared/crontab.before-ledgerly"
printf '%s\n' "$current" > "$ROOT/shared/crontab.previous"
printf '%s' "$out" | crontab -
echo "cron: Ledgerly's jobs now run one at a time, for a bounded time (copy of the previous crontab: shared/crontab.previous)"
