# API usage analytics

Two tables, deliberately kept apart:

| | `api_usage` | `api_requests` |
|---|---|---|
| Granularity | one row per user per day | one row per request |
| Counts | allowed calls only | **everything, including 429s** |
| Retention | indefinite | `API_ANALYTICS_RETENTION_DAYS` (default 90) |
| Personal data | none | IP, user agent, search terms |
| Purpose | billing / quota | operational analytics |

**They will legitimately disagree**, and that is by design. A throttled request
appears in the detail log and never in the rollup. If the two ever agree on a
throttled call, billing has changed.

---

## Capture

`TrackApiRequest` writes one row per call. Three things about it are load-bearing:

**It runs above the throttler.** Listing it before `throttle:api` in
`routes/api.php` is *not* sufficient — Laravel re-sorts route middleware by its
priority list, which hoists `ThrottleRequests` above anything unlisted. The
middleware is pinned ahead of it via `prependToPriorityList` in
`bootstrap/app.php`. Remove that pin and throttled requests silently stop being
recorded, with no error. `MiddlewareOrderTest` asserts the resolved order on
every API route.

**The insert happens in `terminate()`**, after `Response::send()` has already
called `fastcgi_finish_request()`, so the caller has their bytes before anything
is written. State travels on `$request->attributes` because
`Kernel::terminateMiddleware()` resolves a *fresh* middleware instance —
anything stashed on `$this` in `handle()` is gone by then.

**Failure is always swallowed.** An analytics bug must never turn into a 500 for
a paying caller.

`TrackApiUsage` and `ApiUsage` are untouched by all of this. The 429 exclusion is
structural — the throttler physically sits between the two middleware — rather
than a condition someone can get wrong later.

**`GET /api/user` now shares the stack.** It was the one authenticated endpoint
with neither throttling nor tracking. Closing that hole is a real behaviour
change for callers: the endpoint consumes quota and can return 429 where it
never used to, which on the 30-a-day free plan is not nothing. Documented under
"Rate limits & plans" in `Docs.vue`.

## Reading

`App\Support\ApiAnalytics`. Every range is half-open (`>= from`, `< to`), and
every series is zero-filled so a quiet day renders as a zero rather than a gap.

Percentiles use `ROW_NUMBER()`, **not `PERCENTILE_CONT`**. MariaDB 11.8
(production) has the latter; MySQL (dev and the test database) does not — so that
choice would pass in production and fail every test run.

`quotaPressure()` deliberately reads `api_usage`, so the admin sees the same
number the user sees on their own dashboard and the same number billing counts.

## Retention

```bash
php artisan api:prune-requests --dry-run     # report only
php artisan api:prune-requests               # config window, chunked deletes
php artisan api:prune-requests --days=30     # override
```

Retention is the **privacy control** for this table, not just housekeeping — the
window is what the published policy commits to.

It's registered on the scheduler (`routes/console.php`, 03:10 daily), which does
nothing until something calls `schedule:run`. To activate it, add one cron entry
in hPanel → Advanced → Cron Jobs, **Custom**, every minute:

```
cd /home/<user>/domains/golfcoursesapi.com/public_html && /usr/bin/php artisan schedule:run >/dev/null 2>&1
```

That also unlocks a queue worker for the scorecard scanner (see
`docs/SCORECARD_SCANNING.md`).

**Until that cron exists**, the admin analytics page prunes opportunistically —
one bounded batch, at most once a day, guarded by a cache lock. It fires on an
admin page load and *never* on an API request, so the caller-facing path stays
clean. Worst case the table drifts a few days past the window between visits.

## Privacy

Published at `/privacy`, rendered from the same config the code uses so the
commitment can't drift from the behaviour.

| Setting | Default | Effect |
|---|---|---|
| `API_ANALYTICS_IP_MODE` | `anonymized` | IPv4 → `/24`, IPv6 → `/48` |
| | `full` | verbatim — a conscious opt-in |
| | `hashed` | HMAC. **Weaker than it looks**: only ~4bn IPv4 inputs exist, so a hash is brute-forceable. Anonymising is stronger. |

Also: only whitelisted query parameters are stored (a caller can't write
arbitrary keys into the table), and `lat`/`lng` are rounded to ~1km. Deleting an
account cascades to its detail rows — that's the erasure path.

⚠️ **`TrustProxies` is not configured.** On Hostinger, requests arrive via
LiteSpeed, so `$request->ip()` may return a proxy address and every row could
carry the same value, making the distinct-networks figure meaningless. Verify
against real production traffic before trusting it. The existing IP-keyed
`explore` rate limiter has the same exposure.

## Local development

```bash
php artisan db:seed --class=ApiAnalyticsSeeder
```

~17k requests across 6 users over 60 days, with a shape chosen so bugs can't hide:
a long-tail latency distribution where p50 and p95 genuinely differ (uniform
durations would let a broken percentile query pass by eye), diurnal and weekday
weighting (flat traffic hides zero-fill bugs), and enough 429s to make the
detail-vs-billing split visible. Admin login: `analytics-admin@example.com` /
`password`.

## Known gaps

- **No sub-day range.** Traffic buckets by calendar day, so a 24-hour window
  would render as two partial bars that read like a trend. An honest short view
  needs hourly bucketing.
- **Unauthenticated failures aren't recorded.** Capture sits below `auth:sanctum`,
  so a 401 from a bad key is invisible. Recording those means instrumenting above
  auth, where there's no user to attribute the row to.
- **The public `/explore/*` endpoints are unmeasured** — no authenticated user to
  attribute them to.
