<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Http;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestController;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;

final class WebhookRequestControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowConnectServiceProvider::class];
    }

    public function test_a_request_with_no_resolved_route_returns_404_not_a_typeerror(): void
    {
        // Request::route() (no $param) can return null when no route
        // resolver is set — this must degrade to a safe 404 response, not
        // an uncaught TypeError escaping BEFORE the controller's own
        // try/catch (the exact bug class D-PR4's event-listener isolation
        // fix addressed: never let a strictly-typed parameter throw ahead
        // of the guard meant to catch failures for an untrusted caller).
        $request = Request::create('/laravel-flow-connect/webhook/order-webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        $request->setRouteResolver(fn () => null);

        $response = $this->app->make(WebhookRequestController::class)->__invoke($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
