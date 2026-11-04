<?php

namespace App\Upstream\OnlyFans;

/**
 * Reads the signing rules out of OnlyFans' own web app code, as text: nothing
 * downloaded is ever executed.
 *
 * The signing module is obfuscated (javascript-obfuscator style): its strings sit
 * RC4-encrypted in an array that is rotated until a checksum of parseInt() values
 * matches; arithmetic hides behind an object of tiny functions ({abc: (W,n) =>
 * W+n}); and the checksum is one long expression of
 * sha1[NUMBER % sha1.length].charCodeAt(0) ± constant. This class undoes exactly
 * that: it decodes the strings the same way, finds the rotation, and evaluates the
 * checksum expression symbolically with a small parser, which gives the indexes
 * (with repeats) and the constant.
 *
 * Minified names change with every release, so the patterns match structure, not
 * names. If OnlyFans changes the structure, extraction fails loudly
 * (InvalidSigningRules) and the provider moves on to the next source.
 */
class WebAppRulesExtractor
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+/=';

    // The signing module is ~9 KB with a few hundred strings; far more is not that
    // module, and rotating a huge array would not fit in a job's time.
    private const MAX_MODULE_BYTES = 300_000;

    private const MAX_STRINGS = 5_000;

    /** @var list<string> the string array, rotated into place */
    private array $strings = [];

    private int $offset = 0;

    /**
     * Wrapper functions in scope, name => [target, index argument (0|1), added to the index, key argument (0|1)].
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: int}>
     */
    private array $wrappers = [];

    private string $decoder = '';

    /**
     * @param  string  $chunk  the script that holds the signing module
     * @param  ?string  $release  the web app release it belongs to (e.g. 202611031453-fbc506331f)
     *
     * @throws InvalidSigningRules
     */
    public function extract(string $chunk, string $appToken, string $source = '', ?string $release = null): SigningRules
    {
        if (strlen($chunk) > self::MAX_MODULE_BYTES) {
            throw new InvalidSigningRules('signing module unexpectedly large');
        }

        $this->strings = $this->stringArray($chunk);
        [$this->decoder, $this->offset] = $this->decoderFunction($chunk);
        $this->rotate($chunk);

        // The signing function: n.A=W=>{const n={...};function o(W,n){return i(W- -752,n)}...
        if (! preg_match('/=\w+=>\{const (\w+)=\{(.*?)\};(function \w+\(\w+,\w+\)\{return \w+\(\w+\s*-\s*-?\s*\d+,\w+\)\})/s', $chunk, $m, PREG_OFFSET_CAPTURE)) {
            throw new InvalidSigningRules('signing function not found');
        }

        $opsName = $m[1][0];
        $this->wrappers = [];
        $this->defineWrapper($m[3][0]);
        $ops = $this->opsObject($m[2][0]);
        $body = substr($chunk, $m[0][1]);

        // [n[o(..)], r[o(..)], t, e||0][o(..)]("\n"): static_param, time, path, user id.
        $call = '(\w+)\((-?\d+),"((?:[^"\\\\]|\\\\.)*)"\)';
        if (! preg_match('/\['.preg_quote($opsName, '/').'\['.$call.'\],\w+\['.$call.'\],\w+,\w+\|\|0\]\['.$call.'\]\("\\\\n"\)/', $body, $s)) {
            throw new InvalidSigningRules('signed message not found');
        }

        $staticParam = $ops[$this->call($s[1], (int) $s[2], $this->unescape($s[3]))] ?? null;

        // =[n[o(..)],k,function(W){...}(k),n[o(..)]][o(..)](":"): prefix, sha1, checksum, suffix.
        if (! preg_match('/=\['.preg_quote($opsName, '/').'\['.$call.'\],(\w+),function\((\w+)\)\{(.*?)\}\(\4\),'.preg_quote($opsName, '/').'\['.$call.'\]\]\['.$call.'\]\(":"\)/s', $body, $g)) {
            throw new InvalidSigningRules('signature assembly not found');
        }

        $prefix = $ops[$this->call($g[1], (int) $g[2], $this->unescape($g[3]))] ?? null;
        $suffix = $ops[$this->call($g[7], (int) $g[8], $this->unescape($g[9]))] ?? null;
        [$indexes, $constant] = $this->checksum($g[6], $g[5], $opsName, $ops);

        return SigningRules::fromArray([
            'static_param' => $staticParam,
            'prefix' => $prefix,
            'suffix' => $suffix,
            'checksum_indexes' => $indexes,
            'checksum_constant' => $constant,
            'app_token' => $appToken,
            'release' => $release,
        ], $source);
    }

    /** @return list<string> */
    private function stringArray(string $chunk): array
    {
        if (! preg_match('/function \w+\(\)\{const \w+=\[((?:"(?:[^"\\\\]|\\\\.)*",?)+)\];return\(\w+=function\(\)\{return \w+\}\)\(\)\}/', $chunk, $m)) {
            throw new InvalidSigningRules('string array not found');
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $all);

        if (count($all[1]) > self::MAX_STRINGS) {
            throw new InvalidSigningRules('string array unexpectedly large');
        }

        return array_map(fn ($s) => $this->unescape($s), $all[1]);
    }

    /** @return array{0: string, 1: int} the decoder's name and the offset it subtracts */
    private function decoderFunction(string $chunk): array
    {
        if (! preg_match('/function (\w+)\(\w+,\w+\)\{\w+-=(\d+);const \w+=\w+\(\);/', $chunk, $m)) {
            throw new InvalidSigningRules('string decoder not found');
        }

        return [$m[1], (int) $m[2]];
    }

    /** Rotate the array as the obfuscated code does, until its parseInt() checksum matches. */
    private function rotate(string $chunk): void
    {
        $pattern = '/!function\(\w+,\w+\)\{(function \w+\(\w+,\w+\)\{return \w+\(\w+\s*-\s*-?\s*\d+,\w+\)\})const \w+=\w+\(\);for\(;;\)try\{const \w+=void 0;if\((.+?)===\w+\)break;\w+\.push\(\w+\.shift\(\)\)\}catch\(\w+\)\{\w+\.push\(\w+\.shift\(\)\)\}\}\(\w+,(\d+)\)/s';

        if (! preg_match($pattern, $chunk, $m)) {
            throw new InvalidSigningRules('string array rotation not found');
        }

        $this->wrappers = [];
        $this->defineWrapper($m[1]);
        $target = (float) $m[3];

        for ($turn = 0; $turn < count($this->strings); $turn++) {
            try {
                $value = (new JsExpression($m[2], fn (string $name, array $args) => match ($name) {
                    'parseInt' => self::parseInt((string) $args[0]),
                    default => $this->call($name, ...$args),
                }))->number();

                if ($value === $target) {
                    return;
                }
            } catch (InvalidSigningRules) {
                // Like the original: a string that does not decode means "not yet".
            }

            $this->strings[] = array_shift($this->strings);
        }

        throw new InvalidSigningRules('no rotation of the string array matches its checksum');
    }

    /** "function o(W,n){return i(n- -194,W)}" → o(a, b) = i(b + 194, a). */
    private function defineWrapper(string $definition): void
    {
        if (! preg_match('/^function (\w+)\((\w+),(\w+)\)\{return (\w+)\((\w+)\s*-\s*(-?)\s*(\d+),(\w+)\)\}$/', $definition, $m)) {
            throw new InvalidSigningRules('unexpected wrapper: '.substr($definition, 0, 60));
        }

        [, $name, $first, , $target, $indexArg, $minus, $amount, $keyArg] = $m;

        $this->wrappers[$name] = [$target, $indexArg === $first ? 0 : 1, $minus === '-' ? (int) $amount : -(int) $amount, $keyArg === $first ? 0 : 1];
    }

    /** Call a wrapper or the decoder with literal arguments: returns the decoded string. */
    private function call(string $name, int|string ...$args): string
    {
        for ($depth = 0; $depth < 10 && isset($this->wrappers[$name]); $depth++) {
            [$target, $indexAt, $add, $keyAt] = $this->wrappers[$name];
            $args = [(int) $args[$indexAt] + $add, (string) $args[$keyAt]];
            $name = $target;
        }

        if ($name !== $this->decoder) {
            throw new InvalidSigningRules("call to an unknown function {$name}");
        }

        return $this->decode((int) $args[0] - $this->offset, (string) $args[1]);
    }

    /** The obfuscator's string decoding: its own base64, then RC4 with the call's key. */
    private function decode(int $index, string $key): string
    {
        $encoded = $this->strings[$index] ?? throw new InvalidSigningRules("string index {$index} out of range");

        $bytes = '';
        $count = 0;
        $buffer = 0;

        for ($e = 0, $length = strlen($encoded); $e < $length; $e++) {
            $digit = strpos(self::ALPHABET, $encoded[$e]);

            if ($digit === false) {
                continue;
            }

            $buffer = $count % 4 ? 64 * $buffer + $digit : $digit;

            if ($count++ % 4) {
                $bytes .= chr(255 & ($buffer >> ((-2 * $count) & 6)));
            }
        }

        // decodeURIComponent: the bytes are UTF-8, and malformed UTF-8 throws.
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new InvalidSigningRules('string does not decode');
        }

        $units = array_values(unpack('n*', mb_convert_encoding($bytes, 'UTF-16BE', 'UTF-8')) ?: []);

        $state = range(0, 255);
        $j = 0;
        $keyLength = strlen($key);

        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $state[$i] + ord($key[$i % $keyLength])) % 256;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }

        $out = [];
        $i = $j = 0;

        foreach ($units as $unit) {
            $i = ($i + 1) % 256;
            $j = ($j + $state[$i]) % 256;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $out[] = $unit ^ $state[($state[$i] + $state[$j]) % 256];
        }

        // UTF-16 code units, as JavaScript strings are: surrogate pairs stay pairs.
        return mb_convert_encoding(pack('n*', ...$out), 'UTF-8', 'UTF-16BE');
    }

    /**
     * The arithmetic helpers and constants: {abc: function(W,n){return W+n}, xyz: o(-290,"k")}.
     *
     * @return array<string, string> name => "+", "-", "%", "call" or a decoded string
     */
    private function opsObject(string $source): array
    {
        $ops = [];
        $call = '(\w+)\((-?\d+),"((?:[^"\\\\]|\\\\.)*)"\)';

        // Each entry must end at a comma or the end of the object: a value that goes on
        // (o(1,"a")+o(2,"b"), o(1,"a").split("")...) is left out, and whatever uses it fails.
        preg_match_all('/(?:^|,)(\w+):(?:function\((\w+),(\w+)(?:,\w+)*\)\{return ([^}]*)\}|'.$call.')(?=,|$)/', $source, $all, PREG_SET_ORDER);

        foreach ($all as $m) {
            if (($m[5] ?? '') !== '') {
                $ops[$m[1]] = $this->call($m[5], (int) $m[6], $this->unescape($m[7]));
            } else {
                $ops[$m[1]] = match ($m[4]) {
                    "{$m[2]}+{$m[3]}" => '+',
                    "{$m[2]}-{$m[3]}" => '-',
                    "{$m[2]}%{$m[3]}" => '%',
                    default => 'call',
                };
            }
        }

        return $ops;
    }

    /**
     * Evaluate the checksum expression with the digest as a symbol.
     *
     * @return array{0: list<int>, 1: int} indexes (repeated as often as they count) and the constant
     */
    private function checksum(string $body, string $digest, string $opsName, array $ops): array
    {
        // return Math[t(..)](EXPRESSION)[t(..)](16): Math.abs(...).toString(16)
        $call = '\w+\(-?\d+,"(?:[^"\\\\]|\\\\.)*"\)';

        if (! preg_match('/^(function \w+\(\w+,\w+\)\{return \w+\(\w+\s*-\s*-?\s*\d+,\w+\)\})return (\w+)\[('.$call.')\]\((.+)\)\[('.$call.')\]\(16\)$/s', $body, $m)) {
            throw new InvalidSigningRules('checksum function not understood');
        }

        $this->defineWrapper($m[1]);

        if ($m[2] !== 'Math' || $this->evaluateKey($m[3]) !== 'abs' || $this->evaluateKey($m[5]) !== 'toString') {
            throw new InvalidSigningRules('checksum is not Math.abs(...).toString(16)');
        }

        $sum = (new JsExpression($m[4], fn (string $name, array $args) => $this->call($name, ...$args)))
            ->linear($digest, 40, $opsName, $ops);

        $indexes = [];

        foreach ($sum['coefficients'] as $index => $times) {
            if ($times < 0) {
                throw new InvalidSigningRules('a digest character is subtracted; the rules format cannot express that');
            }

            array_push($indexes, ...array_fill(0, $times, $index));
        }

        sort($indexes);

        return [$indexes, $sum['constant']];
    }

    private function evaluateKey(string $expression): string
    {
        if (! preg_match('/^(\w+)\((-?\d+),"((?:[^"\\\\]|\\\\.)*)"\)$/', $expression, $m)) {
            throw new InvalidSigningRules('unexpected key: '.substr($expression, 0, 40));
        }

        return $this->call($m[1], (int) $m[2], $this->unescape($m[3]));
    }

    /** JavaScript's parseInt(): leading integer, or NaN. */
    public static function parseInt(string $value): float
    {
        return preg_match('/^\s*([+-]?\d+)/', $value, $m) ? (float) $m[1] : NAN;
    }

    private function unescape(string $literal): string
    {
        return self::jsString($literal);
    }

    /**
     * A JavaScript double-quoted string's content, unescaped. Escapes are read in one
     * pass, so an escaped backslash followed by "x41" stays that, not "A".
     *
     * @throws InvalidSigningRules
     */
    public static function jsString(string $literal): string
    {
        $json = preg_replace_callback('/\\\\(?:x([0-9a-fA-F]{2})|(.))/s',
            fn ($m) => ($m[1] ?? '') !== '' ? '\\u00'.$m[1] : '\\'.$m[2], $literal);

        return json_decode('"'.$json.'"') ?? throw new InvalidSigningRules('unreadable string literal');
    }
}
