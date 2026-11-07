<?php

namespace App\Upstream;

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
    ) {}
}
