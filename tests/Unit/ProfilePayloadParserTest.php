<?php

namespace Tests\Unit;

use App\Upstream\InvalidProfilePayload;
use App\Upstream\ProfilePayloadParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePayloadParserTest extends TestCase
{
    private const FALLBACK_REVISION = 1_000;

    private function parse(string $body)
    {
        return (new ProfilePayloadParser)->parse($body, self::FALLBACK_REVISION);
    }

    public function test_real_fans_api_response_for_madison420ivy(): void
    {
        $snapshot = $this->parse($this->fixture('fansapi-madison420ivy.json'));

        $this->assertSame('fansapi', $snapshot->format);
        $this->assertSame(611_905, $snapshot->likes, 'likes come from data.favoritedCount');
        // Fans API has no revision; the snapshot time (_meta._cache.cached_at) orders responses.
        $this->assertSame(CarbonImmutable::parse('2026-11-01T08:12:09+00:00')->getTimestampMs(), $snapshot->revision);
        $this->assertSame(5_140_520, $snapshot->attributes['upstream_id']);
        $this->assertSame('Madison Ivy', $snapshot->attributes['name']);
        $this->assertSame(178, $snapshot->attributes['posts_count']);
        $this->assertSame(623, $snapshot->attributes['photos_count']);
        $this->assertSame(202, $snapshot->attributes['videos_count']);
        $this->assertSame(825, $snapshot->attributes['medias_count']);
        $this->assertTrue($snapshot->attributes['is_verified']);
        $this->assertSame(0.0, $snapshot->attributes['subscribe_price']);
        $this->assertSame('2018-11-11', $snapshot->attributes['joined_at']->toDateString());
        $this->assertStringStartsWith('https://public.onlyfans.com/', $snapshot->attributes['avatar_url']);
        $this->assertCount(87, $snapshot->raw, 'the whole profile object is kept');
    }

    public function test_fans_api_response_without_cache_timestamp_uses_the_request_time(): void
    {
        $snapshot = $this->parse(json_encode(['data' => ['favoritedCount' => 5], '_meta' => ['_cache' => ['is_cached' => false]]]));

        $this->assertSame(self::FALLBACK_REVISION, $snapshot->revision);
    }

    public function test_fans_api_response_without_favorited_count_is_invalid(): void
    {
        $this->expectExceptionObject(new InvalidProfilePayload('data.favoritedCount (likes) is missing'));

        $this->parse(json_encode(['data' => ['username' => 'x']]));
    }

    public function test_old_format(): void
    {
        $snapshot = $this->parse($this->fixture('old-format.json'));

        $this->assertSame([120_000, 10, 'old'], [$snapshot->likes, $snapshot->revision, $snapshot->format]);
        $this->assertSame('Fixture Creator', $snapshot->attributes['name']);
    }

    public function test_new_format(): void
    {
        $snapshot = $this->parse($this->fixture('new-format.json'));

        $this->assertSame([121_000, 11, 'new'], [$snapshot->likes, $snapshot->revision, $snapshot->format]);
        $this->assertSame('Fixture Creator', $snapshot->attributes['name']);
    }

    public function test_explicit_zero_likes_is_valid(): void
    {
        $this->assertSame(0, $this->parse($this->fixture('zero-likes.json'))->likes);
    }

    public function test_missing_likes_is_invalid(): void
    {
        $this->expectException(InvalidProfilePayload::class);
        $this->expectExceptionMessage('likes is missing');

        $this->parse($this->fixture('missing-likes.json'));
    }

    public function test_negative_likes_is_invalid(): void
    {
        $this->expectExceptionMessage('profile.likes is negative: -5');

        $this->parse($this->fixture('negative-likes.json'));
    }

    public function test_non_numeric_likes_is_invalid(): void
    {
        $this->expectExceptionMessage('profile.likes is not a whole number: "121k"');

        $this->parse($this->fixture('non-numeric-likes.json'));
    }

    public static function invalidLikes(): array
    {
        return [
            'fraction' => [1.5],
            'boolean' => [true],
            'null' => [null],
            'empty string' => [''],
            'number with spaces' => [' 12'],
            'array' => [[1]],
            'object' => [['n' => 1]],
            'negative string' => ['-3'],
            'exponent string' => ['1e5'],
        ];
    }

    #[DataProvider('invalidLikes')]
    public function test_likes_that_are_not_a_whole_non_negative_number_are_invalid(mixed $likes): void
    {
        $this->expectException(InvalidProfilePayload::class);

        $this->parse(json_encode(['profile' => ['likes' => $likes], 'revision' => 12]));
    }

    public function test_numeric_strings_and_whole_floats_are_accepted(): void
    {
        $this->assertSame(121_000, $this->parse('{"profile": {"likes": "121000"}, "revision": 12}')->likes);
        $this->assertSame(121_000, $this->parse('{"profile": {"likes": 121000.0}, "revision": 12}')->likes);
    }

    public function test_conflicting_likes_in_both_places_is_invalid(): void
    {
        $this->expectExceptionMessage('likes conflict: likes=1, profile.likes=2');

        $this->parse('{"likes": 1, "profile": {"likes": 2}, "revision": 12}');
    }

    public function test_the_same_likes_in_both_places_is_fine(): void
    {
        $this->assertSame(7, $this->parse('{"likes": 7, "profile": {"likes": 7}, "revision": 12}')->likes);
    }

    public function test_missing_or_bad_revision_is_invalid(): void
    {
        foreach (['{"likes": 1}', '{"likes": 1, "revision": -1}', '{"likes": 1, "revision": "eleven"}', '{"likes": 1, "revision": null}'] as $body) {
            try {
                $this->parse($body);
                $this->fail("accepted: {$body}");
            } catch (InvalidProfilePayload) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function malformedBodies(): array
    {
        return [
            'empty (HTTP 500 body)' => [''],
            'truncated' => ['{"profile": {"likes": 1210'],
            'html error page' => ['<html><body>502 Bad Gateway</body></html>'],
            'json list' => ['[{"likes": 1}]'],
            'json string' => ['"ok"'],
            'profile is a list' => ['{"profile": [1], "revision": 3}'],
            'data is a string' => ['{"data": "nope"}'],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function test_malformed_bodies_are_invalid(string $body): void
    {
        $this->expectException(InvalidProfilePayload::class);

        $this->parse($body);
    }

    public function test_truncated_fixture_is_invalid(): void
    {
        $this->expectExceptionMessage('not valid JSON');

        $this->parse($this->fixture('truncated.json'));
    }

    public function test_an_oversized_body_is_refused_before_decoding(): void
    {
        config(['refresh.http.max_body_bytes' => 100]);

        $this->expectExceptionMessage('larger than 100 bytes');

        $this->parse(json_encode(['likes' => 1, 'revision' => 1, 'about' => str_repeat('x', 200)]));
    }

    public function test_optional_fields_with_unexpected_types_are_skipped_not_fatal(): void
    {
        $snapshot = $this->parse(json_encode(['profile' => [
            'likes' => 10,
            'postsCount' => 'many',
            'isVerified' => 'yes',
            'avatar' => 'not a url',
            'name' => 'Still Fine',
            'lastSeen' => null,
        ], 'revision' => 3]));

        $this->assertSame(10, $snapshot->likes);
        $this->assertSame('Still Fine', $snapshot->attributes['name']);
        $this->assertArrayNotHasKey('posts_count', $snapshot->attributes, 'a bad optional field keeps the stored value');
        $this->assertArrayNotHasKey('is_verified', $snapshot->attributes);
        $this->assertArrayNotHasKey('avatar_url', $snapshot->attributes);
        $this->assertArrayHasKey('last_seen_at', $snapshot->attributes, 'an explicit null is passed on');
        $this->assertNull($snapshot->attributes['last_seen_at']);
    }
}
