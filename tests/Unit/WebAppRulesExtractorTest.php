<?php

namespace Tests\Unit;

use App\Upstream\OnlyFans\InvalidSigningRules;
use App\Upstream\OnlyFans\JsExpression;
use App\Upstream\OnlyFans\WebAppRulesExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticWebApp;

/** Reading signing rules out of obfuscated web app code, without running it. */
class WebAppRulesExtractorTest extends TestCase
{
    private const RULES = [
        'static_param' => 'Syn7heticStaticParam0123456789Ab',
        'prefix' => '13579',
        'suffix' => '2468aceb',
        'checksum_indexes' => [0, 3, 3, 5, 8, 11, 11, 11, 14, 17, 19, 20, 22, 24, 24, 25, 27, 28, 30, 31, 31, 32, 33, 34, 35, 36, 36, 37, 38, 38, 39, 39],
        'checksum_constant' => -1634,
    ];

    public static function rotations(): array
    {
        return ['shipped in order' => [0], 'rotated by 1' => [1], 'rotated by 17' => [17]];
    }

    #[DataProvider('rotations')]
    public function test_it_recovers_the_rules_the_code_was_built_from(int $rotation): void
    {
        $chunk = (new SyntheticWebApp(self::RULES, $rotation))->chunk();

        $rules = (new WebAppRulesExtractor)->extract($chunk, '0123456789abcdef0123456789abcdef', 'webapp:test', SyntheticWebApp::RELEASE);

        $this->assertSame(self::RULES['static_param'], $rules->staticParam);
        $this->assertSame([self::RULES['prefix'], self::RULES['suffix']], [$rules->prefix, $rules->suffix]);
        $this->assertSame(self::RULES['checksum_indexes'], $rules->checksumIndexes, 'indexes with their repeats');
        $this->assertSame(self::RULES['checksum_constant'], $rules->checksumConstant);
        $this->assertSame('0123456789abcdef0123456789abcdef', $rules->appToken);
        $this->assertSame(SyntheticWebApp::RELEASE, $rules->release);
    }

    public function test_the_code_is_never_run(): void
    {
        // Strings the code would only produce by running are not in the chunk itself.
        $chunk = (new SyntheticWebApp(self::RULES))->chunk();

        $this->assertStringNotContainsString(self::RULES['static_param'], $chunk);
        $this->assertStringNotContainsString('charCodeAt', $chunk);
    }

    public function test_a_subtracted_digest_character_is_refused(): void
    {
        $chunk = (new SyntheticWebApp(self::RULES, 2, subtractACharacter: true))->chunk();

        $this->expectException(InvalidSigningRules::class);
        $this->expectExceptionMessage('subtracted');

        (new WebAppRulesExtractor)->extract($chunk, '0123456789abcdef0123456789abcdef');
    }

    public static function brokenChunks(): array
    {
        $chunk = (new SyntheticWebApp(self::RULES))->chunk();

        return [
            'no string array' => [preg_replace('/function k\(\)\{const W=\[/', 'function k(){const W=Q([', $chunk, 1), 'string array not found'],
            'rotation never matches' => [str_replace('}(k,858000)', '}(k,858001)', $chunk), 'no rotation'],
            'other signing code' => [str_replace('("\\n")', '("|")', $chunk), 'signed message not found'],
        ];
    }

    #[DataProvider('brokenChunks')]
    public function test_changed_code_fails_loudly(string $chunk, string $message): void
    {
        $this->expectException(InvalidSigningRules::class);
        $this->expectExceptionMessage($message);

        (new WebAppRulesExtractor)->extract($chunk, '0123456789abcdef0123456789abcdef');
    }

    public function test_expressions_follow_javascript_arithmetic(): void
    {
        $resolve = fn (string $name, array $args) => match ($name) {
            'parseInt' => WebAppRulesExtractor::parseInt((string) $args[0]),
            's' => (string) $args[0],
        };

        $this->assertSame(-7.0, (new JsExpression('1+-2*4', $resolve))->number());
        $this->assertSame(1.5, (new JsExpression('parseInt(s("123abc"))/2/41', $resolve))->number());
        $this->assertNan((new JsExpression('parseInt(s("abc"))+1', $resolve))->number());
        $this->assertSame(2.0, (new JsExpression('(7%5)', $resolve))->number());
    }

    public function test_the_checksum_is_evaluated_as_a_sum_of_digest_characters(): void
    {
        $expression = new JsExpression('n.p(W[3].charCodeAt(0),5)+(W[81%W.length].charCodeAt(0)-2)+n.q(W[3].charCodeAt(0),10)', fn () => '');

        $sum = $expression->linear('W', 40, 'n', ['p' => '+', 'q' => '-']);

        $this->assertSame([1 => 1, 3 => 2], $sum['coefficients']);
        $this->assertSame(-7, $sum['constant']);
    }
}
