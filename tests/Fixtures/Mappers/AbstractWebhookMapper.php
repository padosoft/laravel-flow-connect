<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers;

use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;

/**
 * Implements {@see WebhookInputMapper} but is ABSTRACT — used to prove the
 * registrar rejects a non-instantiable "mapper" config value at
 * registration time, not just a value that fails to implement the
 * interface at all.
 */
abstract class AbstractWebhookMapper implements WebhookInputMapper {}
