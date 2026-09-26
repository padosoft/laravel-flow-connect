<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Nodes;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Nodes\Support\PathResolver;
use Padosoft\LaravelFlowConnect\Nodes\TransformNode;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransformNodeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>|null the result map, or null when the node failed
     */
    private function transform(mixed $data, array $mapping, ?string &$error = null): ?array
    {
        $result = (new TransformNode)->execute(new NodeContext('run-1', 'def', 'node-1', ['data' => $data, 'mapping' => $mapping]));
        $error = $result->error?->getMessage();

        return $result->success ? $result->outputs['result'] : null;
    }

    private function order(): array
    {
        return [
            'id' => 7,
            'customer' => ['name' => 'Ada', 'email' => 'ada@example.test', 'vip' => 'true'],
            'items' => [['sku' => 'A', 'qty' => '2'], ['sku' => 'B', 'qty' => '3']],
            'note' => null,
        ];
    }

    public function test_paths_read_nested_values_and_the_whole_input(): void
    {
        $result = $this->transform($this->order(), [
            'email' => '$.customer.email',
            'firstSku' => '$.items.0.sku',
            'everything' => '$',
        ]);

        $this->assertSame('ada@example.test', $result['email']);
        $this->assertSame('A', $result['firstSku']);
        $this->assertSame($this->order(), $result['everything']);
    }

    public function test_a_wildcard_maps_over_a_list(): void
    {
        $this->assertSame(['A', 'B'], $this->transform($this->order(), ['skus' => '$.items.*.sku'])['skus']);
    }

    public function test_a_missing_path_is_null_unless_a_default_is_given(): void
    {
        $result = $this->transform($this->order(), [
            'plain' => '$.nope',
            'defaulted' => ['path' => '$.nope', 'default' => 'n/a'],
            'nullDefaulted' => ['path' => '$.note', 'default' => 'none'],
            'presentNull' => '$.note',
        ]);

        $this->assertNull($result['plain']);
        $this->assertSame('n/a', $result['defaulted']);
        $this->assertSame('none', $result['nullDefaulted']);
        $this->assertNull($result['presentNull']);
    }

    public function test_templates_interpolate_paths(): void
    {
        $result = $this->transform($this->order(), [
            'greeting' => 'Hello {{ $.customer.name }}, order #{{$.id}}',
            'missing' => 'x{{ $.nope }}y',
            'compound' => ['template' => 'items: {{ $.items.*.sku }}'],
            'flag' => 'vip={{ $.customer.vip }}',
        ]);

        $this->assertSame('Hello Ada, order #7', $result['greeting']);
        $this->assertSame('xy', $result['missing']);
        $this->assertSame('items: ["A","B"]', $result['compound']);
        $this->assertSame('vip=true', $result['flag']);
    }

    public function test_literals_and_explicit_values(): void
    {
        $result = $this->transform([], [
            'text' => 'plain text',
            'int' => 42,
            'bool' => false,
            'none' => null,
            'list' => ['value' => [1, 2, 3]],
            'looksLikePath' => ['value' => '$.not.evaluated'],
        ]);

        $this->assertSame(['text' => 'plain text', 'int' => 42, 'bool' => false, 'none' => null, 'list' => [1, 2, 3], 'looksLikePath' => '$.not.evaluated'], $result);
    }

    public function test_casts(): void
    {
        $result = $this->transform($this->order(), [
            'qty' => ['path' => '$.items.0.qty', 'cast' => 'int'],
            'price' => ['path' => '$.items.1.qty', 'cast' => 'float'],
            'idText' => ['path' => '$.id', 'cast' => 'string'],
            'vip' => ['path' => '$.customer.vip', 'cast' => 'bool'],
            'asJson' => ['path' => '$.customer', 'cast' => 'json'],
            'nullStaysNull' => ['path' => '$.note', 'cast' => 'int'],
        ]);

        $this->assertSame(2, $result['qty']);
        $this->assertSame(3.0, $result['price']);
        $this->assertSame('7', $result['idText']);
        $this->assertTrue($result['vip']);
        $this->assertSame('{"name":"Ada","email":"ada@example.test","vip":"true"}', $result['asJson']);
        $this->assertNull($result['nullStaysNull']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidMappings(): array
    {
        return [
            'empty mapping' => [[], 'non-empty object'],
            'list mapping' => [['a', 'b'], 'non-empty object'],
            'bad path' => [['x' => '$.a b'], 'invalid path'],
            'bad template placeholder' => [['x' => 'hi {{ name }}'], 'not a path'],
            'unknown spec key' => [['x' => ['path' => '$.a', 'oops' => 1]], 'unknown key'],
            'no source' => [['x' => ['cast' => 'int']], 'exactly one of'],
            'two sources' => [['x' => ['path' => '$.a', 'value' => 1]], 'exactly one of'],
            'unknown cast' => [['x' => ['value' => 1, 'cast' => 'money']], 'unknown `cast`'],
            'non numeric int' => [['x' => ['value' => 'abc', 'cast' => 'int']], 'could not cast'],
            'non bool' => [['x' => ['value' => 'maybe', 'cast' => 'bool']], 'could not cast'],
            'non string path' => [['x' => ['path' => 5]], 'must be a string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    #[DataProvider('invalidMappings')]
    public function test_an_invalid_mapping_fails_the_node_naming_the_key_not_the_data(array $mapping, string $fragment): void
    {
        $result = $this->transform(['secret' => 'S3CR3T'], $mapping, $error);

        $this->assertNull($result);
        $this->assertStringContainsString($fragment, (string) $error);
        $this->assertStringNotContainsString('S3CR3T', (string) $error);
    }

    public function test_the_failure_is_a_mapping_exception(): void
    {
        $result = (new TransformNode)->execute(new NodeContext('r', 'd', 'n', ['data' => [], 'mapping' => ['x' => '$.a b']]));

        $this->assertInstanceOf(MappingException::class, $result->error);
    }

    public function test_the_node_is_pure_and_runs_in_a_dry_run(): void
    {
        $result = (new TransformNode)->execute(new NodeContext('r', 'd', 'n', ['data' => ['a' => 1], 'mapping' => ['x' => '$.a']], true));

        $this->assertTrue($result->success);
        $this->assertFalse($result->dryRunSkipped);
        $this->assertSame(['x' => 1], $result->outputs['result']);
    }

    public function test_path_resolver_distinguishes_missing_from_null(): void
    {
        $this->assertTrue(PathResolver::isMissing(PathResolver::resolve(['a' => 1], '$.b')));
        $this->assertFalse(PathResolver::isMissing(PathResolver::resolve(['a' => null], '$.a')));
        $this->assertNull(PathResolver::resolve(['a' => null], '$.a'));
        $this->assertTrue(PathResolver::isMissing(PathResolver::resolve('scalar', '$.a')));
    }

    public function test_path_resolver_rejects_anything_that_is_not_a_plain_path(): void
    {
        foreach (['a.b', '$a', '$.', '$..a', '$.a[0]', '$.a b', '', '$.a()'] as $bad) {
            $this->assertFalse(PathResolver::isPath($bad), $bad);
        }

        $this->expectException(\InvalidArgumentException::class);
        PathResolver::resolve([], 'nope');
    }

    public function test_it_is_registered_with_the_declared_ports_and_derived_output(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('connect.transform');

        $this->assertSame(PortType::Any, $definition->input('data')?->type);
        $this->assertTrue($definition->input('data')->required);
        $this->assertSame(PortType::Json, $definition->input('mapping')?->type);
        $this->assertSame(PortType::Json, $definition->output('result')?->type);
        $this->assertSame(PortProvenance::Derived, $definition->output('result')->provenance);
    }

    public function test_it_runs_inside_a_graph_wired_from_another_node(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('first', 'connect.transform', ['data' => ['name' => 'Ada', 'n' => '5'], 'mapping' => ['greeting' => 'Hi {{ $.name }}', 'n' => ['path' => '$.n', 'cast' => 'int']]]),
                new GraphNode('second', 'connect.transform', ['mapping' => ['shout' => '$.greeting', 'twice' => '$.n']]),
            ],
            [new Connection('first', 'result', 'second', 'data')],
        );

        $run = $this->app->make(GraphRunner::class)->run($graph, []);

        $this->assertSame(NodeState::Succeeded, $run->nodeStates['second']);
        $this->assertSame(['shout' => 'Hi Ada', 'twice' => 5], $run->nodeOutputs['second']['result']);
    }
}
