<?php

namespace Tests\Support;

/**
 * Builds a web app with the same structure as OnlyFans' (a page, app.js wiring
 * the request headers, and an obfuscated signing chunk), from rules chosen by
 * the test. Written here from scratch: no OnlyFans code is in the repository.
 *
 * The chunk has what WebAppRulesExtractor has to undo: strings RC4-encrypted
 * and base64-encoded with the obfuscator's alphabet, in an array shipped rotated
 * and put back in place by a parseInt() checksum loop; wrapper functions that
 * shift string indexes; an object of tiny arithmetic helpers; and the checksum
 * as sha1[NUMBER % sha1.length].charCodeAt(0) ± constant terms.
 */
final class SyntheticWebApp
{
    public const RELEASE = '202611040000-abcdef0123';

    public const BASE = 'https://static2.onlyfans.com/static/prod/f/'.self::RELEASE.'/';

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+/=';

    private const OFFSET = 300;

    /** @var list<array{0: string, 1: string}> [plain text, key] in their true order */
    private array $strings = [];

    /**
     * @param  array{static_param: string, prefix: string, suffix: string, checksum_indexes: list<int>, checksum_constant: int}  $rules
     */
    public function __construct(private array $rules, private int $rotation = 3, private bool $subtractACharacter = false) {}

    public function page(): string
    {
        return '<!doctype html><html><head><script defer=defer src='.self::BASE.'7777.js></script>'
            .'<script defer=defer src='.self::BASE.'chunk-vendors-0001.js></script>'
            .'<script defer=defer src='.self::BASE.'app.js></script></head><body></body></html>';
    }

    public function app(string $appToken = '0123456789abcdef0123456789abcdef'): string
    {
        return '(self.webpackChunkof_vue=self.webpackChunkof_vue||[]).push([[524],{'
            .'111:function(e,t,s){"use strict";var J=s(222);const Pe=1},'
            .'977434:function(e,t,s){"use strict";var J=s(807777),G=s(12);const Pe="'.$appToken.'";'
            .'const nt=async e=>{try{const t={...(0,J.A)(e)};t["app-token"]=Pe;t["x-bc"]="x";return t}catch(t){}return{}}}}]);';
    }

    /** The signing chunk (module 807777). */
    public function chunk(): string
    {
        $this->strings = [];
        $r = $this->rules;

        $plus = $this->name('Qplus');
        $plus2 = $this->name('Wplus');
        $minus = $this->name('Eminus');
        $mod = $this->name('Rmod');
        $call3 = $this->name('Tcall');
        $staticKey = $this->name('Ystat');
        $prefixKey = $this->name('Upref');
        $suffixKey = $this->name('Isuff');

        // Wrappers: in the rotation loop o(key, n) = i(n + 100, key); in the
        // signing function o(n, key) = i(n + 500, key); in the checksum t(n, key) = o(n - 50, key).
        $sign = fn (string $s) => $this->wrap($s, 500, 'o');
        $check = fn (string $s) => $this->wrap($s, 450, 't');

        $terms = [];
        $constants = $this->splitConstant(count($r['checksum_indexes']), $r['checksum_constant']);

        foreach ($r['checksum_indexes'] as $n => $index) {
            $number = 40 * (1690 + 3 * $n) + $index;
            $char = 'W['.($n % 2 ? "{$number}%W[".$check('length').']' : 'n['.$check($mod).']('.$number.',W['.$check('length').'])')
                .']['.$check('charCodeAt').'](0)';
            $c = $constants[$n];
            $terms[] = match ($n % 3) {
                0 => 'n['.$check($plus).']('.$char.','.$c.')',
                1 => $c < 0 ? '('.$char.'-'.(-$c).')' : '('.$char.'+'.$c.')',
                default => 'n['.$check($minus).']('.$char.','.(-$c).')',
            };
        }

        if ($this->subtractACharacter) {
            // 0 - (sha1[1] + sha1[1]): a digest character with a negative weight
            // (the test's rules do not use index 1).
            $char = 'W[1]['.$check('charCodeAt').'](0)';
            $terms[] = 'n['.$check($minus).'](0,n['.$check($plus).']('.$char.','.$char.'))';
        }

        $expression = array_shift($terms);
        foreach ($terms as $n => $term) {
            $expression = $n % 2 ? $expression.'+'.$term : 'n['.$check($n % 4 ? $plus2 : $plus).']('.$expression.','.$term.')';
        }

        $ops = implode(',', [
            $plus.':function(W,n){return W+n}',
            $minus.':function(W,n){return W-n}',
            $mod.':function(W,n){return W%n}',
            $plus2.':function(W,n){return W+n}',
            $call3.':function(W,n,o,t){return W(n,o,t)}',
            $staticKey.':'.$sign($r['static_param']),
            $prefixKey.':'.$sign($r['prefix']),
            $suffixKey.':'.$sign($r['suffix']),
        ]);

        $signing = 'n.A=W=>{const n={'.$ops.'};function o(W,n){return i(W- -500,n)}'
            .'const t=n['.$sign($call3).'](u(),W,'.$sign('url').',""),r={};r['.$sign('time').']=+new Date;'
            .'const e=n['.$sign($call3).'](u(),f.A,'.$sign('auth.user.id').',null),'
            .'k=d()([n['.$sign($staticKey).'],r['.$sign('time').'],t,e||0]['.$sign('join').']("\\n"));'
            .'return r['.$sign('sign').']=[n['.$sign($prefixKey).'],k,function(W){function t(W,n){return o(W-50,n)}'
            .'return Math['.$check('abs').']('.$expression.')['.$check('toString').'](16)}(k),'
            .'n['.$sign($suffixKey).']]['.$sign('join').'](":"),r}';

        // The rotation check: parseInt of two strings, which only line up in the true order.
        $first = $this->add('860000xyz');
        $second = $this->add('4000abc');
        $rotate = '!function(W,n){function o(W,n){return i(n- -100,W)}const t=W();for(;;)try{const W=void 0;'
            .'if(parseInt(o("'.$this->strings[$first][1].'",'.($first + self::OFFSET - 100).'))/1+'
            .'-parseInt(o("'.$this->strings[$second][1].'",'.($second + self::OFFSET - 100).'))/2===n)break;'
            .'t.push(t.shift())}catch(c){t.push(t.shift())}}(k,858000)';

        // Shipped rotated right; the loop's push(shift) turns it back $rotation times.
        $encoded = array_map(fn (array $s) => '"'.$this->encode($s[0], $s[1]).'"', $this->strings);
        $shift = $this->rotation % count($encoded);
        $shipped = $shift === 0 ? $encoded : [...array_slice($encoded, -$shift), ...array_slice($encoded, 0, count($encoded) - $shift)];

        return '(self.webpackChunkof_vue=self.webpackChunkof_vue||[]).push([[7777],{807777:function(W,n,o){'
            .'var t=o(1),c=o.n(t),r=o(2),d=o.n(r),e=o(3),u=o.n(e),f=o(4);'
            .'function k(){const W=['.implode(',', $shipped).'];return(k=function(){return W})()}'
            .self::decoder()
            .$rotate.','.$signing.'}}]);';
    }

    /**
     * i(index, key): the string at index - OFFSET, decoded as the obfuscator does it
     * (its base64 alphabet, the bytes as UTF-8, then RC4 with the key). A working
     * decoder, so the chunk really computes its signature when run
     * (synthetic-web-app-in-node.mjs checks that).
     */
    private static function decoder(): string
    {
        return 'function i(W,n){W-='.self::OFFSET.';const o=k();let t=o[W];'
            .'const a="'.self::ALPHABET.'";let r="",p="";'
            .'for(let c=0,e,f,g=0;f=t.charAt(g++);~f&&(e=c%4?64*e+f:f,c++%4)?r+=String.fromCharCode(255&e>>(-2*c&6)):0)f=a.indexOf(f);'
            .'for(let h=0;h<r.length;h++)p+="%"+("00"+r.charCodeAt(h).toString(16)).slice(-2);'
            .'t=decodeURIComponent(p);const s=[];let j=0,x,u="";'
            .'for(let q=0;q<256;q++)s[q]=q;'
            .'for(let q=0;q<256;q++){j=(j+s[q]+n.charCodeAt(q%n.length))%256;x=s[q];s[q]=s[j];s[j]=x}'
            .'for(let q=0,y=0,z=0;y<t.length;y++){q=(q+1)%256;z=(z+s[q])%256;x=s[q];s[q]=s[z];s[z]=x;'
            .'u+=String.fromCharCode(t.charCodeAt(y)^s[(s[q]+s[z])%256])}'
            .'return u}';
    }

    /** A call of wrapper $function that resolves to the string: its index is shifted by $shift on the way to i(). */
    private function wrap(string $plain, int $shift, string $function): string
    {
        $position = $this->add($plain);

        return $function.'('.($position + self::OFFSET - $shift).',"'.$this->strings[$position][1].'")';
    }

    private function name(string $base): string
    {
        return $base.substr(md5($base), 0, 3);
    }

    /** Adds a string (once per text) and returns its position in the true order. */
    private function add(string $plain): int
    {
        foreach ($this->strings as $position => [$text]) {
            if ($text === $plain) {
                return $position;
            }
        }

        $this->strings[] = [$plain, $this->key(count($this->strings))];

        return count($this->strings) - 1;
    }

    private function key(int $n): string
    {
        // Short keys with characters a JS string literal can hold unescaped.
        return substr(strtr(base64_encode(md5('key'.$n, true)), ['/' => '!', '+' => '#', '=' => '$']), 0, 4);
    }

    /** RC4 with the key, then the obfuscator's base64 (its own alphabet, no padding). */
    private function encode(string $plain, string $key): string
    {
        $state = range(0, 255);
        $j = 0;

        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $state[$i] + ord($key[$i % strlen($key)])) % 256;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }

        $units = '';
        $i = $j = 0;

        foreach (str_split($plain) as $char) {
            $i = ($i + 1) % 256;
            $j = ($j + $state[$i]) % 256;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $units .= mb_chr(ord($char) ^ $state[($state[$i] + $state[$j]) % 256], 'UTF-8');
        }

        $standard = rtrim(base64_encode($units), '=');

        return strtr($standard, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', substr(self::ALPHABET, 0, 64));
    }

    /** @return list<int> one constant per term, summing to $total */
    private function splitConstant(int $terms, int $total): array
    {
        $constants = [];

        for ($n = 0; $n < $terms - 1; $n++) {
            $constants[] = (($n * 37) % 120) - 60;
        }

        $constants[] = $total - array_sum($constants);

        return $constants;
    }
}
