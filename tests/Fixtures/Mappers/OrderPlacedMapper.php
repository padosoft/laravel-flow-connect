<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Events\OrderPlaced;

final class OrderPlacedMapper implements EventInputMapper
{
    public function map(object $event): array
    {
        assert($event instanceof OrderPlaced);

        return ['order_id' => $event->orderId];
    }
}
