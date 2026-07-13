<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Triggers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Triggers\ScheduleTriggerRegistrar;

final class ScheduleTriggerRegistrarTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow-connect.schedule_triggers', [
            ['flow' => 'daily-report', 'cron' => '0 6 * * *', 'input' => ['range' => 'yesterday']],
        ]);
    }

    public function test_a_valid_entry_registers_with_the_scheduler(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertSame('0 6 * * *', $events[0]->getExpression());
    }

    public function test_the_registered_callback_fires_the_flow_with_the_configured_input(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('daily-report', ['range' => 'yesterday'], null)
                ->andReturn(null);
        });

        // Re-boot with the mock in place: the provider's boot() already ran
        // during app bootstrap with the REAL FlowEngine bound, so exercise
        // the registered callback directly (the CallbackEvent's own filter/
        // callback closure) rather than re-registering the schedule.
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();
        $this->assertCount(1, $events);

        $events[0]->run($this->app);
    }

    public function test_malformed_cron_is_skipped_and_logged(): void
    {
        Log::spy();

        $this->app['config']->set('laravel-flow-connect.schedule_triggers', [
            ['flow' => 'bad-cron', 'cron' => 'not-a-cron-expression'],
        ]);

        // Re-register against a fresh Schedule to observe the skip (the app
        // already booted once in setUp with the original config).
        $schedule = new Schedule;
        $this->app->make(ScheduleTriggerRegistrar::class)
            ->register($schedule, $this->app['config']->get('laravel-flow-connect.schedule_triggers'));

        $this->assertCount(0, $schedule->events());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_missing_flow_name_is_skipped_and_logged(): void
    {
        Log::spy();

        $schedule = new Schedule;
        $this->app->make(ScheduleTriggerRegistrar::class)
            ->register($schedule, [['cron' => '* * * * *']]);

        $this->assertCount(0, $schedule->events());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_non_array_entry_is_skipped_not_a_fatal(): void
    {
        Log::spy();

        $schedule = new Schedule;
        $this->app->make(ScheduleTriggerRegistrar::class)
            ->register($schedule, ['not-an-array', 42, null]);

        $this->assertCount(0, $schedule->events());
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_an_invalid_timezone_identifier_is_skipped_and_logged(): void
    {
        Log::spy();

        $schedule = new Schedule;
        $this->app->make(ScheduleTriggerRegistrar::class)
            ->register($schedule, [
                ['flow' => 'daily-report', 'cron' => '0 6 * * *', 'timezone' => 'Not/ARealZone'],
            ]);

        $this->assertCount(0, $schedule->events());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_a_valid_timezone_identifier_is_applied(): void
    {
        $schedule = new Schedule;
        $this->app->make(ScheduleTriggerRegistrar::class)
            ->register($schedule, [
                ['flow' => 'daily-report', 'cron' => '0 6 * * *', 'timezone' => 'Europe/Rome'],
            ]);

        $events = $schedule->events();
        $this->assertCount(1, $events);
        $this->assertSame('Europe/Rome', (string) $events[0]->timezone);
    }

    public function test_a_fire_failure_is_caught_and_logged_not_thrown(): void
    {
        Log::spy();

        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \RuntimeException('flow rejected the input'));
        });

        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        // Must not throw despite the underlying dispatch() failure.
        $events[0]->run($this->app);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'fire() failed'))
            ->once();
    }
}
