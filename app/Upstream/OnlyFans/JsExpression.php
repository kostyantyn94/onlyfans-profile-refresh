<?php

namespace App\Upstream\OnlyFans;

/**
 * A parser and evaluator for the small subset of JavaScript expressions found
 * in OnlyFans' obfuscated signing code: numbers, strings, + - * / %, unary minus,
 * parentheses, calls f(a, b), member access x[y]. Nothing is executed: calls go
 * to a resolver given by the caller, and the digest the checksum is computed
 * from is a symbol, so the result is "sum of digest characters + constant".
 */
class JsExpression
{
    /** @var list<array{0: string, 1: string}> [type, text] */
    private array $tokens;

    private int $at = 0;

    // Set for linear(): the digest's name and length, the helper object and its entries.
    private ?string $digest = null;

    private int $digestLength = 0;

    private ?string $opsName = null;

    private array $ops = [];

    /** @param  \Closure(string, list<int|string>): (string|float)  $resolve  calls a named function with literal arguments */
    public function __construct(string $source, private \Closure $resolve)
    {
        $this->tokens = $this->tokenize($source);
    }

    /** Evaluate to a number (the string-array rotation check). */
    public function number(): float
    {
        $value = $this->evaluate();

        return $value[0] === 'num' ? $value[1] : throw new InvalidSigningRules('expression is not a number');
    }

    /**
     * Evaluate with $digest a symbol of $length characters and $opsName the helper object.
     *
     * @return array{coefficients: array<int, int>, constant: int}
     */
    public function linear(string $digest, int $length, string $opsName, array $ops): array
    {
        [$this->digest, $this->digestLength, $this->opsName, $this->ops] = [$digest, $length, $opsName, $ops];
        $value = $this->toLinear($this->evaluate());
        ksort($value[1]);

        return ['coefficients' => array_filter($value[1]), 'constant' => (int) $value[2]];
    }

    private function evaluate(): array
    {
        $value = $this->additive();

        if ($this->at !== count($this->tokens)) {
            throw new InvalidSigningRules('unexpected "'.$this->tokens[$this->at][1].'" in expression');
        }

        return $value;
    }

    private function additive(): array
    {
        $left = $this->multiplicative();

        while ($this->peek('+') || $this->peek('-')) {
            $operator = $this->tokens[$this->at++][1];
            $left = $this->apply($operator, $left, $this->multiplicative());
        }

        return $left;
    }

    private function multiplicative(): array
    {
        $left = $this->unary();

        while ($this->peek('*') || $this->peek('/') || $this->peek('%')) {
            $operator = $this->tokens[$this->at++][1];
            $left = $this->apply($operator, $left, $this->unary());
        }

        return $left;
    }

    private function unary(): array
    {
        if ($this->peek('-') || $this->peek('+')) {
            $operator = $this->tokens[$this->at++][1];
            $value = $this->unary();

            return $operator === '-' ? $this->apply('-', ['num', 0.0], $value) : $value;
        }

        return $this->postfix();
    }

    private function postfix(): array
    {
        $value = $this->primary();

        while (true) {
            if ($this->accept('[')) {
                $key = $this->additive();
                $this->expect(']');
                $value = $this->member($value, $key);
            } elseif ($this->accept('(')) {
                $args = [];

                if (! $this->accept(')')) {
                    do {
                        $args[] = $this->additive();
                    } while ($this->accept(','));

                    $this->expect(')');
                }

                $value = $this->invoke($value, $args);
            } else {
                return $value;
            }
        }
    }

    private function primary(): array
    {
        [$type, $text] = $this->tokens[$this->at++] ?? throw new InvalidSigningRules('expression ends early');

        return match (true) {
            $type === 'num' => ['num', (float) $text],
            $type === 'str' => ['str', $text],
            $type === 'id' && $text === $this->digest => ['digest'],
            $type === 'id' && $text === $this->opsName => ['ops'],
            $type === 'id' => ['fn', $text],
            $text === '(' => (function () {
                $value = $this->additive();
                $this->expect(')');

                return $value;
            })(),
            default => throw new InvalidSigningRules("unexpected \"{$text}\" in expression"),
        };
    }

    private function member(array $object, array $key): array
    {
        return match (true) {
            $object[0] === 'digest' && $key === ['str', 'length'] => ['num', (float) $this->digestLength],
            $object[0] === 'digest' && $key[0] === 'num' => ['char', $this->index($key[1])],
            $object[0] === 'char' && $key === ['str', 'charCodeAt'] => ['charCodeAt', $object[1]],
            $object[0] === 'ops' && $key[0] === 'str' && isset($this->ops[$key[1]]) => ['op', $this->ops[$key[1]]],
            $object[0] === 'fn' && $key[0] === 'str' => ['fn', $object[1].'.'.$key[1]],
            default => throw new InvalidSigningRules('unsupported member access'),
        };
    }

    private function invoke(array $callee, array $args): array
    {
        if ($callee[0] === 'charCodeAt') {
            return $args === [['num', 0.0]] ? ['lin', [$callee[1] => 1], 0.0] : throw new InvalidSigningRules('charCodeAt with an argument other than 0');
        }

        if ($callee[0] === 'op') {
            return count($args) === 2 && in_array($callee[1], ['+', '-', '%'], true)
                ? $this->apply($callee[1], $args[0], $args[1])
                : throw new InvalidSigningRules('unsupported helper call');
        }

        if ($callee[0] !== 'fn') {
            throw new InvalidSigningRules('call of a value that is not a function');
        }

        $literals = array_map(fn (array $arg) => match ($arg[0]) {
            'num' => (int) $arg[1],
            'str' => $arg[1],
            default => throw new InvalidSigningRules('non-literal argument'),
        }, $args);

        $result = ($this->resolve)($callee[1], $literals);

        return is_float($result) ? ['num', $result] : ['str', $result];
    }

    private function apply(string $operator, array $left, array $right): array
    {
        if ($left[0] === 'num' && $right[0] === 'num') {
            return ['num', match ($operator) {
                '+' => $left[1] + $right[1],
                '-' => $left[1] - $right[1],
                '*' => $left[1] * $right[1],
                '/' => $right[1] == 0 ? NAN : $left[1] / $right[1],
                '%' => $right[1] == 0 ? NAN : fmod($left[1], $right[1]),
            }];
        }

        if (! in_array($operator, ['+', '-'], true)) {
            throw new InvalidSigningRules("unsupported \"{$operator}\" on the digest");
        }

        [, $a, $ca] = $this->toLinear($left);
        [, $b, $cb] = $this->toLinear($right);
        $sign = $operator === '+' ? 1 : -1;

        foreach ($b as $index => $times) {
            $a[$index] = ($a[$index] ?? 0) + $sign * $times;
        }

        return ['lin', $a, $ca + $sign * $cb];
    }

    private function toLinear(array $value): array
    {
        return match ($value[0]) {
            'lin' => $value,
            'num' => ['lin', [], $value[1]],
            default => throw new InvalidSigningRules('not a number in the checksum'),
        };
    }

    private function index(float $value): int
    {
        if ($value < 0 || $value >= $this->digestLength || floor($value) !== $value) {
            throw new InvalidSigningRules('digest index out of range');
        }

        return (int) $value;
    }

    private function peek(string $text): bool
    {
        return ($this->tokens[$this->at] ?? null) === ['op', $text];
    }

    private function accept(string $text): bool
    {
        if ($this->peek($text)) {
            $this->at++;

            return true;
        }

        return false;
    }

    private function expect(string $text): void
    {
        $this->accept($text) ?: throw new InvalidSigningRules("expected \"{$text}\"");
    }

    /** @return list<array{0: string, 1: string}> */
    private function tokenize(string $source): array
    {
        $pattern = '/\s*(?:(\d+(?:\.\d+)?)|"((?:[^"\\\\]|\\\\.)*)"|([A-Za-z_$][\w$]*)|([-+*\/%()\[\],.]))/A';
        $tokens = [];
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (trim(substr($source, $offset)) === '') {
                break;
            }

            if (! preg_match($pattern, $source, $m, 0, $offset)) {
                throw new InvalidSigningRules('unreadable expression near "'.substr($source, $offset, 20).'"');
            }

            $offset += strlen($m[0]);

            if (($m[1] ?? '') !== '') {
                $tokens[] = ['num', $m[1]];
            } elseif (isset($m[2]) && $m[0] !== '' && str_contains($m[0], '"')) {
                $tokens[] = ['str', json_decode('"'.preg_replace('/\\\\x([0-9a-fA-F]{2})/', '\\u00$1', $m[2]).'"') ?? throw new InvalidSigningRules('unreadable string')];
            } elseif (($m[3] ?? '') !== '') {
                $tokens[] = ['id', $m[3]];
            } elseif ($m[4] === '.') {
                // a.b is a["b"]
                if (! preg_match('/\s*([A-Za-z_$][\w$]*)/A', $source, $name, 0, $offset)) {
                    throw new InvalidSigningRules('unexpected "."');
                }

                $offset += strlen($name[0]);
                array_push($tokens, ['op', '['], ['str', $name[1]], ['op', ']']);
            } else {
                $tokens[] = ['op', $m[4]];
            }
        }

        return $tokens;
    }
}
