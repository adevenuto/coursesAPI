<?php

namespace Tests\Feature\Scorecard;

use Anthropic\Client as AnthropicClient;
use Anthropic\RequestOptions;
use App\Models\ScorecardScan;
use App\Models\User;
use App\Support\Scorecard\ScorecardParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

class ScorecardParseTest extends TestCase
{
    use RefreshDatabase;

    private FakeAnthropicTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ScorecardScan::DISK);

        $this->transport = new FakeAnthropicTransport;

        $this->app->bind(ScorecardParser::class, fn () => new ScorecardParser(
            new AnthropicClient(
                apiKey: 'test-key',
                requestOptions: RequestOptions::with(maxRetries: 0, transporter: $this->transport),
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/scorecards/bolingbrook.json')),
            true,
        );
    }

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
    }

    private function uploadAs(User $user): ScorecardScan
    {
        $this->actingAs($user)->post('/scorecard-scans', [
            'images' => [UploadedFile::fake()->image('card.jpg', 900, 600)],
        ]);

        return ScorecardScan::query()->latest('id')->firstOrFail();
    }

    public function test_parse_stores_the_response_verbatim(): void
    {
        $card = $this->fixture();
        $this->transport->pushParse($card);

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);

        $this->actingAs($editor)
            ->post("/scorecard-scans/{$scan->id}/parse")
            ->assertRedirect(route('scorecard-scans.show', $scan));

        $scan->refresh();

        $this->assertSame(ScorecardScan::STATUS_PARSED, $scan->status);
        $this->assertNull($scan->error);
        $this->assertSame($card, $scan->parsed());
        $this->assertSame('claude-opus-5', $scan->model);
        $this->assertSame(5120, $scan->usage['input_tokens']);
        $this->assertSame(1, $this->transport->callCount());

        // The card is re-checked in PHP, not taken on the model's word.
        $this->assertTrue($scan->verification['passed']);
        $this->assertSame([], $scan->verification['issues']);
    }

    public function test_a_card_that_does_not_reconcile_is_parsed_but_flagged(): void
    {
        $card = $this->fixture();
        $card['tees'][0]['yardage']['total'] += 30;
        $this->transport->pushParse($card);

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");

        $scan->refresh();

        // Still parsed — the editor needs to see it and decide, not be blocked.
        $this->assertSame(ScorecardScan::STATUS_PARSED, $scan->status);
        $this->assertFalse($scan->verification['passed']);
        $this->assertStringContainsString(
            'prints a total of',
            $scan->verification['issues'][0]['message'],
        );
    }

    public function test_a_reused_parse_is_re_verified_rather_than_copied(): void
    {
        $this->transport->pushParse($this->fixture());

        $editor = $this->editor();
        $first = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$first->id}/parse");

        // Blank the stored verification so a copy would be visibly wrong.
        $first->update(['verification' => null]);

        $second = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$second->id}/parse");

        $this->assertTrue($second->refresh()->verification['passed']);
        $this->assertSame(1, $this->transport->callCount());
    }

    public function test_request_carries_the_image_and_the_constrained_schema(): void
    {
        $this->transport->pushParse($this->fixture());

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");

        $body = $this->transport->requestBody();

        $this->assertSame('claude-opus-5', $body['model']);

        // Structured outputs, not tool use — the response shape is guaranteed.
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('high', $body['output_config']['effort']);
        $schema = $body['output_config']['format']['schema'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertContains('tees', $schema['required']);
        $this->assertContains('holes', $schema['required']);

        $content = $body['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('image/jpeg', $content[0]['source']['media_type']);
        $this->assertNotEmpty($content[0]['source']['data']);
        // Instructions come after the image so the card is read first.
        $this->assertSame('text', $content[1]['type']);
        $this->assertStringContainsString('COLUMN BY COLUMN', $content[1]['text']);
    }

    public function test_identical_images_reuse_an_earlier_parse_without_calling_the_api(): void
    {
        $this->transport->pushParse($this->fixture());

        $editor = $this->editor();

        $first = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$first->id}/parse");

        $second = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$second->id}/parse");

        $second->refresh();

        $this->assertSame(ScorecardScan::STATUS_PARSED, $second->status);
        $this->assertSame($first->refresh()->raw_parse, $second->raw_parse);
        $this->assertSame($first->id, $second->usage['reused_from_scan']);

        // The whole point: the second scan cost nothing.
        $this->assertSame(1, $this->transport->callCount());
    }

    public function test_a_rejected_api_key_fails_the_scan_with_a_pointed_message(): void
    {
        $this->transport->pushError(401);

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");

        $scan->refresh();

        $this->assertSame(ScorecardScan::STATUS_FAILED, $scan->status);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', (string) $scan->error);
        $this->assertNull($scan->raw_parse);
    }

    public function test_a_truncated_response_is_reported_rather_than_half_stored(): void
    {
        $this->transport->pushParse($this->fixture(), stopReason: 'max_tokens');

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");

        $scan->refresh();

        $this->assertSame(ScorecardScan::STATUS_FAILED, $scan->status);
        $this->assertStringContainsString('too large', (string) $scan->error);
        $this->assertNull($scan->raw_parse);
    }

    public function test_a_refusal_is_reported(): void
    {
        $this->transport->pushMessage('', stopReason: 'refusal');

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");

        $this->assertSame(ScorecardScan::STATUS_FAILED, $scan->refresh()->status);
        $this->assertStringContainsString('declined', (string) $scan->error);
    }

    public function test_a_failed_scan_can_be_parsed_again(): void
    {
        $this->transport->pushError(500, 'api_error', 'upstream blip')->pushParse($this->fixture());

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");
        $this->assertSame(ScorecardScan::STATUS_FAILED, $scan->refresh()->status);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");
        $scan->refresh();

        $this->assertSame(ScorecardScan::STATUS_PARSED, $scan->status);
        $this->assertNull($scan->error);
    }

    public function test_parsing_runs_inline_even_on_a_queued_connection(): void
    {
        // phpunit.xml forces QUEUE_CONNECTION=sync, which hides the case that
        // actually ships: every real .env uses `database`, and nothing drains
        // that queue. A plain dispatch() would file the job and leave the scan
        // stuck on "parsing" forever, so pin the inline behaviour explicitly.
        config(['queue.default' => 'database']);

        $this->transport->pushParse($this->fixture());

        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/parse");

        $this->assertSame(ScorecardScan::STATUS_PARSED, $scan->refresh()->status);
        $this->assertSame(1, $this->transport->callCount());
        $this->assertSame(0, DB::table('jobs')->count(), 'the parse must not be left sitting in the queue');
    }

    public function test_only_the_owner_or_an_admin_can_trigger_a_parse(): void
    {
        $scan = $this->uploadAs($this->editor());

        $this->actingAs($this->editor())
            ->post("/scorecard-scans/{$scan->id}/parse")
            ->assertForbidden();

        $this->assertSame(0, $this->transport->callCount());
        $this->assertSame(ScorecardScan::STATUS_PENDING, $scan->refresh()->status);
    }
}
