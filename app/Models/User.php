<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Billable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $plan
 * @property string $role
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The user's plan key (falls back to the configured default).
     */
    public function planKey(): string
    {
        $plan = $this->plan ?: (string) config('api.default_plan', 'free');

        return config()->has("api.plans.{$plan}") ? $plan : (string) config('api.default_plan', 'free');
    }

    /**
     * @return array<string, mixed>
     */
    public function planConfig(): array
    {
        return config('api.plans.'.$this->planKey(), []);
    }

    public function dailyLimit(): int
    {
        return (int) ($this->planConfig()['per_day'] ?? 250);
    }

    public function burstLimit(): int
    {
        return (int) ($this->planConfig()['per_minute'] ?? 30);
    }

    public function hasPremium(): bool
    {
        return (bool) ($this->planConfig()['premium'] ?? false);
    }

    // ---- Roles -------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEditor(): bool
    {
        return in_array($this->role, ['editor', 'admin'], true);
    }

    /**
     * Course editing is for editors/admins; editors must also be on a paid
     * plan (admins always qualify).
     */
    public function canEditCourses(): bool
    {
        return $this->isEditor() && ($this->hasPremium() || $this->isAdmin());
    }

    /**
     * When an account is deleted, end any live Stripe subscription (so a
     * deleted account is never billed again) and clean up local billing
     * records and API keys. A billing failure must never block the deletion.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            try {
                foreach ($user->subscriptions as $subscription) {
                    if ($subscription->active()) {
                        $subscription->cancelNow();
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Failed to cancel Stripe subscription on account deletion', [
                    'user_id' => $user->getKey(),
                    'stripe_id' => $user->stripe_id,
                    'message' => $e->getMessage(),
                ]);
            }

            // Remove local billing records and API keys tied to the account.
            foreach ($user->subscriptions()->get() as $subscription) {
                $subscription->items()->delete();
                $subscription->delete();
            }

            $user->tokens()->delete();
        });
    }
}
