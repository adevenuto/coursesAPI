<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * Create (or reconcile) the Stripe products & recurring prices for each paid
 * plan defined in config/api.php, against whatever keys are currently in
 * .env. Idempotent: prices are matched by a stable lookup key, so re-running
 * reuses them. When a plan's configured price changes, a new Stripe price is
 * created and the lookup key transferred to it (Stripe prices are immutable).
 *
 * Usage:
 *   php artisan stripe:sync-products            # create/reconcile, print IDs
 *   php artisan stripe:sync-products --write-env  # also patch STRIPE_PRICE_* in .env
 */
class StripeSyncProducts extends Command
{
    protected $signature = 'stripe:sync-products {--write-env : Write the resulting price IDs into the .env file}';

    protected $description = 'Create/reconcile Stripe products and prices for the paid plans in config/api.php';

    public function handle(): int
    {
        $secret = config('cashier.secret');

        if (blank($secret)) {
            $this->error('STRIPE_SECRET is not set. Add your Stripe secret key to .env first.');

            return self::FAILURE;
        }

        $mode = str_starts_with((string) $secret, 'sk_live_') ? 'LIVE' : 'TEST';
        $this->line("Stripe mode: <options=bold>{$mode}</>");
        if ($mode === 'LIVE' && ! $this->confirm('You are using a LIVE key. Create/modify real products?', false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $stripe = new StripeClient($secret);
        $currency = strtolower((string) config('cashier.currency', 'usd'));
        $prefix = (string) config('api.stripe.product_prefix', 'GCA');
        $lookupPrefix = Str::slug($prefix, '_'); // "GCA" -> "gca"
        $taxCode = (string) config('api.stripe.tax_code');

        $envUpdates = [];

        foreach (config('api.plans', []) as $key => $plan) {
            if (! ($plan['premium'] ?? false)) {
                continue; // free tier isn't billed
            }

            $label = $plan['label'] ?? ucfirst($key);
            $amount = (int) round(((float) ($plan['price'] ?? 0)) * 100);
            $lookupKey = $lookupPrefix.'_'.$key.'_monthly';

            $price = $this->reconcilePrice($stripe, [
                'plan_key' => $key,
                'name' => trim($prefix.' '.$label),
                'amount' => $amount,
                'currency' => $currency,
                'lookup_key' => $lookupKey,
                'tax_code' => $taxCode,
            ]);

            $envVar = 'STRIPE_PRICE_'.strtoupper($key);
            $envUpdates[$envVar] = $price->id;

            $this->line(sprintf(
                '  %-4s %s  %s  →  <fg=green>%s</>',
                strtoupper($key),
                '$'.number_format($amount / 100, 2).'/mo',
                str_pad($price->product, 24),
                $price->id
            ));
        }

        if (empty($envUpdates)) {
            $this->warn('No paid plans found in config/api.php.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Add these to your .env:');
        foreach ($envUpdates as $var => $id) {
            $this->line("  <fg=cyan>{$var}</>={$id}");
        }

        if ($this->option('write-env')) {
            $this->writeEnv($envUpdates);
        } else {
            $this->newLine();
            $this->comment('Re-run with --write-env to patch these into .env automatically.');
        }

        return self::SUCCESS;
    }

    /**
     * Find or create the price for a plan, keeping tax code and amount in sync.
     *
     * @param  array<string, mixed>  $spec
     */
    private function reconcilePrice(StripeClient $stripe, array $spec): \Stripe\Price
    {
        $existing = $stripe->prices->all([
            'lookup_keys' => [$spec['lookup_key']],
            'limit' => 1,
        ]);

        if (! empty($existing->data)) {
            /** @var \Stripe\Price $price */
            $price = $existing->data[0];
            $productId = $price->product; // string id (not expanded)

            // Keep the product's tax code current.
            $stripe->products->update($productId, ['tax_code' => $spec['tax_code']]);

            // Prices are immutable — if the amount/currency changed, mint a new
            // price on the same product and transfer the lookup key to it.
            if ($price->unit_amount !== $spec['amount'] || $price->currency !== $spec['currency']) {
                $this->warn("  {$spec['plan_key']}: price changed, creating a new Stripe price and archiving the old one.");
                $new = $stripe->prices->create([
                    'unit_amount' => $spec['amount'],
                    'currency' => $spec['currency'],
                    'recurring' => ['interval' => 'month'],
                    'product' => $productId,
                    'lookup_key' => $spec['lookup_key'],
                    'transfer_lookup_key' => true,
                ]);
                $stripe->prices->update($price->id, ['active' => false]);

                return $new;
            }

            return $price;
        }

        // Nothing exists yet — create product + price atomically.
        return $stripe->prices->create([
            'unit_amount' => $spec['amount'],
            'currency' => $spec['currency'],
            'recurring' => ['interval' => 'month'],
            'lookup_key' => $spec['lookup_key'],
            'product_data' => [
                'name' => $spec['name'],
                'tax_code' => $spec['tax_code'],
            ],
        ]);
    }

    /**
     * Patch STRIPE_PRICE_* lines in the .env file in place.
     *
     * @param  array<string, string>  $updates
     */
    private function writeEnv(array $updates): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->error('.env not found — skipping write.');

            return;
        }

        $contents = file_get_contents($path);

        foreach ($updates as $var => $id) {
            $line = $var.'='.$id;
            if (preg_match('/^'.preg_quote($var, '/').'=.*$/m', $contents)) {
                $contents = preg_replace('/^'.preg_quote($var, '/').'=.*$/m', $line, $contents);
            } else {
                $contents .= PHP_EOL.$line;
            }
        }

        file_put_contents($path, $contents);
        $this->info('Updated .env with the price IDs.');
        $this->call('config:clear');
    }
}
