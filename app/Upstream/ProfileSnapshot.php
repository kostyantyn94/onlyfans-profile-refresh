<?php

namespace App\Upstream;

use Carbon\CarbonImmutable;

/** A validated profile from one upstream response. */
final readonly class ProfileSnapshot
{
    /**
     * @param  array<string, mixed>  $attributes  profile columns present in the response
     * @param  array<string, mixed>  $raw  the upstream profile object as received
     */
    public function __construct(
        public int $likes,
        public int $revision,
        public string $format,
        public array $attributes,
        public array $raw,
        // Set when Fans API answered from its cache: when that snapshot was taken.
        public ?CarbonImmutable $cachedAt = null,
    ) {}
}
