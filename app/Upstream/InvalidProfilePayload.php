<?php

namespace App\Upstream;

use RuntimeException;

/** The upstream answered, but not with a profile we can trust. */
class InvalidProfilePayload extends RuntimeException
{
}
