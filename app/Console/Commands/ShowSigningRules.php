<?php

namespace App\Console\Commands;

use App\Upstream\OnlyFans\SigningRulesProvider;
use App\Upstream\OnlyFans\SigningRulesUnavailable;
use Illuminate\Console\Command;

class ShowSigningRules extends Command
{
    protected $signature = 'onlyfans:rules
        {--reload : Forget the rules in use and load them from the sources again}
        {--base-url= : Upstream to show the rules for (default: services.onlyfans.base_url)}';

    protected $description = 'Show the signing rules OnlyFans requests are signed with, and where they came from';

    public function handle(SigningRulesProvider $provider): int
    {
        $baseUrl = (string) ($this->option('base-url') ?: config('services.onlyfans.base_url'));

        if ($this->option('reload')) {
            $provider->forget($baseUrl);
        }

        try {
            $rules = $provider->for($baseUrl);
        } catch (SigningRulesUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // None of this is secret: it is what OnlyFans ships to every browser.
        $this->table(['Field', 'Value'], [
            ['upstream', $baseUrl],
            ['source', $rules->source],
            ['web app release', $rules->release ?? '-'],
            ['rules (fingerprint)', substr($rules->fingerprint(), 0, 12)],
            ['sign', "{$rules->prefix}:<sha1>:<checksum, hex>:{$rules->suffix}"],
            ['static param', $rules->staticParam],
            ['checksum', 'sha1 characters at '.implode(',', $rules->checksumIndexes).sprintf(' %+d', $rules->checksumConstant)],
            ['app-token', $rules->appToken],
            ['headers left out', implode(', ', $rules->removeHeaders) ?: '-'],
        ]);

        return self::SUCCESS;
    }
}
