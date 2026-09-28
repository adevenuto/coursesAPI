<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCourseSuggestionRequest;
use App\Mail\CourseSuggestionSubmitted;
use App\Models\CourseSuggestion;
use App\Support\ApiIp;
use App\Support\SuggestionGeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes "suggest a course" submissions from the explorer modal. The app's only
 * unauthenticated POST route — abuse is held off by the `suggestions` throttle
 * on the route plus the honeypot below.
 */
class CourseSuggestionController extends Controller
{
    public function store(StoreCourseSuggestionRequest $request): RedirectResponse|JsonResponse
    {
        // Honeypot: `contact_reference` is off-screen, so anything in it came
        // from a bot filling every field it found.
        //
        // Stored as spam rather than dropped, for two reasons. It shows how
        // much bot traffic this endpoint attracts, and — the one that matters —
        // if browser autofill ever trips the honeypot on a real person, their
        // suggestion is recoverable instead of silently gone.
        $isSpam = filled($request->input('contact_reference'));

        // Spread order matters: the resolver lands AFTER the validated input so
        // its authoritative overwrites win. A correction's location comes from
        // the course row, not from the readonly fields the client sent back.
        $suggestion = CourseSuggestion::create([
            ...$request->safe()->except('contact_reference'),
            ...SuggestionGeo::resolve($request->safe()->all()),
            'user_id' => $request->user()?->id,
            'status' => $isSpam ? CourseSuggestion::STATUS_SPAM : CourseSuggestion::STATUS_NEW,
            // Anonymised on the way in, per the same GDPR posture as
            // api_requests. The raw IP is only ever a throttle key.
            'ip' => ApiIp::store($request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
        ]);

        // A bot gets a byte-identical response to a person. Anything else —
        // an error, a different redirect, even a slower one — teaches it which
        // field to drop on the next attempt.
        if ($isSpam) {
            return $this->done($request);
        }

        // Sent inline, not queued: nothing drains the queue on this host, so a
        // queued mail would sit in `jobs` forever and silently — the same trap
        // ScorecardScanController sidesteps with dispatchSync.
        //
        // Wrapped because the row is the record of truth. A wrong SMTP password
        // must not cost us the suggestion or show the visitor a 500; it should
        // cost us only the notification, and say so in both the row and the log.
        try {
            Mail::to(config('mail.support_address'))
                ->send(new CourseSuggestionSubmitted($suggestion));

            $suggestion->forceFill(['mailed_at' => now()])->save();
        } catch (Throwable $e) {
            $suggestion->forceFill(['mail_error' => Str::limit($e->getMessage(), 500)])->save();

            Log::warning('Course suggestion email failed', [
                'suggestion_id' => $suggestion->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->done($request);
    }

    /**
     * The modal posts with Accept: application/json and renders its own
     * confirmation, so it wants a plain 200 rather than a redirect it would have
     * to follow. `back()` stays for any non-JSON caller.
     */
    private function done(StoreCourseSuggestionRequest $request): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back();
    }
}
