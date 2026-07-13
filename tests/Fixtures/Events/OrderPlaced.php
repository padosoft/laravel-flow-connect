<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Events;

final class OrderPlaced
{
    public function __construct(
        public readonly int $orderId,
    ) {}
}
