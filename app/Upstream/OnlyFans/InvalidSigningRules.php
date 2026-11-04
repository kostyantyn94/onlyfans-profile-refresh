<?php

namespace App\Upstream\OnlyFans;

use RuntimeException;

/** A rules source returned something that is not a usable set of signing rules. */
class InvalidSigningRules extends RuntimeException {}
