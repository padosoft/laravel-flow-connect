<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Exceptions;

/**
 * A named HTTP connection is missing or malformed in
 * `laravel-flow-connect.http.connections`. Raised when a node USES the
 * connection, never at application boot, so one bad entry fails only the nodes
 * that reference it.
 *
 * @api
 */
final class InvalidHttpConnectionException extends HttpNodeException {}
