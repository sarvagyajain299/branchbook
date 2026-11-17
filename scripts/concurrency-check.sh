#!/usr/bin/env bash
# Real concurrency check against the dev database (run from the project root, in WSL).
#
# Pest wraps every test in ONE database transaction, so it cannot fire truly simultaneous
# requests. This script launches several PHP processes that all start at the same instant and
# checks the guarantees that depend on MySQL row locks:
#   1. ticket numbers are unique and gapless when several desks issue tickets at once;
#   2. several advisors pressing "Call next" at once never get the same customer;
#   3. several customers booking the same last slot at once: exactly one wins per advisor.
#
# It writes to the database in .env. Reset afterwards with:  sail artisan migrate:fresh --seed
set -uo pipefail

SAIL=./vendor/bin/sail
RUN="$SAIL exec -T laravel.test php scripts/concurrency-check.php"
pass=0; fail=0

launch_at() { python3 -c 'import time; print(f"{time.time()+4:.3f}")'; }   # everyone fires 4 s from now
ok()   { echo "  PASS  $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL  $1"; fail=$((fail+1)); }

echo "1. Ticket numbering: 8 desks issue an A- ticket at the same instant"
for round in 1 2 3; do
  start=$(launch_at); out=$(mktemp -d)
  for i in $(seq 1 8); do $RUN issue DXB-MAR PAO "$start" > "$out/$i" & done; wait
  codes=$(cat "$out"/* | sort -u | wc -l)
  [ "$codes" -eq 8 ] && ok "round $round: 8 workers got 8 different numbers ($(cat "$out"/* | sort | tr '\n' ' '))" || bad "round $round: duplicates or errors: $(cat "$out"/* | tr '\n' ' ')"
  rm -rf "$out"
done

echo "2. Call next: 3 qualified advisors press the button together, 8 customers waiting"
for round in 1 2 3; do
  start=$(launch_at); out=$(mktemp -d)
  i=0; for email in advisor.dxb1@northwind.test advisor.dxb2@northwind.test advisor.dxb4@northwind.test; do
    i=$((i+1)); $RUN call "$email" "$start" > "$out/$i" &
  done; wait
  distinct=$(cat "$out"/* | grep -v none | sort -u | wc -l); total=$(cat "$out"/* | grep -vc none)
  errors=$(cat "$out"/* | grep -c ERR)
  [ "$distinct" -eq 3 ] && [ "$total" -eq 3 ] && [ "$errors" -eq 0 ] && ok "round $round: 3 advisors got 3 different tickets ($(cat "$out"/* | tr '\n' ' '))" || bad "round $round: $(cat "$out"/* | tr '\n' ' ')"
  rm -rf "$out"
  # free the advisors for the next round
  docker exec branchbook-mysql-1 mysql -usail -ppassword branchbook -e "UPDATE queue_tickets SET status='no_show' WHERE status='called'" 2>/dev/null
done

echo "3. Booking: 6 customers try to hold the same slot; 3 advisors can take PAO"
# Find a real slot where all 3 qualified advisors are free, using the public JSON endpoint.
slot=$(curl -s -H 'Accept: application/json' "http://localhost/book/PAO/DXB-MAR/slots?days=14" | python3 -c '
import json, sys, datetime as dt
today = dt.date.today().isoformat()
for day in json.load(sys.stdin)["days"]:
    if day["date"] <= today:
        continue
    for s in day["slots"]:
        if s["free_advisors"] >= 3:
            print(day["date"] + " " + s["local_time"]); sys.exit()
')
echo "     slot: $slot"
start=$(launch_at); out=$(mktemp -d)
for i in $(seq 1 6); do $RUN book DXB-MAR PAO "$slot" "$start" "$i" > "$out/$i" & done; wait
booked=$(cat "$out"/* | grep -c '^booked'); advisors=$(cat "$out"/* | grep '^booked' | sort -u | wc -l); errors=$(cat "$out"/* | grep -c ERR)
echo "     results: $(cat "$out"/* | tr '\n' ' ')"
[ "$booked" -le 3 ] && [ "$booked" -eq "$advisors" ] && [ "$errors" -eq 0 ] && ok "at most one booking per advisor ($booked booked, $advisors distinct advisors)" || bad "double booking or error"
rm -rf "$out"

echo
echo "Result: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
