<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;

/**
 * Deliberately does NOT implement {@see WebhookInputMapper}
 * — used to prove the registrar rejects a "mapper" config value that isn't a
 * real mapper class, at registration time rather than only failing at
 * request time.
 */
final class NotAWebhookMapper {}
