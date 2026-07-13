<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Triggers;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestController;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\AbstractWebhookMapper;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\NotAWebhookMapper;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\ThrowingWebhookMapper;
use Padosoft\LaravelFlowConnect\Tests\Fixtures\Mappers\WebhookOrderMapper;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTriggerRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class WebhookTriggerRegistrarTest extends TestCase
{
    private const SECRET = 'shh-its-a-secret';

    private const URI = 'laravel-flow-connect/webhook/order-webhook';

    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow-connect.webhook', [
            'enabled' => true,
            'triggers' => [
                'order-webhook' => ['flow' => 'fulfill-order', 'secret' => self::SECRET, 'mapper' => WebhookOrderMapper::class],
            ],
        ]);
    }

    private function signatureHeader(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }

    private function postWebhook(string $body, ?string $signatureHeader, string $uri = self::URI): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signatureHeader !== null) {
            $server['HTTP_X_LARAVEL_FLOW_SIGNATURE'] = $signatureHeader;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    /**
     * For tests that need webhook config DIFFERENT from `defineEnvironment`'s
     * default: mutating `$this->app['config']` after the app has already
     * booted has no effect on routes the service provider already registered
     * (route registration in `boot()` reads config exactly once). Building a
     * fresh {@see Router} and calling `register()` directly — the same
     * pattern the skip/log tests below already use — sidesteps that
     * boot-order issue entirely.
     *
     * @param  array<string, mixed>  $webhookConfig
     */
    private function dispatchOnFreshRouter(string $body, ?string $signatureHeader, array $webhookConfig, string $uri = self::URI): Response
    {
        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, $webhookConfig);

        $request = Request::create($uri, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        if ($signatureHeader !== null) {
            $request->headers->set('X-Laravel-Flow-Signature', $signatureHeader);
        }

        // The route closure's `Request $request` parameter is resolved by
        // the container (`app('request')`), NOT the object passed to
        // dispatch() — the real HTTP kernel rebinds this before routing;
        // calling Router::dispatch() directly bypasses that, so it must be
        // done here too, or the closure sees Testbench's original bootstrap
        // request instead of this one (missing the signature header).
        $this->app->instance('request', $request);

        return $router->dispatch($request);
    }

    public function test_happy_path_creates_a_run(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('fulfill-order', ['order_id' => 42], null)
                ->andReturn(null);
        });

        $body = json_encode(['order' => ['id' => 42]]);

        $response = $this->postWebhook($body, $this->signatureHeader($body));

        $response->assertStatus(202);
    }

    public function test_omitting_a_mapper_fires_the_payload_verbatim(): void
    {
        $body = json_encode(['order_id' => 42]);

        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with('fulfill-order', ['order_id' => 42], null)
                ->andReturn(null);
        });

        $response = $this->dispatchOnFreshRouter($body, $this->signatureHeader($body), [
            'enabled' => true,
            'triggers' => ['order-webhook' => ['flow' => 'fulfill-order', 'secret' => self::SECRET]],
        ]);

        $this->assertSame(202, $response->getStatusCode());
    }

    public function test_disabled_registers_no_route(): void
    {
        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, [
            'enabled' => false,
            'triggers' => ['order-webhook' => ['flow' => 'fulfill-order', 'secret' => self::SECRET]],
        ]);

        $this->assertCount(0, $router->getRoutes());
    }

    public function test_the_registered_route_action_is_not_a_closure(): void
    {
        // Laravel's route:cache cannot serialize a Closure action — the
        // registrar must bind a controller CLASS, with the per-slug config
        // threaded through as route defaults, so a host application that
        // caches its routes doesn't silently lose every webhook route.
        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, [
            'enabled' => true,
            'triggers' => ['order-webhook' => ['flow' => 'fulfill-order', 'secret' => self::SECRET]],
        ]);

        $route = $router->getRoutes()->getRoutes()[0];

        $this->assertFalse($route->getAction('uses') instanceof \Closure);
        $this->assertSame(WebhookRequestController::class, $route->getController()::class);
    }

    public function test_tampered_signature_is_rejected_401(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $body = json_encode(['order' => ['id' => 42]]);
        $header = $this->signatureHeader($body);

        // The BODY sent differs from what the signature was computed over.
        $this->postWebhook(json_encode(['order' => ['id' => 999]]), $header)->assertStatus(401);
    }

    public function test_missing_signature_is_rejected_401(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $body = json_encode(['order' => ['id' => 42]]);

        $this->postWebhook($body, null)->assertStatus(401);
    }

    public function test_expired_timestamp_is_rejected_401(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $body = json_encode(['order' => ['id' => 42]]);
        $header = $this->signatureHeader($body, time() - 3600);

        $this->postWebhook($body, $header)->assertStatus(401);
    }

    public function test_replay_within_window_is_rejected(): void
    {
        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once()->andReturn(null);
        });

        $body = json_encode(['order' => ['id' => 42]]);
        $header = $this->signatureHeader($body);

        $this->postWebhook($body, $header)->assertStatus(202);
        // Second delivery of the byte-identical request+signature.
        $this->postWebhook($body, $header)->assertStatus(401);
    }

    public function test_malformed_json_payload_returns_422_not_a_500(): void
    {
        $body = '{not valid json';
        $header = $this->signatureHeader($body);

        $this->postWebhook($body, $header)->assertStatus(422);
    }

    public function test_a_mapper_that_throws_returns_a_generic_error_never_the_exception_message(): void
    {
        Log::spy();

        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $body = json_encode(['order' => ['id' => 42]]);
        $header = $this->signatureHeader($body);

        $response = $this->dispatchOnFreshRouter($body, $header, [
            'enabled' => true,
            'triggers' => ['order-webhook' => ['flow' => 'fulfill-order', 'secret' => self::SECRET, 'mapper' => ThrowingWebhookMapper::class]],
        ]);

        $this->assertSame(500, $response->getStatusCode());
        // The exception's own message must NEVER reach the external caller.
        $this->assertStringNotContainsString('webhook mapping failed on purpose', (string) $response->getContent());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'mapping/fire failed')
                && $context['message'] === 'webhook mapping failed on purpose')
            ->once();
    }

    public function test_a_fire_failure_returns_a_generic_error_and_is_logged(): void
    {
        Log::spy();

        $this->mock(FlowEngine::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('flow rejected the input'));
        });

        $body = json_encode(['order' => ['id' => 42]]);
        $header = $this->signatureHeader($body);

        $response = $this->postWebhook($body, $header);

        $response->assertStatus(500);
        $response->assertDontSee('flow rejected the input');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'mapping/fire failed'))
            ->once();
    }

    public function test_a_non_array_entry_is_skipped_not_a_fatal(): void
    {
        Log::spy();

        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, ['enabled' => true, 'triggers' => ['bad-slug' => 'not-an-array']]);

        $this->assertCount(0, $router->getRoutes());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_non_string_key_is_skipped_not_a_fatal(): void
    {
        Log::spy();

        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, ['enabled' => true, 'triggers' => [0 => ['flow' => 'x', 'secret' => 'y']]]);

        $this->assertCount(0, $router->getRoutes());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_missing_flow_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, ['enabled' => true, 'triggers' => ['slug' => ['secret' => 'y']]]);

        $this->assertCount(0, $router->getRoutes());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_missing_secret_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, ['enabled' => true, 'triggers' => ['slug' => ['flow' => 'x']]]);

        $this->assertCount(0, $router->getRoutes());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_a_mapper_not_implementing_the_interface_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, ['enabled' => true, 'triggers' => [
            'slug' => ['flow' => 'x', 'secret' => 'y', 'mapper' => NotAWebhookMapper::class],
        ]]);

        $this->assertCount(0, $router->getRoutes());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_a_non_instantiable_mapper_is_skipped_and_logged(): void
    {
        Log::spy();

        $registrar = $this->app->make(WebhookTriggerRegistrar::class);
        $router = new Router($this->app['events'], $this->app);
        $registrar->register($router, ['enabled' => true, 'triggers' => [
            'slug' => ['flow' => 'x', 'secret' => 'y', 'mapper' => AbstractWebhookMapper::class],
        ]]);

        $this->assertCount(0, $router->getRoutes());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'config entry skipped'))
            ->once();
    }

    public function test_the_registrar_is_bound_with_the_real_container(): void
    {
        $registrar = $this->app->make(WebhookTriggerRegistrar::class);

        self::assertInstanceOf(WebhookTriggerRegistrar::class, $registrar);
    }
}
