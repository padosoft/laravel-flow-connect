<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;

final class WebhookOrderMapper implements WebhookInputMapper
{
    public function map(array $payload): array
    {
        return ['order_id' => $payload['order']['id'] ?? null];
    }
}
