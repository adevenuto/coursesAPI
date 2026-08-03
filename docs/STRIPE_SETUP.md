# Stripe / Billing setup

Billing runs on [Laravel Cashier](https://laravel.com/docs/billing) with **hosted Stripe
Checkout**. Stripe owns the card form, PCI, 3DS, receipts, and the self-serve billing
portal (update card / cancel). The app only mirrors the subscription tier onto
`users.plan`, which drives API rate limits and premium gating.

Everything is env-driven — no code changes needed to go from test to live.

## 1. Get your API keys

Stripe Dashboard → **Developers → API keys** (start in **Test mode**).

```
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
```

## 2. Create the products & prices

**Recommended — one command.** With your keys in `.env`, run:

```bash
php artisan stripe:sync-products --write-env
```

It reads the paid plans from `config/api.php`, creates the Stripe products +
recurring monthly prices (Pro **$9.99**, Max **$19.99**), applies the required tax
code, and patches `STRIPE_PRICE_PRO` / `STRIPE_PRICE_MAX` into `.env`. It's
idempotent — matches prices by a stable lookup key, so re-running reuses them, and
if you change a plan's `price` in config it mints a new Stripe price and transfers
the lookup key. It refuses to touch a live account without confirmation.

> This is the same command you run in production: paste live keys, run it, done.
> Test and live modes are separate — you create products in each mode once.

Pricing lives in `config/api.php` (`plans.*.price` + `plans.*.stripe_price_id`); the
Stripe product name/tax code come from `config/api.php` `stripe.*`
(`STRIPE_PRODUCT_PREFIX`, `STRIPE_TAX_CODE`).

<details>
<summary>Manual alternative (dashboard)</summary>

Dashboard → **Product catalog → Add product**, create two recurring monthly prices
(**$9.99**, **$19.99**), set each product's **tax code** (SaaS – `txcd_10103001`),
then copy the Price IDs into `.env` as `STRIPE_PRICE_PRO` / `STRIPE_PRICE_MAX`.
</details>

## 3. Configure the webhook

The app keeps `users.plan` in sync from Stripe subscription events
(`app/Listeners/SyncPlanFromStripe.php`), so the webhook is required.

**Local (Stripe CLI):**

```bash
stripe login
stripe listen --forward-to http://localhost:8000/stripe/webhook
```

The CLI prints a signing secret — put it in `.env`:

```
STRIPE_WEBHOOK_SECRET=whsec_...
```

**Production:** Dashboard → **Developers → Webhooks → Add endpoint**
`https://YOUR_DOMAIN/stripe/webhook`, subscribe to at least:

- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `checkout.session.completed`
- `invoice.payment_succeeded`
- `invoice.payment_failed`

Copy that endpoint's signing secret into `STRIPE_WEBHOOK_SECRET`.

## 4. Enable the billing portal

Dashboard → **Settings → Billing → Customer portal** → activate it (allow plan changes,
cancellations, and payment-method updates). The "Manage billing" button uses this.

## 5. Try it

1. Sign in, go to **Settings → Billing**.
2. Pick Pro or Max → hosted Checkout. Use test card `4242 4242 4242 4242`, any future
   expiry/CVC/ZIP.
3. On return, the webhook flips your plan; the page shows the active tier and invoices.

## How the pieces fit

- **`config/api.php`** — single source of truth for plan limits, display price, and the
  Stripe price id per plan.
- **`app/Support/Plans.php`** — maps price id ⇄ plan key.
- **`BillingController`** — `checkout` (new subscription), `swap` (change tier),
  `portal` (manage/cancel), `invoice` (PDF).
- **`SyncPlanFromStripe`** — webhook listener; Stripe stays the source of truth for the
  tier, the app just mirrors it onto `users.plan`.

> Until keys are present the Billing page shows a "not configured" banner and checkout
> buttons stay disabled — safe to ship without keys.
