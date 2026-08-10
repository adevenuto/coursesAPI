<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scorecard_scans', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete so a scan survives the uploader being deleted.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // NULL course_id means "this scan will create a new course".
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();

            // pending | parsing | parsed | failed | applied | discarded
            $table->string('status', 20)->default('pending')->index();

            // [{path, original_name, mime, bytes, width, height, sha256}]
            $table->json('images');
            // sha256 over the stored image hashes. Lets an identical re-upload
            // reuse an earlier parse instead of paying for the same call twice.
            $table->string('content_hash', 64)->index();

            // The model's response, stored verbatim as immutable evidence. Kept
            // even after apply so a bad mapping can be re-derived without respending.
            $table->longText('raw_parse')->nullable();
            // {passed: bool, issues: [{level, scope, message}]} from ScorecardVerifier.
            $table->json('verification')->nullable();

            $table->string('model', 60)->nullable();
            $table->json('usage')->nullable(); // {input_tokens, output_tokens, cost_estimate}
            $table->text('error')->nullable();

            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_revision_id')->nullable()
                ->constrained('course_revisions')->nullOnDelete();

            $table->timestamps();

            $table->index(['course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scorecard_scans');
    }
};
