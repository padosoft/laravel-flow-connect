<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;
use RuntimeException;

final class ThrowingMapper implements EventInputMapper
{
    public function map(object $event): array
    {
        throw new RuntimeException('mapping failed on purpose');
    }
}
