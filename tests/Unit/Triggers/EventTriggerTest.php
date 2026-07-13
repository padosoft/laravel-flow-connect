<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Triggers;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Triggers\EventTrigger;

final class EventTriggerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    public function test_fire_dispatches_the_named_flow_with_the_given_input(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('fulfill-order', ['order_id' => 42], null)
                ->andReturn(null);
        });

        $this->app->make(EventTrigger::class)->fire('fulfill-order', ['order_id' => 42]);
    }

    public function test_fire_forwards_execution_options(): void
    {
        $options = new FlowExecutionOptions(correlationId: 'corr-1');

        $this->mock(FlowEngine::class, function ($mock) use ($options): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('fulfill-order', [], $options)
                ->andReturn(null);
        });

        $this->app->make(EventTrigger::class)->fire('fulfill-order', [], $options);
    }
}
