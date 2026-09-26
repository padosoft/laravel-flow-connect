<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Nodes;

use Illuminate\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Nodes\DelayNode;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The delay node on top of core's engine-resumed timers: unit-level parsing and
 * limits, then the real thing end to end — a queued graph paused on a persisted
 * timer, resumed by the actual `flow:resume-due-timers` sweeper.
 */
final class DelayNodeTest extends TestCase
{
    private const NOW = '2026-10-01 10:00:00';

    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.executor.max_inline_delay_seconds', 5);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/vendor/padosoft/laravel-flow/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sleep::fake(false);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function node(array $inputs, ?int $max = null): NodeResult
    {
        $config = new Repository($max === null ? [] : ['laravel-flow-connect' => ['delay' => ['max_seconds' => $max]]]);

        return (new DelayNode($config))->execute(new NodeContext('run-1', 'def', 'node-1', $inputs));
    }

    public function test_seconds_resume_that_long_from_now_and_pass_the_input_through(): void
    {
        $result = $this->node(['seconds' => 90, 'in' => ['order' => 7]]);

        $this->assertTrue($result->paused);
        $this->assertSame(Carbon::parse(self::NOW)->addSeconds(90)->getTimestamp(), $result->resumeAt?->getTimestamp());
        $this->assertSame(['out' => ['order' => 7]], $result->outputs);
    }

    public function test_zero_seconds_resume_immediately(): void
    {
        $this->assertSame(Carbon::parse(self::NOW)->getTimestamp(), $this->node(['seconds' => 0])->resumeAt?->getTimestamp());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function timestamps(): array
    {
        return [
            'utc' => ['2026-10-01T12:00:00Z', 1_790_856_000],
            'offset' => ['2026-10-01T14:00:00+02:00', 1_790_856_000],
            'negative offset' => ['2026-10-01T07:00:00-05:00', 1_790_856_000],
            'fractional' => ['2026-10-01T12:00:00.500Z', 1_790_856_000],
            'max valid offset' => ['2026-10-02T02:00:00+14:00', 1_790_856_000],
        ];
    }

    #[DataProvider('timestamps')]
    public function test_until_parses_an_iso_8601_timestamp_with_an_offset(string $until, int $expected): void
    {
        $result = $this->node(['until' => $until]);

        $this->assertTrue($result->success, (string) $result->error?->getMessage());
        $this->assertSame($expected, $result->resumeAt?->getTimestamp());
    }

    public function test_a_time_already_past_is_not_an_error(): void
    {
        $result = $this->node(['until' => '2020-01-01T00:00:00Z']);

        $this->assertTrue($result->success);
        $this->assertLessThan(Carbon::parse(self::NOW)->getTimestamp(), $result->resumeAt?->getTimestamp());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'neither' => [[], 'exactly one'],
            'both' => [['seconds' => 5, 'until' => '2030-01-01T00:00:00Z'], 'exactly one'],
            'negative seconds' => [['seconds' => -1], 'non-negative integer'],
            'float seconds' => [['seconds' => 1.5], 'non-negative integer'],
            'string seconds' => [['seconds' => '5'], 'non-negative integer'],
            'no offset' => [['until' => '2030-01-01T00:00:00'], 'ISO-8601'],
            'date only' => [['until' => '2030-01-01'], 'ISO-8601'],
            'free text' => [['until' => 'next tuesday'], 'ISO-8601'],
            'overflowing date' => [['until' => '2030-02-31T00:00:00Z'], 'ISO-8601'],
            'impossible offset hours' => [['until' => '2026-10-01T10:00:00+99:99'], 'ISO-8601'],
            'offset beyond +14' => [['until' => '2026-10-01T10:00:00+15:00'], 'ISO-8601'],
            'impossible offset minutes' => [['until' => '2026-10-01T10:00:00+02:75'], 'ISO-8601'],
            'hour 24' => [['until' => '2026-10-01T24:00:00Z'], 'ISO-8601'],
            'minute 60' => [['until' => '2026-10-01T10:60:00Z'], 'ISO-8601'],
            'second 60' => [['until' => '2026-10-01T10:00:60Z'], 'ISO-8601'],
            'month 13' => [['until' => '2026-13-01T10:00:00Z'], 'ISO-8601'],
            'lowercase separators' => [['until' => '2026-10-01t10:00:00z'], 'ISO-8601'],
            'trailing junk' => [['until' => '2026-10-01T10:00:00Z junk'], 'ISO-8601'],
            'over the limit seconds' => [['seconds' => 10_000], 'exceeds the 3600 second limit'],
            'over the limit until' => [['until' => '2030-01-01T00:00:00Z'], 'more than 3600 seconds away'],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    #[DataProvider('invalid')]
    public function test_invalid_input_fails_the_node(array $inputs, string $fragment): void
    {
        $result = $this->node($inputs, max: 3600);

        $this->assertFalse($result->success);
        $this->assertInstanceOf(MappingException::class, $result->error);
        $this->assertStringContainsString($fragment, $result->error->getMessage());
    }

    public function test_it_is_registered_with_the_declared_ports(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('connect.delay');

        $this->assertSame(PortType::Int, $definition->input('seconds')?->type);
        $this->assertSame(PortType::Text, $definition->input('until')?->type);
        $this->assertFalse($definition->input('seconds')->required);
        $this->assertSame(PortType::Any, $definition->output('out')?->type);
    }

    /**
     * d (delay) -> t (transform)
     */
    private function delayThenTransform(int $seconds): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('d', 'connect.delay', ['seconds' => $seconds, 'in' => ['n' => 5]]),
                new GraphNode('t', 'connect.transform', ['mapping' => ['n' => '$.n']]),
            ],
            [new Connection('d', 'out', 't', 'data')],
        );
    }

    public function test_a_synchronous_run_sleeps_inline_within_the_cap(): void
    {
        $run = $this->app->make(GraphRunner::class)->run($this->delayThenTransform(3), []);

        $this->assertSame(NodeState::Succeeded, $run->nodeStates['d']);
        $this->assertSame(['n' => 5], $run->nodeOutputs['t']['result']);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_synchronous_run_fails_beyond_the_cap_telling_the_caller_to_queue_it(): void
    {
        $run = $this->app->make(GraphRunner::class)->run($this->delayThenTransform(3600), []);

        $this->assertSame(NodeState::Failed, $run->nodeStates['d']);
        $this->assertStringContainsString('run the graph queued', $run->errors['d']);
        Sleep::assertNeverSlept();
    }

    public function test_a_dry_run_never_waits(): void
    {
        $run = $this->app->make(GraphRunner::class)->run($this->delayThenTransform(3600), [], null, true);

        $this->assertSame(NodeState::Succeeded, $run->nodeStates['t']);
        Sleep::assertNeverSlept();
    }

    public function test_a_queued_run_pauses_on_a_persisted_timer_and_the_sweeper_resumes_it(): void
    {
        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($this->delayThenTransform(3600), []);

        $node = static fn (string $id): object => DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', $id)->first();

        // Paused on a persisted resume time; nothing downstream ran; no worker slept.
        $this->assertSame('paused', $node('d')->status);
        $this->assertNotNull($node('d')->resume_at);
        $this->assertSame('paused', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertNotSame('succeeded', $node('t')->status);
        Sleep::assertNeverSlept();

        // Not due yet: the sweeper leaves it alone.
        $this->artisan('flow:resume-due-timers')->expectsOutput('0 due timer(s) dispatched.')->assertExitCode(0);
        $this->assertSame('paused', $node('d')->status);

        // An hour later it resumes and the graph finishes.
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(3601));
        $this->artisan('flow:resume-due-timers')->expectsOutput('1 due timer(s) dispatched.')->assertExitCode(0);

        $this->assertSame('succeeded', $node('d')->status);
        $this->assertSame('succeeded', $node('t')->status);
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(['n' => 5], json_decode((string) $node('t')->outputs, true)['result']);
    }
}
