<?php

namespace App\Support\Scorecard;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use RuntimeException;

/**
 * Turns scorecard images into the structured parse described by ScorecardSchema.
 *
 * Shaped like App\Support\ReverseGeocoder: the key is injected rather than read
 * from config here, counters are public so a command can report them, a soft
 * failure returns null, and a misconfigured key throws instead of quietly
 * marking every scan failed.
 *
 * Output is constrained by the schema (structured outputs) rather than coaxed
 * out of prose, so there is no brittle JSON extraction — the only decode that
 * can fail is one the API itself guaranteed wouldn't.
 */
class ScorecardParser
{
    /**
     * Non-streaming, so this stays under the SDK's HTTP timeout. A dense
     * 18-hole card with six tees lands around 8k output tokens; the headroom
     * covers 27-hole cards, and anything past it is surfaced as a truncation
     * error rather than silently half-parsed.
     */
    private const MAX_TOKENS = 16000;

    public int $calls = 0;

    public function __construct(
        private readonly Client $client,
        private readonly string $model = 'claude-opus-5',
    ) {}

    /**
     * Parse one card from one or more images.
     *
     * @param  array<int, string>  $imagePaths  absolute paths to JPEG images
     * @param  string  $context  who this card is being read for, from
     *                           ScorecardSchema::courseContext(); "" when the
     *                           scan has no course yet.
     * @return array{parse: array<string,mixed>, usage: array<string,mixed>}
     *
     * @throws RuntimeException on a truncated, refused or unusable response
     */
    public function parse(array $imagePaths, string $context = ''): array
    {
        if ($imagePaths === []) {
            throw new RuntimeException('A scorecard scan needs at least one image.');
        }

        $content = [];
        foreach ($imagePaths as $path) {
            $content[] = ImageBlockParam::with(
                source: Base64ImageSource::with(
                    data: base64_encode((string) file_get_contents($path)),
                    mediaType: 'image/jpeg',
                ),
            );
        }
        // Instructions after the images: the model reads the card, then the rules.
        // Context last of all: it only qualifies rules already stated, and a card
        // that contradicts it should be reported rather than bent to fit.
        $content[] = TextBlockParam::with(text: ScorecardSchema::instructions());

        if (trim($context) !== '') {
            $content[] = TextBlockParam::with(text: $context);
        }

        try {
            $message = $this->client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $content]],
                model: $this->model,
                outputConfig: OutputConfig::with(
                    effort: 'high',
                    format: JSONOutputFormat::with(schema: ScorecardSchema::jsonSchema()),
                ),
            );
        } catch (APIStatusException $e) {
            // 401/403 is a configuration fault, not a bad scorecard. Fail loudly
            // so it's fixed once rather than recorded against every scan.
            if (in_array($e->status ?? 0, [401, 403], true)) {
                throw new RuntimeException(
                    'Anthropic rejected the API key. Check ANTHROPIC_API_KEY.', previous: $e
                );
            }

            throw new RuntimeException('Scorecard parsing failed: '.$e->getMessage(), previous: $e);
        }

        $this->calls++;

        $this->assertUsable($message->stopReason);

        return [
            'parse' => $this->decode($message->content),
            'usage' => [
                'input_tokens' => $message->usage->inputTokens ?? null,
                'output_tokens' => $message->usage->outputTokens ?? null,
                'model' => $message->model ?? $this->model,
            ],
        ];
    }

    private function assertUsable(?string $stopReason): void
    {
        if ($stopReason === 'max_tokens') {
            throw new RuntimeException(
                'The scorecard was too large to parse in one response. Try splitting the card across images.'
            );
        }

        if ($stopReason === 'refusal') {
            throw new RuntimeException('The model declined to parse this image.');
        }
    }

    /**
     * @param  array<int, mixed>  $content
     * @return array<string, mixed>
     */
    private function decode(array $content): array
    {
        foreach ($content as $block) {
            if (($block->type ?? null) !== 'text') {
                continue;
            }

            $decoded = json_decode((string) $block->text, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('The parse response contained no readable JSON.');
    }
}
