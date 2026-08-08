<?php

use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Every settings page is behind auth and must stay out of search results.
Route::withHead(robots: 'noindex, nofollow')->middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit')
        ->withHead(title: 'Profile settings');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // API keys / plan / usage
    Route::get('settings/api-keys', [ApiKeyController::class, 'index'])
        ->name('api-keys.index')
        ->withHead(title: 'API keys');
    Route::post('settings/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('settings/api-keys/{tokenId}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // Billing (Stripe / Cashier)
    Route::get('settings/billing', [BillingController::class, 'index'])
        ->name('billing.index')
        ->withHead(title: 'Billing');
    Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('settings/billing/swap', [BillingController::class, 'swap'])->name('billing.swap');
    Route::post('settings/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::get('settings/billing/invoice/{invoiceId}', [BillingController::class, 'invoice'])->name('billing.invoice');
});

Route::withHead(robots: 'noindex, nofollow')->middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit')
        ->withHead(title: 'Security settings');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')
        ->name('appearance.edit')
        ->withHead(title: 'Appearance settings');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
