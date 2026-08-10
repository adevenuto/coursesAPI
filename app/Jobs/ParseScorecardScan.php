<?php

namespace App\Jobs;

use App\Models\ScorecardScan;
use App\Support\Scorecard\ScorecardParser;
use App\Support\Scorecard\ScorecardVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the vision parse for one scan and stages the result.
 *
 * Queued so the work is already shaped for a background worker, but production
 * runs `QUEUE_CONNECTION=sync` — Hostinger shared hosting has no worker and no
 * cron (docs/DEPLOY_HOSTINGER.md), so this executes inline in the parse request.
 * The page polls on status regardless, so switching to a cron-driven
 * `queue:work --stop-when-empty` later is a config change, not a rewrite.
 *
 * Never throws out of handle(): a failed parse is a state the editor needs to
 * see on the scan, not a 500.
 */
class ParseScorecardScan implements ShouldQueue
{
    use Queueable;

    /** One attempt — a re-parse is an explicit editor action, not an automatic retry. */
    public int $tries = 1;

    /**
     * A dense card takes 30-90s to read. `queue:work` defaults to killing a job
     * at 60s, so a worker would reap this mid-call and leave the scan stuck
     * while still billing for the request. Ignored under dispatchSync, but this
     * is the value that matters the day a worker is switched on.
     */
    public int $timeout = 300;

    public function __construct(public readonly int $scanId) {}

    public function handle(ScorecardParser $parser, ScorecardVerifier $verifier): void
    {
        $scan = ScorecardScan::find($this->scanId);

        if ($scan === null || $scan->status === ScorecardScan::STATUS_APPLIED) {
            return;
        }

        $scan->update(['status' => ScorecardScan::STATUS_PARSING, 'error' => null]);

        try {
            $reused = $this->reusableParse($scan);

            if ($reused !== null) {
                // An identical card has already been read. Copying the earlier
                // parse keeps a re-upload free. Verification is re-run rather
                // than copied, so a change to the rules applies to old parses too.
                $scan->update([
                    'status' => ScorecardScan::STATUS_PARSED,
                    'raw_parse' => $reused->raw_parse,
                    'model' => $reused->model,
                    'usage' => ['reused_from_scan' => $reused->id],
                    'verification' => $verifier->verify($reused->parsed() ?? []),
                ]);

                return;
            }

            $result = $parser->parse($scan->imagePaths());

            $scan->update([
                'status' => ScorecardScan::STATUS_PARSED,
                'raw_parse' => json_encode($result['parse'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'model' => $result['usage']['model'] ?? null,
                'usage' => $result['usage'],
                'verification' => $verifier->verify($result['parse']),
            ]);
        } catch (Throwable $e) {
            report($e);

            $scan->update([
                'status' => ScorecardScan::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * An earlier successful parse of byte-identical images, if there is one.
     */
    private function reusableParse(ScorecardScan $scan): ?ScorecardScan
    {
        return ScorecardScan::query()
            ->where('content_hash', $scan->content_hash)
            ->whereKeyNot($scan->id)
            ->whereNotNull('raw_parse')
            ->whereIn('status', [ScorecardScan::STATUS_PARSED, ScorecardScan::STATUS_APPLIED])
            ->latest('id')
            ->first();
    }
}
