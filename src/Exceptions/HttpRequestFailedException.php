<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Exceptions;

/**
 * The HTTP request failed: a transport error, an error status with
 * `failOnError` set, or a response over the connection's size cap. The message
 * carries the connection name and the status only — never the URL, a header or
 * the response body, any of which can hold a secret.
 *
 * @api
 */
final class HttpRequestFailedException extends HttpNodeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function forStatus(string $connection, int $status): self
    {
        return new self(sprintf('HTTP request through connection [%s] failed with status %d.', $connection, $status), $status);
    }

    public static function forTransport(string $connection, string $exceptionClass): self
    {
        return new self(sprintf('HTTP request through connection [%s] failed: transport error (%s).', $connection, $exceptionClass));
    }

    public static function forOversizedResponse(string $connection, int $maxBytes): self
    {
        return new self(sprintf('HTTP response through connection [%s] exceeded the %d byte limit.', $connection, $maxBytes));
    }
}
