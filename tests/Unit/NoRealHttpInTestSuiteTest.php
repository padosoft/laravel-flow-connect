<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The HTTP node must never reach a real network from the test suite. Every test
 * file that touches Laravel's HTTP client (the `Factory` class or the `Http`
 * facade) has to call `preventStrayRequests()`, which turns any unfaked request
 * into a failure instead of a real call.
 */
final class NoRealHttpInTestSuiteTest extends TestCase
{
    public function test_every_test_using_the_http_client_prevents_stray_requests(): void
    {
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/..', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $usesClient = str_contains($source, 'Illuminate\Http\Client\Factory')
                || str_contains($source, 'Illuminate\Support\Facades\Http;');

            if ($usesClient && ! str_contains($source, 'preventStrayRequests()')) {
                $violations[] = substr($file->getPathname(), (int) strlen((string) realpath(__DIR__.'/..')) + 1);
            }
        }

        $this->assertSame([], $violations, 'These test files use the HTTP client without preventStrayRequests().');
    }

    public function test_the_guard_itself_catches_a_file_that_forgets(): void
    {
        $source = "<?php\nuse Illuminate\\Http\\Client\\Factory;\n\$f = new Factory;";

        $this->assertTrue(str_contains($source, 'Illuminate\Http\Client\Factory') && ! str_contains($source, 'preventStrayRequests()'));
    }
}
