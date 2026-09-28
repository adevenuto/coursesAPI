<?php

namespace Tests\Feature\Explorer;

use App\Mail\CourseSuggestionSubmitted;
use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\CourseSuggestion;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class CourseSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'missing',
            'course_name' => 'Royal Dornoch',
            'club_name' => 'Royal Dornoch Golf Club',
            'country' => 'Scotland',
            'state' => 'Highland',
            'city' => 'Dornoch',
            'website' => 'https://royaldornoch.com',
            'notes' => 'Championship course, not in your listings.',
            'submitter_email' => 'golfer@example.com',
        ], $overrides);
    }

    /** Geo rows + one course, mirroring ExplorerTest::seedGeo(). */
    private function seedGeo(): Course
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        State::create(['id' => 10, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'IL', 'latitude' => 40, 'longitude' => -89]);
        City::create(['id' => 100, 'name' => 'Lemont', 'state_id' => 10, 'state_name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 41.67, 'longitude' => -88.0]);

        return Course::create([
            'course_name' => 'Cog Hill No. 4', 'club_name' => 'Cog Hill Golf & Country Club',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 41.67, 'lng' => -88.0, 'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);
    }

    public function test_a_guest_can_submit_a_suggestion(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_suggestions', [
            'course_name' => 'Royal Dornoch',
            'type' => 'missing',
            'status' => CourseSuggestion::STATUS_NEW,
            'user_id' => null,
        ]);

        Mail::assertSent(
            CourseSuggestionSubmitted::class,
            fn (CourseSuggestionSubmitted $mail) => $mail->hasTo(config('mail.support_address'))
                // Reply in the support inbox answers the submitter, not us.
                && $mail->hasReplyTo('golfer@example.com'),
        );
    }

    public function test_a_signed_in_submitter_is_recorded(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/course-suggestions', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['user_id' => $user->id]);
    }

    /** The stored IP follows the same GDPR posture as api_requests. */
    public function test_the_stored_ip_is_anonymised(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->post('/course-suggestions', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['ip' => '203.0.113.0']);
        $this->assertDatabaseMissing('course_suggestions', ['ip' => '203.0.113.42']);
    }

    public function test_it_requires_a_course_name_and_a_valid_email(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload([
            'course_name' => '',
            'submitter_email' => 'not-an-email',
        ]))->assertSessionHasErrors(['course_name', 'submitter_email']);

        $this->assertDatabaseCount('course_suggestions', 0);
        Mail::assertNothingSent();
    }

    /**
     * Also pins the collapse of the old `missing_course` / `missing_club` pair
     * into one `missing` type — the form now offers a single "course/club that's
     * missing" option, so two stored values behind one label would be a lie.
     */
    public function test_an_unknown_type_is_rejected(): void
    {
        Mail::fake();

        foreach (['something_else', 'missing_course', 'missing_club'] as $type) {
            $this->post('/course-suggestions', $this->payload(['type' => $type]))
                ->assertSessionHasErrors('type');
        }

        $this->assertDatabaseCount('course_suggestions', 0);
    }

    /** The website lands in an email a human clicks, so only http(s) passes. */
    public function test_a_javascript_url_is_rejected(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload(['website' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('website');

        $this->assertDatabaseCount('course_suggestions', 0);
    }

    /**
     * A tripped honeypot must be indistinguishable from success — an error
     * would tell the bot which field to drop next time. The row is still
     * written so real submissions caught by autofill stay recoverable.
     */
    public function test_the_honeypot_is_stored_as_spam_and_sends_no_mail(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload(['contact_reference' => 'filled by a bot']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_suggestions', [
            'course_name' => 'Royal Dornoch',
            'status' => CourseSuggestion::STATUS_SPAM,
            'mailed_at' => null,
        ]);

        Mail::assertNothingSent();
    }

    /**
     * The defensive path that matters most: production mail is not configured
     * yet, so a throwing mailer is the likely first real-world case. The
     * suggestion must survive it, and the visitor must not see a 500.
     */
    public function test_a_failing_mailer_does_not_lose_the_suggestion(): void
    {
        Log::spy();

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP connection refused'));

        $this->post('/course-suggestions', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $suggestion = CourseSuggestion::sole();

        $this->assertSame(CourseSuggestion::STATUS_NEW, $suggestion->status);
        $this->assertNull($suggestion->mailed_at);
        $this->assertStringContainsString('SMTP connection refused', (string) $suggestion->mail_error);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_successful_send_is_recorded_on_the_row(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload())->assertRedirect();

        $this->assertNotNull(CourseSuggestion::sole()->mailed_at);
    }

    public function test_submissions_are_throttled_by_ip(): void
    {
        Mail::fake();

        foreach (range(1, 5) as $i) {
            $this->post('/course-suggestions', $this->payload(['submitter_email' => "golfer{$i}@example.com"]))
                ->assertRedirect();
        }

        $this->post('/course-suggestions', $this->payload(['submitter_email' => 'golfer6@example.com']))
            ->assertStatus(429);

        $this->assertDatabaseCount('course_suggestions', 5);
    }

    /**
     * Throttling runs before validation, so a fumbled submission spends a slot.
     * Blank-email attempts must therefore NOT share a bucket: keying on '' put
     * every visitor who forgot their email into one global counter, so five
     * fumbles locked the form site-wide for an hour.
     */
    public function test_blank_email_attempts_do_not_share_a_throttle_bucket(): void
    {
        Mail::fake();

        // Well past the old per-hour cap of 5, and all from different IPs so the
        // per-IP windows can't be what's being measured here.
        foreach (range(1, 9) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->post('/course-suggestions', $this->payload(['submitter_email' => '']))
                ->assertSessionHasErrors('submitter_email');
        }

        // A real submission from another visitor still gets through.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->post('/course-suggestions', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('course_suggestions', 1);
    }

    /** A keen contributor reporting several courses in one sitting is the point. */
    public function test_a_contributor_can_submit_several_courses_in_one_sitting(): void
    {
        Mail::fake();

        foreach (range(1, 8) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->post('/course-suggestions', $this->payload([
                    'course_name' => "Missing Links {$i}",
                    'submitter_email' => 'keen@example.com',
                ]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('course_suggestions', 8);
    }

    public function test_a_missing_suggestion_stores_resolved_geo_ids(): void
    {
        Mail::fake();
        $this->seedGeo();

        $this->post('/course-suggestions', $this->payload(['city_id' => 100]))->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['city_id' => 100, 'state_id' => 10, 'country_id' => 1]);
    }

    /**
     * The trio is read off the city row, so a client cannot post a city that
     * disagrees with its own state and country.
     */
    public function test_the_geo_trio_is_read_off_the_city_row(): void
    {
        Mail::fake();
        $this->seedGeo();

        $this->post('/course-suggestions', $this->payload([
            'city_id' => 100,
            'state_id' => 999999,
            'country_id' => 999999,
        ]))->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['city_id' => 100, 'state_id' => 10, 'country_id' => 1]);
    }

    public function test_a_state_without_a_city_still_resolves_its_country(): void
    {
        Mail::fake();
        $this->seedGeo();

        $this->post('/course-suggestions', $this->payload(['state_id' => 10]))->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['state_id' => 10, 'country_id' => 1, 'city_id' => null]);
    }

    /**
     * An id with no row behind it is dropped, never rejected: the visitor cannot
     * see or fix an invisible field, and the free text is still worth having.
     */
    public function test_unknown_geo_ids_are_dropped_not_rejected(): void
    {
        Mail::fake();
        $this->seedGeo();

        $this->post('/course-suggestions', $this->payload(['city_id' => 999999]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_suggestions', [
            'city_id' => null, 'state_id' => null, 'country_id' => null,
            'city' => 'Dornoch', 'state' => 'Highland', 'country' => 'Scotland',
        ]);
    }

    public function test_free_text_locations_survive_with_no_ids(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload())->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', [
            'country' => 'Scotland', 'state' => 'Highland', 'city' => 'Dornoch',
            'country_id' => null, 'state_id' => null, 'city_id' => null,
        ]);
    }

    /**
     * A correction's identity comes from the course row, not the form. The modal
     * renders those fields readonly, but readonly is a courtesy — this is what
     * makes it true.
     */
    public function test_a_correction_resolves_everything_from_the_course(): void
    {
        Mail::fake();
        $course = $this->seedGeo();

        $this->post('/course-suggestions', $this->payload([
            'type' => 'correction',
            'course_id' => $course->id,
            'course_name' => 'Totally Wrong Name',
            'club_name' => 'Injected Club',
            'country' => 'Nowhere',
            'state' => 'Nowhere',
            'city' => 'Nowhere',
            'notes' => 'Hole 7 is a par 3, not a par 4.',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_suggestions', [
            'course_id' => $course->id,
            'course_name' => 'Cog Hill No. 4',
            'club_name' => 'Cog Hill Golf & Country Club',
            'country' => 'United States',
            'state' => 'Illinois',
            'city' => 'Lemont',
            'country_id' => 1, 'state_id' => 10, 'city_id' => 100,
        ]);
    }

    /** The Algolia index lags the DB, so a deleted course is still searchable. */
    public function test_an_unknown_course_id_is_dropped_not_rejected(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload([
            'type' => 'correction',
            'course_id' => 999999,
            'notes' => 'Something is off.',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_suggestions', [
            'course_id' => null,
            'course_name' => 'Royal Dornoch',
        ]);
    }

    public function test_a_correction_requires_notes(): void
    {
        Mail::fake();
        $course = $this->seedGeo();

        $this->post('/course-suggestions', $this->payload([
            'type' => 'correction', 'course_id' => $course->id, 'notes' => '',
        ]))->assertSessionHasErrors('notes');

        $this->assertDatabaseCount('course_suggestions', 0);
    }

    public function test_a_missing_suggestion_does_not_require_notes(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload(['notes' => '']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_a_missing_suggestion_requires_a_country(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload(['country' => '']))
            ->assertSessionHasErrors('country');

        $this->assertDatabaseCount('course_suggestions', 0);
    }

    /** prepareForValidation nulls it, so a crafted POST can't half-fill one. */
    public function test_a_correction_sends_no_club_name_of_its_own(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload([
            'type' => 'correction',
            'course_id' => 999999,
            'club_name' => 'Injected Club',
            'notes' => 'Something is off.',
        ]))->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['club_name' => null]);
    }

    public function test_the_course_name_message_depends_on_the_type(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload(['course_name' => '']))
            ->assertSessionHasErrors(['course_name' => 'Tell us the name of the course or club.']);

        $this->post('/course-suggestions', $this->payload(['type' => 'correction', 'course_name' => '', 'notes' => 'x']))
            ->assertSessionHasErrors(['course_name' => 'Search for the course or club you want corrected.']);
    }

    public function test_a_percent_sign_in_a_field_is_stored_literally(): void
    {
        Mail::fake();

        $this->post('/course-suggestions', $this->payload(['course_name' => '100% Golf %_%']))->assertRedirect();

        $this->assertDatabaseHas('course_suggestions', ['course_name' => '100% Golf %_%']);
    }

    /**
     * Renders the Mailable for real against the array mailer — every other test
     * fakes mail, which never touches the Blade. A typo in the template or a
     * missing x-mail component would otherwise surface first in production.
     */
    public function test_the_notification_email_renders(): void
    {
        // Not faked, so the array mailer actually renders the Blade on send.
        $this->post('/course-suggestions', $this->payload())->assertRedirect();

        $suggestion = CourseSuggestion::sole();

        // A broken template would throw, be caught, and land here — so this
        // pair is the real assertion that the view compiles and sends.
        $this->assertNotNull($suggestion->mailed_at);
        $this->assertNull($suggestion->mail_error);

        $body = (new CourseSuggestionSubmitted($suggestion))->render();

        $this->assertStringContainsString('Royal Dornoch', $body);
        $this->assertStringContainsString('Dornoch, Highland, Scotland', $body);
        $this->assertStringContainsString('golfer@example.com', $body);
        $this->assertStringContainsString('Championship course', $body);
    }

    public function test_a_correction_email_reads_as_a_correction(): void
    {
        $course = $this->seedGeo();

        $this->post('/course-suggestions', $this->payload([
            'type' => 'correction',
            'course_id' => $course->id,
            'notes' => 'Hole 7 is a par 3.',
        ]))->assertRedirect();

        $suggestion = CourseSuggestion::sole();
        $body = (new CourseSuggestionSubmitted($suggestion))->render();

        $this->assertStringContainsString('Correction', $body);
        $this->assertStringContainsString('What needs fixing', $body);
        $this->assertStringContainsString('Hole 7 is a par 3.', $body);
        $this->assertStringContainsString('Cog Hill No. 4', $body);
        // The course link, so triage is one click from the inbox.
        $this->assertStringContainsString('/courses/'.$course->id, $body);
        $this->assertStringContainsString('Matched course #'.$course->id, $body);
    }

    public function test_a_missing_email_says_what_still_needs_research(): void
    {
        $this->post('/course-suggestions', $this->payload())->assertRedirect();

        $body = (new CourseSuggestionSubmitted(CourseSuggestion::sole()))->render();

        $this->assertStringContainsString('Missing course or club', $body);
        $this->assertStringContainsString('Royal Dornoch', $body);
        $this->assertStringContainsString('needs research', $body);
    }

    /**
     * The path the modal actually takes. It renders its own confirmation, so it
     * wants a plain 200 rather than a redirect it would have to follow.
     */
    public function test_a_json_submission_gets_a_plain_ok(): void
    {
        Mail::fake();

        $this->postJson('/course-suggestions', $this->payload())
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertDatabaseCount('course_suggestions', 1);
    }

    public function test_a_json_validation_failure_returns_422_with_field_errors(): void
    {
        Mail::fake();

        $this->postJson('/course-suggestions', $this->payload(['submitter_email' => 'nope']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('submitter_email');

        $this->assertDatabaseCount('course_suggestions', 0);
    }

    /** A bot must not be able to tell the honeypot tripped. */
    public function test_a_json_honeypot_submission_looks_identical_to_success(): void
    {
        Mail::fake();

        $this->postJson('/course-suggestions', $this->payload(['contact_reference' => 'bot']))
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Mail::assertNothingSent();
    }

    public function test_the_explorer_page_still_renders_for_guests(): void
    {
        $this->get('/explorer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Explorer')->where('canEdit', false));
    }
}
