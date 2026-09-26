<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Nodes;

use Illuminate\Config\Repository;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Nodes\BatchNode;
use PHPUnit\Framework\Attributes\DataProvider;

final class BatchNodeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function batch(array $inputs, ?int $max = null): NodeResult
    {
        $config = new Repository($max === null ? [] : ['laravel-flow-connect' => ['batch' => ['max_size' => $max]]]);

        return (new BatchNode($config))->execute(new NodeContext('run-1', 'def', 'node-1', $inputs));
    }

    public function test_it_splits_in_order_and_the_last_batch_may_be_shorter(): void
    {
        $result = $this->batch(['items' => [1, 2, 3, 4, 5], 'size' => 2]);

        $this->assertSame([[1, 2], [3, 4], [5]], $result->outputs['batches']);
        $this->assertSame(3, $result->outputs['count']);
    }

    public function test_an_exact_multiple_and_a_size_larger_than_the_list(): void
    {
        $this->assertSame([[1, 2], [3, 4]], $this->batch(['items' => [1, 2, 3, 4], 'size' => 2])->outputs['batches']);
        $this->assertSame([[1, 2]], $this->batch(['items' => [1, 2], 'size' => 50])->outputs['batches']);
    }

    public function test_an_empty_list_gives_no_batches(): void
    {
        $result = $this->batch(['items' => [], 'size' => 3]);

        $this->assertSame([], $result->outputs['batches']);
        $this->assertSame(0, $result->outputs['count']);
    }

    public function test_items_of_any_shape_are_kept_intact(): void
    {
        $items = [['id' => 1], 'text', null, 3.5, [1, 2]];

        $this->assertSame([[['id' => 1], 'text'], [null, 3.5], [[1, 2]]], $this->batch(['items' => $items, 'size' => 2])->outputs['batches']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'object not a list' => [['items' => ['a' => 1], 'size' => 2], '`items` must be a list'],
            'not an array' => [['items' => 'abc', 'size' => 2], '`items` must be a list'],
            'zero size' => [['items' => [1], 'size' => 0], 'positive integer'],
            'negative size' => [['items' => [1], 'size' => -3], 'positive integer'],
            'float size' => [['items' => [1], 'size' => 2.5], 'positive integer'],
            'string size' => [['items' => [1], 'size' => '2'], 'positive integer'],
            'over the limit' => [['items' => [1], 'size' => 11], 'exceeds the 10 limit'],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    #[DataProvider('invalid')]
    public function test_invalid_input_fails_the_node(array $inputs, string $fragment): void
    {
        $result = $this->batch($inputs, max: 10);

        $this->assertFalse($result->success);
        $this->assertInstanceOf(MappingException::class, $result->error);
        $this->assertStringContainsString($fragment, $result->error->getMessage());
    }

    public function test_the_default_limit_applies_without_config(): void
    {
        $this->assertTrue($this->batch(['items' => [1], 'size' => 1000])->success);
        $this->assertFalse($this->batch(['items' => [1], 'size' => 1001])->success);
    }

    public function test_it_is_registered_and_its_outputs_are_derived(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('connect.batch');

        $this->assertTrue($definition->input('items')?->required);
        $this->assertTrue($definition->input('size')?->required);
        $this->assertSame(PortType::Json, $definition->output('batches')?->type);
        $this->assertSame(PortType::Int, $definition->output('count')?->type);
        $this->assertSame(PortProvenance::Derived, $definition->output('batches')->provenance);
    }

    public function test_it_runs_in_a_graph_and_feeds_a_transform_and_a_dry_run(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('b', 'connect.batch', ['items' => [1, 2, 3, 4, 5], 'size' => 2]),
                new GraphNode('t', 'connect.transform', ['mapping' => ['firstBatch' => '$.0', 'all' => '$']]),
            ],
            [new Connection('b', 'batches', 't', 'data')],
        );

        foreach ([false, true] as $dryRun) {
            $run = $this->app->make(GraphRunner::class)->run($graph, [], null, $dryRun);

            $this->assertSame(NodeState::Succeeded, $run->nodeStates['t']);
            $this->assertSame([1, 2], $run->nodeOutputs['t']['result']['firstBatch']);
            $this->assertCount(3, $run->nodeOutputs['t']['result']['all']);
        }
    }
}
