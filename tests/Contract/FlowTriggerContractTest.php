<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Contract;

use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The FlowTrigger contract lives in CORE, `Padosoft\LaravelFlow\Contracts\FlowTrigger`,
 * marked `@api` there — the canonical interface any trigger source
 * depends on, SemVer-covered once core cuts its first tagged release
 * (core has no tag yet; this package currently tracks an untagged dev
 * branch, see README). This package does NOT redefine the interface.
 * This test only asserts it is resolvable from core and that a concrete
 * connect trigger (D-PR3 ScheduleTrigger, D-PR4 EventTrigger, D-PR5
 * WebhookTrigger) can implement it; the actual signature is pinned by
 * core's own `tests/Contract/PublicApiContractTest.php`, not duplicated
 * here.
 */
final class FlowTriggerContractTest extends TestCase
{
    public function test_core_flow_trigger_contract_is_resolvable(): void
    {
        self::assertTrue(interface_exists(FlowTrigger::class));
        self::assertTrue((new ReflectionClass(FlowTrigger::class))->hasMethod('fire'));
    }

    public function test_a_concrete_connect_trigger_can_implement_the_core_contract(): void
    {
        $trigger = new class implements FlowTrigger
        {
            public function fire(string $flowName, array $input = [], ?FlowExecutionOptions $options = null): void
            {
                // A concrete trigger (D-PR3/4/5) calls Flow::dispatch() here.
            }
        };

        self::assertInstanceOf(FlowTrigger::class, $trigger);
    }
}
