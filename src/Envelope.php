<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use Illuminate\Http\JsonResponse;

/**
 * The one error envelope (fancy-conformance suites/http/SPEC.md section 1):
 * `{error, message, field?, limit?, errors?, retryAfter?, requestId}`, with the
 * request id in the body AND the request-id header.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers,
    ) {}

    /** @param  array<string, mixed>  $extra */
    public static function make(int $status, string $error, string $message, string $requestId, string $requestIdHeader = 'x-request-id', ?int $retryAfter = null, array $extra = []): self
    {
        $body = ['error' => $error, 'message' => $message] + $extra;
        $headers = ['content-type' => 'application/json', $requestIdHeader => $requestId];
        if ($retryAfter !== null) {
            $body['retryAfter'] = $retryAfter;
            $headers['retry-after'] = (string) $retryAfter;
        }
        $body['requestId'] = $requestId;

        return new self($status, $body, $headers);
    }

    /** @param  list<array<string, mixed>>  $items */
    public static function validation(array $items, string $requestId, string $requestIdHeader = 'x-request-id'): self
    {
        $message = count($items) === 1 ? (string) $items[0]['message'] : count($items).' fields need attention.';

        return self::make(422, 'validation_failed', $message, $requestId, $requestIdHeader, null, ['errors' => $items]);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse($this->body, $this->status, $this->headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
