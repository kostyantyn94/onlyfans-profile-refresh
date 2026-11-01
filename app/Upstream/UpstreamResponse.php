<?php

namespace App\Upstream;

final readonly class UpstreamResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $retryAfter,
        // When the request was sent, in Unix milliseconds.
        public int $sentAtMs,
        public int $durationMs,
    ) {}
}
