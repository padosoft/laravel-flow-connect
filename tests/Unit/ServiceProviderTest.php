<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlowConnect\LaravelFlowConnectServiceProvider;

final class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowConnectServiceProvider::class];
    }

    public function test_provider_is_loaded(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LaravelFlowConnectServiceProvider::class));
    }
}
