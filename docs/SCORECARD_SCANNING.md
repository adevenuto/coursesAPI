# Scorecard scanning

Editors upload a photo of a scorecard; Claude reads it into a structured parse;
the editor reviews a diff and applies the parts they accept. Nothing reaches a
course until that last step.

Entry points: **Scan card** on a course's edit page, and **Scan a scorecard** on
the explorer (the latter with no course, to build a new one).

---

## Setup

| | |
|---|---|
| `ANTHROPIC_API_KEY` | Server-side key. Never sent to the browser. |
| `ANTHROPIC_MODEL` | Defaults to `claude-opus-5`. |
| `gd` PHP extension | Required — hPanel → Advanced → PHP Configuration. |
| `exif` PHP extension | Optional; without it, EXIF-rotated photos aren't auto-straightened. |

Without the key the scan page reports a configuration error rather than failing
silently on every upload. Without `gd` the upload is rejected with a pointed
message instead of shipping a 12 MB original.

Cost is roughly **$0.25–0.45 per card** (~4.8k input tokens per image plus
~8–15k output). Re-uploading a card that's already been read costs nothing —
see *Spend guard* below.

---

## The flow

```
upload  →  scorecard_scans row (status: pending, images normalised)
parse   →  Claude vision + JSON Schema  →  raw_parse (verbatim)  →  verify in PHP
review  →  diff of proposed vs current, section by section
apply   →  CourseWriter  →  course + course_revisions
```

Each step is a route under the existing editor group (`auth`, `verified`,
`EnsureCourseEditor`), plus a per-scan owner-or-admin check — being an editor
doesn't grant access to another editor's uploads.

### Images

Normalised on upload by `ScorecardImage`: long edge clamped to **2576 px**
(Claude's high-resolution ceiling — larger is downsampled server-side anyway and
only inflates the bill), EXIF rotation baked in, re-encoded JPEG q92. The
quality is deliberately high: the whole feature turns on reading small printed
digits.

Up to 4 images per scan, so a card can be shot in halves or front-and-back.
Files live on the `local` disk under `storage/app/private/scorecards/{id}/`,
which the deploy rsync excludes, so they survive deploys. They're streamed
through a route rather than served.

### Parse

Output is constrained by a JSON Schema (`ScorecardSchema`) rather than coaxed
out of prose, so there's no fragile extraction step and every key is always
present. The schema and the reading instructions live in the same class because
they change together.

Departures from the shape you'd write by hand, all forced by structured outputs.
It requires `additionalProperties: false` and every key in `required`, rejects
numeric constraints, and — the binding one — **allows at most 16 union-typed
parameters** across the whole schema. Exceed it and every parse 400s with
"exponential compilation cost". The first draft had 41.

| Convention | Why |
|---|---|
| Ranges (par 3–6, handicap 1–18) live in the instructions | Schema rejects numeric constraints; `ScorecardVerifier` enforces them |
| Absent text is `""`, not `null` | Unambiguous for text, and each nullable string costs a union |
| `cartPathOnly` is `yes｜no｜unknown` | Keeps the three-state at no union cost |
| `par` is required, never null | Every card prints it for every hole, and it's bound to 3–6 |
| Combination tees aren't captured structurally | They cost 9 of the 16 unions alone — the instructions ask for them in `parseNotes` instead |

The 10 unions that remain are where absence is real and unguessable: ratings,
slopes, printed Out/In/Total yardages, and stroke indexes.

`ScorecardSchemaTest` guards both the ceiling and fixture/schema agreement — the
ceiling is invisible locally (the SDK serializes an over-budget schema happily;
only the API objects), so keep headroom when adding fields.

`parseNotes` is the model's channel for anything the schema can't express — a
sum that didn't reconcile, an illegible cell, a combination tee.

### Verify

`ScorecardVerifier` recomputes the card's arithmetic in PHP. A self-report isn't
evidence: a card that doesn't add up is exactly the case where the model is
least reliable about saying so.

- **error** — wrong, or would be rejected by `CourseValidationRules` on apply.
  Bent totals, duplicated or gapped stroke indexes, out-of-range values.
- **warning** — real and must not block: metres, a hole that plays longer from a
  shorter tee, a card that indexes only one gender.

### Apply

Sections are the unit of acceptance: course details, hole count, and one per
tee. Rejecting a section leaves that part of the course exactly as it was. Tees
are matched **by name**, case-insensitively — positions shift and `layout_data`
has no tee ids — and an accepted tee is merged rather than replaced, so a field
the card didn't print doesn't blank an existing value.

Out-of-range values are dropped rather than written: a misread digit leaves a
gap instead of producing `layout_data` the editor's own save path would refuse.

The bounds live in `App\Support\CourseRating` because the course-rating one is
not a constant: a rating is a scratch player's expected score, so a nine rates
about half an eighteen. The floor is **20 for a nine or shorter, 55 above that**,
against a ceiling of 80. A flat 55 — what the editor enforced before — rejected
every correctly read nine-hole card, and would have nulled all four of Willow
Hill's ratings (33.6/32.3 men, 34.6/32.1 women) on the way in. Hole count is
counted from holes that carry a par or a yardage, so the nine-hole courses stored
as eighteen slots with a blank back nine are bounded as the nines they are.

Tee colours come from the tee's **name**, not from the model. The schema asks
for `tees[].hex` and the model always answers, but with a fresh shade each time —
Blue came back as `#1F4FA8`, `#1F5FBF` and `#2A6EBB` across three real cards,
none of them the palette blue — so every scan needed the colour re-picked by
hand. `App\Support\TeeColor` resolves it from the name against the vocabulary in
`config/tee_colors.php`, which also splits a two-tone name ("Blue/White") across
`color` and `secondaryColor`. The model's hex survives only where the name
carries no colour at all ("Championship", "Forward").

The same resolver backs the editor (typing a name fills the swatch) and
`courses:fix-tee-colors`, which backfills the ~90,000 teeboxes that never had a
colour. About 89% of tee names resolve; the rest are genuinely colourless.

The backfill is a dry run until `--apply`, and wants `--no-index`:

```bash
php artisan courses:fix-tee-colors --csv=/tmp/tee-colors.csv   # read this first
php artisan courses:fix-tee-colors --apply --no-index
```

**No reindex afterwards.** `Course::toSearchableArray()` carries no teebox data,
so nothing searchable changes. `--no-index` only avoids ~20,000 blocking Algolia
round-trips (`SCOUT_QUEUE=false`) that would each write a payload identical to
the one already stored.

A tee rated for one gender only is stored as `[null, women]` rather than
dropped. Cards routinely rate the back tees for men and the forward tee for
women — a red tee printed "56.1/86" under the Ladies' Handicap row — and index 0
of a gendered field *means* men's, so the value cannot collapse to a scalar
without relabelling a women's rating as the men's one. The API reports `rating:
null` with `rating_women: 56.1`; it never invents a men's rating of zero.

The write goes through `App\Support\CourseWriter`, shared with the manual editor,
so a scan-applied change is indistinguishable from a hand edit in the audit log.
Vendor keys (`golftraxx`) and green centers survive untouched.

A scorecard carries no coordinates, so a course created from one lands in the
editor for placement.

### Spend guard

`content_hash` is a sha256 over the **normalised** image bytes. Before calling
the API, the job looks for an earlier scan with the same hash and copies its
parse. Verification is re-run rather than copied, so tightening the rules
applies to cards already read.

---

## What isn't stored

The parse is deliberately richer than `layout_data`. These are read, kept on the
scan, and listed in the preview under *Read, but not stored* — a disclosure, not
a silent drop:

hole names · pace of play and per-hole target times · cart-path-only flags ·
metres (numbers are stored as printed, never converted) · card ID and print date ·
printed Out/In/Total (recomputed from the holes on save) · **gender-split par**
(par is stored per tee, not per gender).

Combination tees aren't captured structurally at all (see the union ceiling
above) — if a card has one, it shows up as prose in `parseNotes`.

### Heads-up: the `*_women` API fields

`layout_data` has always supported gendered `[men, women]` values for slope,
course rating and hole handicap, but **no course used them** — 0 of 22,066 rows.
This feature is the first real writer.

`Course::getScorecardAttribute()` currently falls back to the men's value for
`rating_women` / `slope_women` / `handicap_women`. Once a scanned course carries
genuine women's numbers, those fields stop echoing the men's values and start
reporting the real ones. That's a correctness fix, but it *is* an externally
visible change for scanned courses.

---

## Processing model

`ParseScorecardScan` runs one of two ways, chosen by `SCORECARD_INLINE_PARSE`.

**Inline (`true`, the default)** — `dispatchSync()` runs the parse in the request
that started it, `set_time_limit(300)`, spinner in the UI. No infrastructure, and
the result is there when the request returns. Exposed to the server's request
timeout: a card that takes 90s behind a 60s ceiling gets cut off, having already
been billed for. A scan left in `parsing` in this mode means exactly that, and
the page offers a retry.

**Queued (`false`)** — `dispatch()` files the job and returns immediately; the
page polls every 4s until it lands. Needs a cron worker (below). Immune to the
request timeout, but **slower for a single card**: it adds up to one cron
interval before the parse even starts, plus a polling round-trip.

**Inline is the right default.** This is a per-course widget — one card at a
time, from a course's edit page — and for that, queued only adds latency. Turn
the worker on if, and only if, the request timeout is actually cutting parses
off in production. It's a remedy, not an upgrade.

Inline is also the safe default for deployment: dispatching into a queue nothing
drains would leave every parse hanging, so the code can ship before any cron
exists.

### Enabling the worker (only if inline times out)

Hostinger hPanel → Advanced → Cron Jobs → **Custom** (not PHP — that mode takes a
script path, and this needs arguments), every minute:

```
cd /home/<user>/domains/<domain>/public_html && /usr/bin/php artisan queue:work --stop-when-empty --max-time=55 --timeout=300 >/dev/null 2>&1
```

- `--stop-when-empty` exits the moment the queue drains, and `--max-time=55`
  caps the process below the interval, so this never becomes the persistent
  daemon CloudLinux's process limit punishes.
- `--timeout=300` matches `ParseScorecardScan::$timeout`. The `queue:work`
  default is **60s**, which would reap a parse mid-call.

Then set `SCORECARD_INLINE_PARSE=false` and `php artisan config:cache`. To roll
back, set it to `true` and re-cache — no deploy needed.

Whichever mode is active, `ParseScorecardScan::failed()` marks the scan failed if
the worker kills the job, so it can't sit on `parsing` with no explanation.

---

## Verifying an environment

```bash
php artisan scorecard:doctor
```

Checks `gd`, `exif`, the API key, `max_execution_time`, storage writability and
the table — over SSH, before the first upload. Each prerequisite fails
differently in the browser, and a request cut off mid-parse costs money with
nothing to show for it.

**The timeout check matters most on shared hosting.** The parse runs inline and
takes 30–90s; `artisan serve` has no ceiling locally, so a limit only bites in
production. A cut-off request leaves the scan in `parsing` — the page detects
this on reload and offers a retry, since nothing drains the queue and no work
can be in flight.

## Working on it

```bash
php artisan scorecard:parse {scan}            # re-run a parse from the CLI
php artisan scorecard:parse {scan} --force    # ignore an existing result
php artisan test tests/Feature/Scorecard tests/Unit/Scorecard
```

Tests inject a PSR-18 transporter (`Tests\Support\FakeAnthropicTransport`) rather
than using `Http::fake()` — the SDK discovers its own HTTP client, so the facade
can't see its traffic. The upside is that assertions run against the real
serialized request body. `tests/Fixtures/scorecards/bolingbrook.json` is a
complete, internally-consistent card: every printed total reconciles, so it can
be perturbed one field at a time to test a specific check.

| Class | Job |
|---|---|
| `ScorecardImage` | resize + orient uploads |
| `ScorecardSchema` | JSON Schema + reading instructions |
| `ScorecardParser` | the API call |
| `ScorecardVerifier` | recompute the arithmetic |
| `ScorecardMapper` | parse → `CourseLayoutWriter` shape, collect unmapped |
| `ScorecardDiff` | field-level before/after, grouped into sections |
| `ScorecardApplier` | overlay accepted sections, write via `CourseWriter` |
