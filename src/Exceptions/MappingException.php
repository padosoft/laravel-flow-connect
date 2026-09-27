<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Exceptions;

use RuntimeException;

/**
 * A `connect.transform` / `connect.condition` mapping or rule is invalid, or a
 * value could not be cast. The message names the offending mapping key or rule
 * position, never the data itself (which may be sensitive).
 *
 * @api
 */
final class MappingException extends RuntimeException {}
