<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;

/**
 * Deliberately does NOT implement {@see EventInputMapper}
 * — used to prove the registrar rejects a "mapper" config value that isn't a
 * real mapper class, at registration time rather than only failing at fire
 * time.
 */
final class NotAMapper {}
