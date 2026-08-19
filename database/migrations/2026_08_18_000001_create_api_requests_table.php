<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request detail log for API analytics — a rolling window (see
 * config('api.analytics.retention_days')), pruned by `api:prune-requests`.
 *
 * Deliberately separate from `api_usage`, which stays the forever,
 * allowed-calls-only daily rollup that billing depends on. This table records
 * everything including throttled 429s, so the two will legitimately disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();

            // Cascades so deleting an account erases its detail rows — the
            // GDPR erasure path, matching api_usage.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // No foreign key on purpose. nullOnDelete would wipe per-key history
            // the moment a key is revoked, and cascadeOnDelete would delete the
            // traffic itself; the name snapshot keeps a revoked "Staging" key
            // readable in a 30-day report.
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('token_name', 50)->nullable();

            $table->string('method', 7);
            // route()->uri() — the API's routes are unnamed, and the uri form
            // groups /courses/17 and /courses/9000 into one bucket.
            $table->string('endpoint', 100);
            $table->unsignedSmallInteger('status');

            $table->unsignedMediumInteger('duration_ms')->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->unsignedInteger('result_count')->nullable();

            $table->string('ip', 45)->nullable();  // IPv6-safe
            $table->string('user_agent', 255)->nullable();
            // Derived bucket, so the clients panel groups over ~6 values instead
            // of hundreds of near-identical UA strings.
            $table->string('client', 32)->nullable();

            // Normalised at write time so "top search terms" is a plain GROUP BY
            // on a varchar — no JSON functions, portable across MySQL/MariaDB.
            $table->string('search_term', 120)->nullable();
            // Whitelisted request params only; never the raw query string.
            $table->json('query')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'created_at']); // every per-user panel
            $table->index(['status', 'created_at']);  // error / 429 drilldown
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
