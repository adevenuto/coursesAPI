<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor-submitted course suggestions from the explorer's "Suggest a course"
 * modal — a missing course, a missing club, or a correction to one we have.
 *
 * The row is the record of truth, not the notification email: the mail is sent
 * inline and best-effort, so a misconfigured SMTP must not lose the submission.
 *
 * Location is stored twice, on purpose. The `*_id` columns are set only when the
 * submitter picked a real row out of the cascading lookups; the text columns keep
 * what they actually typed. So a null id sitting next to filled text is the
 * signal "needs manual research" — which is the queue this table exists to feed.
 * Nothing is ever rejected for failing to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_suggestions', function (Blueprint $table) {
            $table->id();

            // missing | correction
            $table->string('type', 20);

            $table->string('course_name');
            $table->string('club_name')->nullable();

            // The course a correction is about. nullOnDelete rather than cascade:
            // deleting a course must not erase a report we may not have actioned,
            // and the course_name snapshot keeps the row readable afterwards.
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();

            // Country first, matching the form's cascade order.
            $table->string('country', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('city', 120)->nullable();

            // Set only when the submitter picked a real row. Declared long-hand
            // rather than with foreignId()->constrained(): these are external
            // dr5hn dataset ids on non-incrementing primary keys, and the explicit
            // form says so — matching how the courses table declares the same three.
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->string('website')->nullable();
            $table->text('notes')->nullable();

            $table->string('submitter_email');

            // Set when the submitter happened to be signed in. Nulled rather
            // than cascaded: deleting an account shouldn't erase a course we
            // may already have acted on.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // new | accepted | rejected | spam — triage lives in the DB until
            // volume justifies a screen.
            $table->string('status', 20)->default('new');

            // Anonymised at write time by ApiIp::store() (IPv4 /24, IPv6 /48),
            // matching how api_requests treats a visitor IP under GDPR. The raw
            // address is only ever a throttle key, never a stored value.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Did the notification actually leave? The mail is best-effort, so
            // without these "nobody emailed me" and "nobody submitted anything"
            // look identical. A null mailed_at with a mail_error is a broken
            // mailer; both null on an old row means it was never attempted.
            $table->timestamp('mailed_at')->nullable();
            $table->text('mail_error')->nullable();

            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();

            // The only query this table has: "what's outstanding, newest first".
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_suggestions');
    }
};
