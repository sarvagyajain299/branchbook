# PR: Phase 7 — REST API (Sanctum)

**Branch:** `feature/phase-7-api` → `develop`

## Summary
A versioned `/api/v1` REST API for availability, bookings and queue operations. Controllers call the
same Action classes as the website; tokens carry per-role abilities; every error uses one JSON shape.

## Changes
- **Public (60/min):** `GET /branches` (city filter, `?lat=&lng=` Haversine sort), `GET /services`, `GET /branches/{code}/availability`.
- **Bookings:** `POST /bookings` (hold + code, 10/min), `POST /bookings/{ref}/verify`, `GET` and `DELETE /bookings/{ref}` with the `X-Manage-Token` header.
- **Staff (Sanctum):** `POST /auth/tokens` (5/min), `DELETE /auth/tokens/current`, `GET /branches/{code}/queue`, `POST /branches/{code}/queue/walk-ins`, `POST /queue/call-next`, `PATCH /queue-tickets/{id}`.
- **Abilities:** front desk, manager and HQ get `queue:read` + `queue:issue`; advisors get `queue:read` + `queue:operate`. Policies still enforce the branch (403 cross-branch).
- **Errors:** `{"message", "errors"}` everywhere; 409 for conflicts, 410 for an expired hold, 404 without leaking details, 429 for limits.
- **Docs:** `docs/openapi.yaml` (13 operations) and `docs/api/BranchBook.postman_collection.json`.
- Sanctum installed; `User` uses `HasApiTokens`; `Audit` records token creation.
- **Not built:** reschedule (`PATCH /bookings/{ref}`).

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 347 passed (2434 assertions)
```
OpenAPI file parsed with PyYAML: 12 paths, 13 operations, no unresolved `$ref`. Operations match the 13 routes in `route:list`.

## How to test
1. `sail composer ci`
2. Import `docs/api/BranchBook.postman_collection.json` into Postman or Bruno and run the folders in order (read the code from Mailpit at http://localhost:8026).
3. `curl http://localhost/api/v1/branches?lat=25.08&lng=55.14`

## Risks / notes
- Bug found by its own test: an invalid dummy bcrypt hash made unknown-email logins return 500; fixed.
- Staff tokens last 12 hours; there is no refresh endpoint.
