# SEO, metadata and crawlers (Laravel Head)

Every page's `<title>`, description, canonical, Open Graph, X card, robots directive and
JSON-LD is resolved **server-side** by [`laravel/head`](https://laravel.com/docs/13.x/head)
and emitted into the initial HTML by the `@head` directive in
`resources/views/app.blade.php`. Inertia then adopts those elements and keeps them in sync
across client-side visits.

**Why server-side matters here:** this app has no working SSR, so before Laravel Head the
initial response was an empty `<div id="app">` plus a hardcoded title. Everything in the
Vue `<Head>` components ran only in the browser. Googlebot may eventually execute JS;
Slack, X, LinkedIn and iMessage never do — so every shared link was a blank preview.

> **Do not use Inertia's `<Head>` component.** Laravel Head owns the document head. Two
> systems managing the same element fight each other. There is also no `title` callback in
> `resources/js/app.ts` — it sets `serverHead: true` instead.

## Where metadata is defined

Three layers, lowest priority first. Higher layers override lower ones field by field.

| Layer | Where | Use for |
|---|---|---|
| Defaults | `app/Providers/HeadServiceProvider.php` | Site-wide: title suffix, description, canonical, OG/X card, share image, font preconnects |
| Route | `routes/web.php`, `routes/settings.php` via `->withHead(...)` | Static pages whose metadata is known ahead of time |
| Runtime | Controllers via the `Head` facade | Anything derived from the request or a model |

`HeadServiceProvider` also registers:

- **`inertiaGlobals`** — viewport, theme colour, icons, manifest. Rendered once in the first
  response and never updated. Only put session-stable tags here.
- **`errors`** — `noindex, follow` plus titles per status code.
- The custom **`GolfCourse`** schema type.

### Adding metadata to a new page

Static page — put it on the route:

```php
Route::get('pricing', PricingController::class)
    ->name('pricing')
    ->withHead(
        title: 'Pricing',                       // renders "Pricing — GCA"
        description: 'Plans and limits.',
    );
```

Pass `title: ['value' => '...', 'exact' => true]` to opt out of the inherited ` — GCA`
suffix (the landing page does this).

Anything private — add `robots` to the group, as `routes/settings.php` does:

```php
Route::withHead(robots: 'noindex, nofollow')->middleware(['auth'])->group(function () {
    // ...
});
```

Model-derived — set it at runtime, as `CourseShowController::head()` does:

```php
Head::title($course->course_name)
    ->description($description)
    ->canonical(route('courses.show', [...]));
```

Fortify's auth routes are registered inside the package, so `withHead()` can't be chained
onto them. Their titles come from the `page()` helper in `FortifyServiceProvider`.

## Structured data (JSON-LD)

| Page | Schema |
|---|---|
| Landing | `Organization`, `WebSite` |
| Course detail | `GolfCourse`, `BreadcrumbList` |

`GolfCourse` isn't one of Laravel Head's built-in types — it's declared in
`app/Head/Schemas/GolfCourse.php` with `#[SchemaType('GolfCourse')]` and registered in
`HeadServiceProvider`.

> **The schema validator rejects `null` and empty-string values** — it throws outside
> production and logs a warning in production. Course rows vary a lot in completeness, so
> every setter on `GolfCourse` drops absent data instead of writing a blank property. Keep
> that pattern when adding fields.

Note: Google's Rich Results Test reports **only** `BreadcrumbList`, because there is no
Google rich-result feature for `GolfCourse`. That is not a failure — use
[validator.schema.org](https://validator.schema.org) to check that block.

## Sitemaps and robots.txt

`app/Http/Controllers/SitemapController.php`, routed in `routes/web.php`.

| URL | Contents |
|---|---|
| `/sitemap.xml` | Index: the pages sitemap plus one entry per course chunk |
| `/sitemap/pages.xml` | `/`, `/docs`, `/explorer` |
| `/sitemap/courses-{n}.xml` | 5,000 courses per chunk (~5 chunks at 22k courses) |
| `/robots.txt` | Allow-all, `Disallow` for private areas, `Sitemap:` line |

Both are **generated on request and cached for 24 hours**, not written to disk. This host
has no cron to regenerate a file, and anything written into `public/` would be deleted by
the deploy's `rsync --delete`. `robots.txt` is a route for the same reason the canonical
URL is generated — its `Sitemap:` line must track `APP_URL` rather than a hardcoded host.
**There is no `public/robots.txt`; do not add one back**, or Apache will serve it instead
of the route.

If `APP_URL` changes, run `php artisan cache:clear` — otherwise the cached sitemaps serve
stale absolute URLs for up to a day.

## Social share image

`public/og-image.png` (1200×630) is the site-wide default, referenced by
`HeadServiceProvider::OG_IMAGE`.

Its source is `resources/og-image/card.html`. To change it, edit that file and re-render:

```bash
bin/render-og-image.sh          # headless Chrome → public/og-image.png
```

Headless Chrome rather than a design tool because Sora and JetBrains Mono are loaded from
Google Fonts, not installed as system fonts — other renderers substitute them silently and
the wordmark comes out wrong. **Commit the PNG:** the deploy never runs this script, so an
untracked file would disappear on the next deploy.

Colours and the `G`/`CA` split mirror the footer lockup in
`resources/js/components/marketing/MarketingFooter.vue`; the contour rings reuse the
geometry from `ContourGreen.vue`. Hostinger's CDN re-encodes the PNG at the edge, so the
bytes served won't match the committed file — that's expected.

## Verifying a deploy

```bash
D=https://golfcoursesapi.com

curl -s $D/ | grep -o 'rel="canonical" href="[^"]*"'    # proves APP_URL is correct
curl -s -o /dev/null -w '%{http_code}\n' $D/robots.txt
curl -s $D/sitemap.xml | grep -o '<sitemap>' | wc -l    # expect 6
curl -s $D/sitemap/courses-1.xml | grep -o '<url>' | wc -l
curl -s -o /dev/null -w '%{http_code}\n' $D/og-image.png
```

Check the raw HTML, not devtools — the point is that the tags are there without JavaScript.
`grep -c` counts matching *lines* and the XML is one long line, so use `grep -o | wc -l`.

Tests live in `tests/Feature/HeadTest.php` and `tests/Feature/SitemapTest.php`, and assert
against rendered HTML rather than Inertia props for the same reason.

## Search Console

The property is a **Domain** property (`golfcoursesapi.com`), verified by a TXT record.
DNS is hosted at **Hostinger**, not the registrar — the nameservers are
`horizon/orbit.dns-parking.com`, so Namecheap's Advanced DNS tab is inert. Add DNS records
in hPanel → Domains → DNS / Nameservers.

The root TXT record set also carries the mail SPF
(`v=spf1 include:_spf.mail.hostinger.com ~all`). Multiple root TXT records are fine —
don't replace that one or password-reset mail starts landing in spam.

Submit only `sitemap.xml`; Google discovers the chunks from the index.

## Known gaps

- **Error pages don't render `@head`.** 404s use Laravel's built-in error views, so the
  `noindex` registered via `Head::errors()` never reaches the HTML. The 404 *status* is
  what crawlers act on, so this is cosmetic — it resolves itself if error pages ever get
  routed through the app layout.
- **`streetAddress` is sometimes a full address.** For courses located by the Google Places
  pass, `courses.address` holds `formatted_address`, so the JSON-LD repeats locality and
  region. Valid, just redundant.
- **No per-course share image.** Every page uses the one static card.
