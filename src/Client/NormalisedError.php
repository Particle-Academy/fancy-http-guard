<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard\Client;

/** An error response as one sentence: the same shape as @particle-academy/fancy-http-errors. */
final class NormalisedError
{
    /** @param  array<string, string>  $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly string $sentence,
        public readonly array $fields,
        public readonly ?int $retryAfter,
        public readonly ?string $requestId,
    ) {}

    /** @return array{status: int, code: string, sentence: string, fields: array<string, string>, retryAfter: ?int, requestId: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'sentence' => $this->sentence,
            'fields' => $this->fields,
            'retryAfter' => $this->retryAfter,
            'requestId' => $this->requestId,
        ];
    }
}
