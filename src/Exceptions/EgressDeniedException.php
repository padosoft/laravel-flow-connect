<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Exceptions;

/**
 * The egress guard refused a request: the target host is not allow-listed, does
 * not match the connection's host, or resolves to a private/reserved address.
 *
 * @api
 */
final class EgressDeniedException extends HttpNodeException {}
