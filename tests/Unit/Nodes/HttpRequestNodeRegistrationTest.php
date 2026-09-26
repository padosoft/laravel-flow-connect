<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Nodes;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Nodes\HttpRequestNode;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Nodes\UntrustedTextNode;

/**
 * The node as core sees it: registered by the provider, declared with the ports
 * and provenance the plan promises, refused by taint analysis when fed from an
 * untrusted source, and runnable inside a real graph.
 */
final class HttpRequestNodeRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // An IP-literal host: judged by the guard without any DNS lookup.
        $app['config']->set('laravel-flow-connect.http', [
            'allowed_hosts' => ['93.184.216.34'],
            'connections' => ['api' => ['base_url' => 'https://93.184.216.34/v1', 'auth' => ['type' => 'bearer', 'token' => 'tok']]],
        ]);
    }

    public function test_the_provider_registers_the_node_with_its_declared_ports(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('connect.http.request');

        $this->assertSame('connect', $definition->category);

        foreach (['connection', 'method', 'path'] as $selector) {
            $this->assertTrue($definition->input($selector)?->requiresTrusted, "{$selector} must refuse untrusted data");
        }
        $this->assertTrue($definition->input('connection')->required);
        $this->assertSame(PortType::Json, $definition->input('query')?->type);
        $this->assertSame(PortType::Any, $definition->input('body')?->type);
        $this->assertFalse($definition->input('body')->requiresTrusted);

        foreach (['status' => PortType::Int, 'ok' => PortType::Bool, 'body' => PortType::Any, 'headers' => PortType::Json] as $key => $type) {
            $this->assertSame($type, $definition->output($key)?->type, $key);
            $this->assertSame(PortProvenance::Untrusted, $definition->output($key)->provenance, "{$key} is someone else's data");
        }
    }

    public function test_registration_keeps_core_handlers_and_discovery_and_does_not_duplicate(): void
    {
        $handlers = (array) config('laravel-flow.nodes.handlers');

        $this->assertSame(1, count(array_keys($handlers, HttpRequestNode::class, true)));
        $this->assertIsArray(config('laravel-flow.nodes.discovery'));
        $this->assertTrue($this->app->make(NodeRegistry::class)->has('flow.merge'));
    }

    public function test_an_untrusted_source_cannot_feed_the_path_but_a_config_literal_can(): void
    {
        $this->app->make(NodeRegistry::class)->register(UntrustedTextNode::class);
        $validator = $this->app->make(GraphValidator::class);

        $tainted = new GraphDefinition(
            [new GraphNode('src', 'test.untrusted_text'), new GraphNode('call', 'connect.http.request', ['connection' => 'api'])],
            [new Connection('src', 'text', 'call', 'path')],
        );

        try {
            $validator->validate($tainted);
            $this->fail('untrusted text must not reach the path port');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('path', $e->getMessage());
        }

        $validator->validate(new GraphDefinition([new GraphNode('call', 'connect.http.request', ['connection' => 'api', 'path' => 'orders'])], []));
        $this->addToAssertionCount(1);
    }

    public function test_the_node_runs_inside_a_graph_and_sends_the_configured_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Factory::response(['id' => 1], 200, ['Content-Type' => 'application/json'])]);

        $result = $this->app->make(GraphRunner::class)->run(
            new GraphDefinition([new GraphNode('call', 'connect.http.request', ['connection' => 'api', 'path' => 'orders'])], []),
            [],
        );

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['call']);
        $this->assertSame(['id' => 1], $result->nodeOutputs['call']['body']);
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://93.184.216.34/v1/orders' && $r->header('Authorization') === ['Bearer tok']);
    }

    public function test_the_node_is_inert_until_a_host_is_allow_listed(): void
    {
        config(['laravel-flow-connect.http.allowed_hosts' => []]);
        Http::preventStrayRequests();

        $result = $this->app->make(GraphRunner::class)->run(
            new GraphDefinition([new GraphNode('call', 'connect.http.request', ['connection' => 'api'])], []),
            [],
        );

        $this->assertSame(NodeState::Failed, $result->nodeStates['call']);
        $this->assertStringContainsString('allowed_hosts', $result->errors['call']);
        Http::assertNothingSent();
    }
}
