<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Nodes;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Nodes\ConditionNode;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConditionNodeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function evaluate(mixed $value, array $rules, string $match = 'all'): bool
    {
        $result = (new ConditionNode)->execute(new NodeContext('run-1', 'def', 'node-1', ['value' => $value, 'rules' => $rules, 'match' => $match]));

        $this->assertTrue($result->success, (string) $result->error?->getMessage());
        $this->assertCount(1, $result->activePorts ?? []);

        return $result->activePorts === ['true'];
    }

    /**
     * @return array<string, array{mixed, array<string, mixed>, bool}>
     */
    public static function cases(): array
    {
        $order = ['total' => 120, 'status' => 'open', 'tags' => ['vip', 'eu'], 'note' => null, 'code' => '5', 'owner' => ['name' => 'Ada', 'age' => 36], 'empty' => ''];

        return [
            'eq string' => [$order, ['path' => '$.status', 'op' => 'eq', 'value' => 'open'], true],
            'eq numeric string vs int' => [$order, ['path' => '$.code', 'op' => 'eq', 'value' => 5], true],
            'eq different string' => [$order, ['path' => '$.status', 'op' => 'eq', 'value' => 'closed'], false],
            'eq null vs missing' => [$order, ['path' => '$.nope', 'op' => 'eq', 'value' => null], true],
            'eq bool is strict' => [['flag' => 1], ['path' => '$.flag', 'op' => 'eq', 'value' => true], false],
            'eq arrays ignore key order' => [$order, ['path' => '$.owner', 'op' => 'eq', 'value' => ['age' => 36, 'name' => 'Ada']], true],
            'neq' => [$order, ['path' => '$.status', 'op' => 'neq', 'value' => 'closed'], true],
            'gt' => [$order, ['path' => '$.total', 'op' => 'gt', 'value' => 100], true],
            'gt equal is false' => [$order, ['path' => '$.total', 'op' => 'gt', 'value' => 120], false],
            'gte equal' => [$order, ['path' => '$.total', 'op' => 'gte', 'value' => 120], true],
            'lt' => [$order, ['path' => '$.total', 'op' => 'lt', 'value' => 121], true],
            'lte' => [$order, ['path' => '$.total', 'op' => 'lte', 'value' => 119], false],
            'gt numeric string' => [$order, ['path' => '$.code', 'op' => 'gt', 'value' => 4], true],
            'lt strings' => [$order, ['path' => '$.status', 'op' => 'lt', 'value' => 'pending'], true],
            'gt incompatible is false' => [$order, ['path' => '$.status', 'op' => 'gt', 'value' => 5], false],
            'gt on missing is false' => [$order, ['path' => '$.nope', 'op' => 'gt', 'value' => 1], false],
            'in' => [$order, ['path' => '$.status', 'op' => 'in', 'value' => ['open', 'paused']], true],
            'in numeric' => [$order, ['path' => '$.total', 'op' => 'in', 'value' => ['120', 5]], true],
            'not_in' => [$order, ['path' => '$.status', 'op' => 'not_in', 'value' => ['closed']], true],
            'contains substring' => [$order, ['path' => '$.status', 'op' => 'contains', 'value' => 'pe'], true],
            'contains list member' => [$order, ['path' => '$.tags', 'op' => 'contains', 'value' => 'vip'], true],
            'contains missing member' => [$order, ['path' => '$.tags', 'op' => 'contains', 'value' => 'us'], false],
            'contains empty needle is false' => [$order, ['path' => '$.status', 'op' => 'contains', 'value' => ''], false],
            'starts_with' => [$order, ['path' => '$.status', 'op' => 'starts_with', 'value' => 'op'], true],
            'ends_with' => [$order, ['path' => '$.status', 'op' => 'ends_with', 'value' => 'en'], true],
            'starts_with on a non-string is false' => [$order, ['path' => '$.total', 'op' => 'starts_with', 'value' => '1'], false],
            'exists' => [$order, ['path' => '$.status', 'op' => 'exists'], true],
            'exists null is false' => [$order, ['path' => '$.note', 'op' => 'exists'], false],
            'exists missing is false' => [$order, ['path' => '$.nope', 'op' => 'exists'], false],
            'not_exists null' => [$order, ['path' => '$.note', 'op' => 'not_exists'], true],
            'empty null' => [$order, ['path' => '$.note', 'op' => 'empty'], true],
            'empty string' => [$order, ['path' => '$.empty', 'op' => 'empty'], true],
            'empty zero is not empty' => [['n' => 0], ['path' => '$.n', 'op' => 'empty'], false],
            'not_empty list' => [$order, ['path' => '$.tags', 'op' => 'not_empty'], true],
            'default path is the whole value' => ['hello', ['op' => 'starts_with', 'value' => 'he'], true],
            'wildcard path yields a list' => [['items' => [['sku' => 'A'], ['sku' => 'B']]], ['path' => '$.items.*.sku', 'op' => 'contains', 'value' => 'B'], true],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    #[DataProvider('cases')]
    public function test_operators(mixed $value, array $rule, bool $expected): void
    {
        $this->assertSame($expected, $this->evaluate($value, [$rule]), json_encode($rule, JSON_THROW_ON_ERROR));
    }

    public function test_match_all_and_any(): void
    {
        $rules = [['path' => '$.a', 'op' => 'eq', 'value' => 1], ['path' => '$.b', 'op' => 'eq', 'value' => 2]];

        $this->assertTrue($this->evaluate(['a' => 1, 'b' => 2], $rules, 'all'));
        $this->assertFalse($this->evaluate(['a' => 1, 'b' => 9], $rules, 'all'));
        $this->assertTrue($this->evaluate(['a' => 1, 'b' => 9], $rules, 'any'));
        $this->assertFalse($this->evaluate(['a' => 0, 'b' => 9], $rules, 'any'));
    }

    public function test_only_the_taken_port_is_activated_and_carries_the_value(): void
    {
        $node = new ConditionNode;
        $true = $node->execute(new NodeContext('r', 'd', 'n', ['value' => ['x' => 1], 'rules' => [['op' => 'exists']]]));
        $false = $node->execute(new NodeContext('r', 'd', 'n', ['value' => null, 'rules' => [['op' => 'exists']]]));

        $this->assertSame(['true'], $true->activePorts);
        $this->assertSame(['true' => ['x' => 1]], $true->outputs);
        $this->assertSame(['false'], $false->activePorts);
        $this->assertSame(['false' => null], $false->outputs);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidRules(): array
    {
        return [
            'empty' => [[], 'non-empty list'],
            'not a list' => [['op' => 'eq'], 'non-empty list'],
            'no op' => [[['path' => '$.a']], 'needs an `op`'],
            'unknown op' => [[['op' => 'matches', 'value' => '.*']], 'unknown operator'],
            'regex is not offered' => [[['op' => 'regex', 'value' => '/x/']], 'unknown operator'],
            'unknown key' => [[['op' => 'exists', 'oops' => 1]], 'unknown key'],
            'bad path' => [[['op' => 'exists', 'path' => 'a.b']], 'invalid `path`'],
            'missing value' => [[['op' => 'eq']], 'needs a `value`'],
            'in needs a list' => [[['op' => 'in', 'value' => 'a']], 'to be a list'],
            'invalid rule hidden behind a passing one' => [[['op' => 'exists', 'path' => '$'], ['op' => 'nope']], 'unknown operator'],
        ];
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_rules_fail_the_node(mixed $rules, string $fragment): void
    {
        $result = (new ConditionNode)->execute(new NodeContext('r', 'd', 'n', ['value' => ['a' => 1], 'rules' => $rules, 'match' => 'any']));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(MappingException::class, $result->error);
        $this->assertStringContainsString($fragment, $result->error->getMessage());
    }

    public function test_an_unknown_match_mode_fails_the_node(): void
    {
        $result = (new ConditionNode)->execute(new NodeContext('r', 'd', 'n', ['value' => 1, 'rules' => [['op' => 'exists']], 'match' => 'most']));

        $this->assertStringContainsString('"all" or "any"', (string) $result->error?->getMessage());
    }

    public function test_it_is_registered_with_a_true_and_a_false_port(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('connect.condition');

        $this->assertSame(PortType::Any, $definition->output('true')?->type);
        $this->assertSame(PortType::Any, $definition->output('false')?->type);
        $this->assertTrue($definition->input('rules')?->required);
        $this->assertFalse($definition->input('match')?->required);
    }

    /**
     * cond --true--> a (transform) --\
     *      \-false-> b (transform) ---> m (flow.merge)
     */
    private function branchingGraph(mixed $value): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('cond', 'connect.condition', ['value' => $value, 'rules' => [['path' => '$.total', 'op' => 'gte', 'value' => 100]]]),
                new GraphNode('a', 'connect.transform', ['mapping' => ['path' => 'review']]),
                new GraphNode('b', 'connect.transform', ['mapping' => ['path' => 'auto']]),
                new GraphNode('m', 'flow.merge'),
            ],
            [
                new Connection('cond', 'true', 'a', 'data'),
                new Connection('cond', 'false', 'b', 'data'),
                new Connection('a', 'result', 'm', 'items'),
                new Connection('b', 'result', 'm', 'items'),
            ],
        );
    }

    public function test_the_true_branch_runs_and_the_false_branch_is_skipped(): void
    {
        $run = $this->app->make(GraphRunner::class)->run($this->branchingGraph(['total' => 150]), []);

        $this->assertSame(RunState::Succeeded, $run->state);
        $this->assertSame(NodeState::Succeeded, $run->nodeStates['a']);
        $this->assertSame(NodeState::Skipped, $run->nodeStates['b']);
        $this->assertSame(NodeState::Succeeded, $run->nodeStates['m']);
        $this->assertSame([['path' => 'review']], $run->nodeOutputs['m']['merged']);
    }

    public function test_the_false_branch_runs_and_the_true_branch_is_skipped(): void
    {
        $run = $this->app->make(GraphRunner::class)->run($this->branchingGraph(['total' => 5]), []);

        $this->assertSame(RunState::Succeeded, $run->state);
        $this->assertSame(NodeState::Skipped, $run->nodeStates['a']);
        $this->assertSame(NodeState::Succeeded, $run->nodeStates['b']);
        $this->assertSame([['path' => 'auto']], $run->nodeOutputs['m']['merged']);
    }

    public function test_a_dry_run_evaluates_the_branch_too(): void
    {
        $run = $this->app->make(GraphRunner::class)->run($this->branchingGraph(['total' => 150]), [], null, true);

        $this->assertSame(NodeState::Succeeded, $run->nodeStates['a']);
        $this->assertSame(NodeState::Skipped, $run->nodeStates['b']);
    }
}
