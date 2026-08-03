<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BillingController extends Controller
{
    private const SUBSCRIPTION = 'default';

    public function index(Request $request): Response
    {
        $user = $request->user();
        $subscription = $user->subscription(self::SUBSCRIPTION);

        return Inertia::render('settings/Billing', [
            'configured' => $this->stripeConfigured(),
            'currentPlan' => $user->planKey(),
            'plans' => $this->planCards(),
            'subscription' => $subscription ? [
                'active' => $subscription->active(),
                'on_grace_period' => $subscription->onGracePeriod(),
                'canceled' => $subscription->canceled(),
                'past_due' => $subscription->pastDue(),
                'ends_at' => $subscription->ends_at?->toDayDateTimeString(),
            ] : null,
            'invoices' => $this->invoices($user),
        ]);
    }

    /**
     * Start a hosted Stripe Checkout session for a paid plan.
     */
    public function checkout(Request $request): HttpResponse|RedirectResponse
    {
        $planKey = $this->validatedPlan($request);
        $user = $request->user();

        // Already on this exact plan with an active subscription — nothing to do.
        if ($user->subscribed(self::SUBSCRIPTION) && $user->planKey() === $planKey) {
            return back()->with('toast', ['type' => 'info', 'message' => "You're already on the {$planKey} plan."]);
        }

        // Existing subscriber changing tiers → swap in place (no new checkout).
        if ($user->subscribed(self::SUBSCRIPTION)) {
            return $this->swap($request);
        }

        $checkout = $user->newSubscription(self::SUBSCRIPTION, Plans::stripePriceFor($planKey))
            ->allowPromotionCodes()
            ->checkout([
                'success_url' => route('billing.index').'?checkout=success',
                'cancel_url' => route('billing.index').'?checkout=cancelled',
            ]);

        return Inertia::location($checkout->url);
    }

    /**
     * Upgrade/downgrade an existing subscription between paid tiers.
     */
    public function swap(Request $request): RedirectResponse
    {
        $planKey = $this->validatedPlan($request);
        $user = $request->user();

        if (! $user->subscribed(self::SUBSCRIPTION)) {
            return to_route('billing.index');
        }

        $user->subscription(self::SUBSCRIPTION)->swap(Plans::stripePriceFor($planKey));

        // Reflect immediately; the webhook will also confirm this.
        $user->forceFill(['plan' => $planKey])->save();

        return to_route('billing.index')->with('toast', [
            'type' => 'success',
            'message' => 'Your plan is now '.config("api.plans.{$planKey}.label", ucfirst($planKey)).'.',
        ]);
    }

    /**
     * Redirect to Stripe's hosted billing portal (update card, invoices, cancel).
     */
    public function portal(Request $request): HttpResponse
    {
        return Inertia::location(
            $request->user()->billingPortalUrl(route('billing.index'))
        );
    }

    private function validatedPlan(Request $request): string
    {
        $data = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $plan = $data['plan'];

        if (! array_key_exists($plan, Plans::paid())) {
            throw ValidationException::withMessages(['plan' => 'That plan is not available.']);
        }

        if (! Plans::stripePriceFor($plan)) {
            throw ValidationException::withMessages(['plan' => 'Billing for this plan is not configured yet.']);
        }

        return $plan;
    }

    private function stripeConfigured(): bool
    {
        return filled(config('cashier.secret'))
            && filled(Plans::stripePriceFor('pro'))
            && filled(Plans::stripePriceFor('max'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planCards(): array
    {
        $cards = [];

        foreach (Plans::all() as $key => $plan) {
            $cards[] = [
                'key' => $key,
                'label' => $plan['label'] ?? ucfirst($key),
                'price' => (float) ($plan['price'] ?? 0),
                'per_day' => (int) ($plan['per_day'] ?? 0),
                'per_minute' => (int) ($plan['per_minute'] ?? 0),
                'premium' => (bool) ($plan['premium'] ?? false),
                'billable' => (bool) ($plan['premium'] ?? false),
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function invoices($user): array
    {
        if (! $user->hasStripeId()) {
            return [];
        }

        try {
            return $user->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'date' => $invoice->date()->toFormattedDateString(),
                'total' => $invoice->total(),
                'url' => route('billing.invoice', $invoice->id),
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Stream a single invoice PDF.
     */
    public function invoice(Request $request, string $invoiceId): HttpResponse
    {
        return $request->user()->downloadInvoice($invoiceId, [
            'vendor' => config('app.name'),
            'product' => 'Fairway API',
        ]);
    }
}
