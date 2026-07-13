<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Triggers;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Triggers\ScheduleTrigger;

final class ScheduleTriggerTest extends TestCase
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
                ->with('daily-report', ['range' => 'yesterday'], null)
                ->andReturn(null);
        });

        $this->app->make(ScheduleTrigger::class)->fire('daily-report', ['range' => 'yesterday']);
    }

    public function test_fire_forwards_execution_options(): void
    {
        $options = new FlowExecutionOptions(correlationId: 'corr-1');

        $this->mock(FlowEngine::class, function ($mock) use ($options): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('daily-report', [], $options)
                ->andReturn(null);
        });

        $this->app->make(ScheduleTrigger::class)->fire('daily-report', [], $options);
    }
}
