<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Exceptions;

use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestVerifier;
use RuntimeException;

/**
 * Raised by {@see WebhookRequestVerifier}
 * for any REQUEST-LEVEL rejection (bad/missing/expired signature, replayed
 * request, malformed JSON body) — as opposed to a payload-MAPPING failure
 * (an {@see WebhookInputMapper}
 * throwing), which is a distinct failure class handled separately by the
 * registrar.
 *
 * `$statusCode` is the HTTP status the route handler must respond with.
 * `getMessage()` is deliberately a GENERIC, safe-to-return-externally reason
 * (e.g. "invalid signature") — it is never the caller's own input echoed
 * back, so returning it in the HTTP response body cannot leak anything.
 *
 * @internal
 */
final class WebhookVerificationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}
