<?php

namespace App\Upstream\OnlyFans;

use RuntimeException;

/**
 * No source has signing rules that OnlyFans has not been rejecting: requests
 * would only collect more rejections, so none is sent.
 */
class SigningRulesUnavailable extends RuntimeException {}
