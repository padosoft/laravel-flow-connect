<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use RuntimeException;

final class ThrowingWebhookMapper implements WebhookInputMapper
{
    public function map(array $payload): array
    {
        throw new RuntimeException('webhook mapping failed on purpose');
    }
}
