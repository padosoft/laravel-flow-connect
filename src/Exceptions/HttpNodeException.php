<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Exceptions;

use RuntimeException;

/**
 * Base class of every failure the HTTP node reports. The message of each
 * subclass is written to be safe to persist and show: it names the connection
 * and the status or reason, and never carries a URL query, a header value, a
 * credential or a response body.
 *
 * @api
 */
class HttpNodeException extends RuntimeException {}
