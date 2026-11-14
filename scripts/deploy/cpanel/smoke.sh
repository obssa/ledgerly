#!/usr/bin/env bash
# Runs on the CI runner after a release went live: checks the public addresses answer as they should.
#   bash scripts/deploy/cpanel/smoke.sh https://ledgerly.orcamind.dev https://ledgerly-api.orcamind.dev <sha>
set -euo pipefail
WEB="${1:?web url}"
API="${2:?api url}"

wait_for() {   # url, expected status
  local code=000
  for _ in $(seq 1 30); do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$1" || true)"
    [ "$code" = "$2" ] && return 0
    sleep 4
  done
  echo "FAIL $1 answered $code, expected $2"
  return 1
}

wait_for "$API/up" 200
echo "ok  API health"

body="$(curl -s --max-time 20 "$API/api/v1/companies")"
[ "$body" = '{"message":"Unauthenticated."}' ] || { echo "FAIL API without a token answered: $body"; exit 1; }
echo "ok  API refuses an unauthenticated request with JSON 401"

headers="$(curl -sI --max-time 20 "$API/api/v1/companies")"
for h in x-content-type-options x-frame-options cache-control strict-transport-security; do
  grep -qi "^$h:" <<<"$headers" || { echo "FAIL API response lacks $h"; exit 1; }
done
echo "ok  API security headers (HSTS means HTTPS is seen through Cloudflare)"

wait_for "$WEB/" 200   # the first request also starts the Node.js app
curl -sI --max-time 20 "$WEB/" | grep -qi '^x-frame-options: DENY' || { echo "FAIL web app lacks X-Frame-Options"; exit 1; }
if curl -s --max-time 20 "$WEB/" | grep -q 'Demo mode'; then echo "FAIL web app is in demo mode: LEDGERLY_API_URL missing from shared/web.env"; exit 1; fi
echo "ok  web app answers, connected to the API"
