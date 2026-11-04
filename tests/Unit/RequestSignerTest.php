<?php

namespace Tests\Unit;

use App\Upstream\OnlyFans\InvalidSigningRules;
use App\Upstream\OnlyFans\RequestSigner;
use App\Upstream\OnlyFans\SigningRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestSignerTest extends TestCase
{
    private function rules(array $overrides = []): SigningRules
    {
        $raw = json_decode(file_get_contents(__DIR__.'/../../resources/fixtures/upstream/onlyfans-test-rules.json'), true);

        return SigningRules::fromArray([...$raw, ...$overrides]);
    }

    public function test_it_matches_an_independent_implementation(): void
    {
        // Expected values computed separately in Python (hashlib) from the same rules.
        $signer = new RequestSigner;

        $this->assertSame(
            '12345:5492a1fe3cc82f7a3028eccc1aa97baf0eb8f97d:ac9:abcdef01',
            $signer->sign($this->rules(), '/api2/v2/users/madison420ivy', 1_762_250_000_000, '0'),
        );
        $this->assertSame(
            '12345:cdb0292e269dc729eaaebd76f62c4d0fbd6c6088:a6d:abcdef01',
            $signer->sign($this->rules(), '/api2/v2/users/madison420ivy?limit=10', 1_762_250_000_000, '123456'),
        );
    }

    public function test_every_signed_input_changes_the_signature(): void
    {
        $signer = new RequestSigner;
        $base = $signer->sign($this->rules(), '/api2/v2/users/a', 1000, '0');

        $this->assertNotSame($base, $signer->sign($this->rules(), '/api2/v2/users/b', 1000, '0'));
        $this->assertNotSame($base, $signer->sign($this->rules(), '/api2/v2/users/a', 1001, '0'));
        $this->assertNotSame($base, $signer->sign($this->rules(), '/api2/v2/users/a', 1000, '1'));
        $this->assertNotSame($base, $signer->sign($this->rules(['static_param' => 'OtherStaticParam0123456789abcdef']), '/api2/v2/users/a', 1000, '0'));
    }

    public function test_a_negative_checksum_is_written_as_its_absolute_value(): void
    {
        $sign = (new RequestSigner)->sign($this->rules(['checksum_constant' => -100_000]), '/api2/v2/users/a', 1000, '0');

        [, $sha1, $checksum] = explode(':', $sign);
        $sum = array_sum(array_map(fn ($i) => ord($sha1[$i]), $this->rules()->checksumIndexes));
        $this->assertSame(dechex(100_000 - $sum), $checksum);
    }

    public static function feedShapes(): array
    {
        $common = [
            'static_param' => 'UQe4c0nznfr5xt8EANtG9HyHmvMdqNRX',
            'checksum_constant' => 728,
            'checksum_indexes' => [2, 5, 6, 7, 8, 9, 11, 14, 14, 14, 15, 15, 16, 18, 22, 23, 24, 24, 25, 25, 26, 27, 27, 32, 33, 33, 34, 35, 35, 35, 38, 39],
        ];

        return [
            'prefix / suffix / app-token' => [[...$common, 'prefix' => '64937', 'suffix' => '6aa15875', 'app-token' => '33d57ade8c02dbc5a333db99ff9ae26a'], []],
            'start / end / app_token' => [[...$common, 'start' => '64937', 'end' => '6aa15875', 'app_token' => '33d57ade8c02dbc5a333db99ff9ae26a'], []],
            'format and remove_headers' => [[...$common, 'format' => '64937:{}:{:x}:6aa15875', 'app_token' => '33d57ade8c02dbc5a333db99ff9ae26a', 'remove_headers' => ['user_id']], ['user-id']],
        ];
    }

    #[DataProvider('feedShapes')]
    public function test_rules_are_read_from_every_published_shape(array $raw, array $removed): void
    {
        $rules = SigningRules::fromArray($raw, 'feed');

        $this->assertSame(['64937', '6aa15875', '33d57ade8c02dbc5a333db99ff9ae26a'], [$rules->prefix, $rules->suffix, $rules->appToken]);
        $this->assertSame($removed, $rules->removeHeaders);
        $this->assertSame(empty($removed), $rules->sends('user-id'));
    }

    public static function invalidRules(): array
    {
        return [
            'no static_param' => [['static_param' => null], 'static_param'],
            'index past the digest' => [['checksum_indexes' => [0, 40]], 'checksum_indexes'],
            'no indexes' => [['checksum_indexes' => []], 'checksum_indexes'],
            'constant as text' => [['checksum_constant' => '321'], 'checksum_constant'],
            'app token not hex' => [['app-token' => 'not-a-token'], 'app_token'],
            'prefix with a colon' => [['prefix' => '12:34'], 'prefix'],
        ];
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_rules_are_refused(array $overrides, string $field): void
    {
        $this->expectException(InvalidSigningRules::class);
        $this->expectExceptionMessage($field);

        $this->rules($overrides);
    }
}
