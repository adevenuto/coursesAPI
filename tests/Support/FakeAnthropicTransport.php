<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 stand-in for the Anthropic transport.
 *
 * The SDK discovers its own PSR-18 client rather than using Laravel's HTTP
 * client, so Http::fake() can't see its traffic. It does accept an injected
 * transporter though (RequestOptions::with(transporter:)), which is a cleaner
 * seam anyway: these tests assert on the real serialized request body the SDK
 * would have put on the wire.
 */
class FakeAnthropicTransport implements ClientInterface
{
    /** @var array<int, ResponseInterface> */
    private array $queue = [];

    /** @var array<int, RequestInterface> */
    public array $requests = [];

    /**
     * Queue a successful message response carrying $payload as the model's JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    public function pushParse(array $payload, string $stopReason = 'end_turn'): self
    {
        return $this->pushMessage(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $stopReason,
        );
    }

    public function pushMessage(string $text, string $stopReason = 'end_turn'): self
    {
        $this->queue[] = new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_'.bin2hex(random_bytes(6)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 5120, 'output_tokens' => 8240],
        ]));

        return $this;
    }

    public function pushError(int $status, string $type = 'authentication_error', string $message = 'invalid x-api-key'): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ]));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->queue)
            ?? new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'type' => 'error',
                'error' => ['type' => 'api_error', 'message' => 'FakeAnthropicTransport ran out of queued responses'],
            ]));
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    /**
     * The decoded body of the nth request the SDK actually sent.
     *
     * @return array<string, mixed>
     */
    public function requestBody(int $index = 0): array
    {
        $request = $this->requests[$index];
        $request->getBody()->rewind();

        return json_decode((string) $request->getBody(), true) ?? [];
    }
}
