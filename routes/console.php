<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Registered even though the host runs no cron today. It costs nothing while
 * nothing calls schedule:run, and it means retention starts working the moment
 * a cron entry is added rather than needing to be remembered then. Until that
 * happens the admin analytics page prunes opportunistically, at most once a day.
 *
 * See docs/API_ANALYTICS.md for the single cron line that activates this.
 */
Schedule::command('api:prune-requests')->dailyAt('03:10');
