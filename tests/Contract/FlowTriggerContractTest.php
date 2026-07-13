<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Contract;

use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlowConnect\Contracts\FlowTrigger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins the FlowTrigger contract's shape: D-PR3 (ScheduleTrigger), D-PR4
 * (EventTrigger), D-PR5 (WebhookTrigger) all implement this interface —
 * an accidental signature change here would silently break all three.
 */
final class FlowTriggerContractTest extends TestCase
{
    public function test_fire_method_signature_is_pinned(): void
    {
        $reflection = new ReflectionClass(FlowTrigger::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('fire'));

        $method = $reflection->getMethod('fire');
        $parameters = $method->getParameters();

        self::assertCount(3, $parameters);

        self::assertSame('flowName', $parameters[0]->getName());
        self::assertSame('string', (string) $parameters[0]->getType());
        self::assertFalse($parameters[0]->isOptional());

        self::assertSame('input', $parameters[1]->getName());
        self::assertSame('array', (string) $parameters[1]->getType());
        self::assertTrue($parameters[1]->isOptional());
        self::assertSame([], $parameters[1]->getDefaultValue());

        self::assertSame('options', $parameters[2]->getName());
        $optionsType = $parameters[2]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $optionsType);
        self::assertSame(FlowExecutionOptions::class, $optionsType->getName());
        self::assertTrue($optionsType->allowsNull());
        self::assertTrue($parameters[2]->isOptional());
        self::assertNull($parameters[2]->getDefaultValue());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    public function test_a_concrete_trigger_can_implement_the_contract(): void
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
