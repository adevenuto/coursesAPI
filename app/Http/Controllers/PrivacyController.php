<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Public privacy policy.
 *
 * Required rather than optional: the API records IP addresses, user agents and
 * search terms against each request. The retention window and IP handling shown
 * on the page are read from config so the published commitment can't drift from
 * what `api:prune-requests` and ApiIp actually do.
 */
class PrivacyController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Privacy', [
            'retentionDays' => (int) config('api.analytics.retention_days', 90),
            'ipMode' => (string) config('api.analytics.ip_mode', 'anonymized'),
            'updated' => 'August 2026',
        ]);
    }
}
