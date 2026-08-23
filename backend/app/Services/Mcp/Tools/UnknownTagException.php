<?php

namespace App\Services\Mcp\Tools;

use RuntimeException;

/**
 * A tag a caller named that does not exist, or that they may not use. Carried
 * out of the filter building as an exception because a search with a silently
 * dropped tag filter returns too much — and looks like a valid answer.
 */
class UnknownTagException extends RuntimeException {}
