<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Triggers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Events\OrderPlaced;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\NotAMapper;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\OrderPlacedMapper;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\ThrowingMapper;
use Padosoft\LaravelFlowConnect\Triggers\EventTriggerRegistrar;

final class EventTriggerRegistrarTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow-connect.event_triggers', [
            ['event' => OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => OrderPlacedMapper::class],
        ]);
    }

    public function test_a_host_event_creates_a_run_with_the_mapped_input(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('fulfill-order', ['order_id' => 42], null)
                ->andReturn(null);
        });

        // Fires through the REAL, app-booted registration (proves the
        // service provider actually wired the listener, not just that
        // register() works in isolation).
        $this->app['events']->dispatch(new OrderPlaced(42));
    }

    public function test_an_entry_without_a_mapper_fires_with_empty_input(): void
    {
        $this->app['config']->set('laravel-flow-connect.event_triggers', [
            ['event' => OrderPlaced::class, 'flow' => 'fulfill-order'],
        ]);

        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('fulfill-order', [], null)
                ->andReturn(null);
        });

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, $this->app['config']->get('laravel-flow-connect.event_triggers'));

        $dispatcher->dispatch(new OrderPlaced(7));
    }

    public function test_a_mapper_that_throws_creates_no_run_and_is_logged(): void
    {
        Log::spy();

        $this->app['config']->set('laravel-flow-connect.event_triggers', [
            ['event' => OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => ThrowingMapper::class],
        ]);

        // No dispatch() expectation set: the mock will fail the test if
        // dispatch() is called at all, proving no run is created.
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, $this->app['config']->get('laravel-flow-connect.event_triggers'));

        // Must not throw despite the mapper failure — the exception must
        // never escape into the host application's own dispatch() call.
        $dispatcher->dispatch(new OrderPlaced(1));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'mapping/fire failed'))
            ->once();
    }

    public function test_a_fire_failure_is_caught_and_logged_not_thrown(): void
    {
        Log::spy();

        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \RuntimeException('flow rejected the input'));
        });

        // Must not throw despite the underlying dispatch() failure.
        $this->app['events']->dispatch(new OrderPlaced(42));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'mapping/fire failed'))
            ->once();
    }

    public function test_a_non_array_entry_is_skipped_not_a_fatal(): void
    {
        Log::spy();

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, ['not-an-array', 42, null]);

        $this->assertSame([], $dispatcher->getListeners('anything'));
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_missing_event_class_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, [['flow' => 'fulfill-order']]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_a_nonexistent_event_class_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, [
            ['event' => 'App\\Events\\NoSuchClass', 'flow' => 'fulfill-order'],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_missing_flow_name_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, [['event' => OrderPlaced::class]]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_a_mapper_not_implementing_the_interface_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, [
            ['event' => OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => NotAMapper::class],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_a_nonexistent_mapper_class_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(EventTriggerRegistrar::class);
        $dispatcher = new EventDispatcher($this->app);
        $registrar->register($dispatcher, [
            ['event' => OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => 'App\\NoSuchMapper'],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_the_registrar_is_bound_with_the_real_container(): void
    {
        $registrar = $this->app->make(EventTriggerRegistrar::class);

        self::assertInstanceOf(EventTriggerRegistrar::class, $registrar);
    }

    public function test_registered_via_the_contracts_dispatcher_interface(): void
    {
        // The provider resolves Dispatcher::class (the interface), not the
        // concrete Illuminate\Events\Dispatcher — confirm both resolve to
        // usable instances the registrar can register against.
        self::assertInstanceOf(Dispatcher::class, $this->app->make(Dispatcher::class));
    }
}
