<?php

namespace App\Upstream\OnlyFans;

use RuntimeException;

/**
 * OnlyFans' web app could not be downloaded (an HTTP error, no time left). Unlike
 * InvalidSigningRules, this says nothing about the release's code, so it is not
 * remembered: the next load tries again.
 */
class WebAppUnreachable extends RuntimeException {}
