<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Nodes;

use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlowConnect\Exceptions\EgressDeniedException;
use Padosoft\LaravelFlowConnect\Exceptions\HttpRequestFailedException;
use Padosoft\LaravelFlowConnect\Exceptions\InvalidHttpConnectionException;
use Padosoft\LaravelFlowConnect\Http\Client\EgressGuard;
use Padosoft\LaravelFlowConnect\Http\Client\HttpConnectionRegistry;
use Padosoft\LaravelFlowConnect\Nodes\HttpRequestNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP node against a FAKED client: nothing here can reach the network
 * (`preventStrayRequests()` turns any unfaked call into a failure).
 */
final class HttpRequestNodeTest extends TestCase
{
    private const BASE = 'https://api.example.test/v1';

    private Factory $http;

    protected function setUp(): void
    {
        $this->http = new Factory;
        $this->http->preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function connection(array $overrides = []): array
    {
        return array_replace(['base_url' => self::BASE, 'auth' => ['type' => 'bearer', 'token' => 'tok-SECRET']], $overrides);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $addresses
     */
    private function node(array $connection = [], array $allowedHosts = ['api.example.test'], array $addresses = ['93.184.216.34']): HttpRequestNode
    {
        $config = new Repository(['laravel-flow-connect' => ['http' => ['connections' => ['api' => $this->connection($connection)]]]]);

        return new HttpRequestNode(
            $this->http,
            new HttpConnectionRegistry($config),
            new EgressGuard($allowedHosts, static fn (): array => $addresses),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function execute(HttpRequestNode $node, array $inputs, bool $dryRun = false): NodeResult
    {
        return $node->execute(new NodeContext('run-1', 'def', 'node-1', ['connection' => 'api', ...$inputs], $dryRun));
    }

    public function test_get_with_query_headers_and_bearer_auth_returns_decoded_json(): void
    {
        $this->http->fake(['*' => Factory::response(['id' => 7], 200, ['Content-Type' => 'application/json', 'X-Rate' => '5'])]);

        $result = $this->execute($this->node(), ['path' => 'orders', 'query' => ['status' => 'open'], 'headers' => ['X-Trace' => 'abc']]);

        $this->assertTrue($result->success, (string) $result->error?->getMessage());
        $this->assertSame(200, $result->outputs['status']);
        $this->assertTrue($result->outputs['ok']);
        $this->assertSame(['id' => 7], $result->outputs['body']);
        $this->assertSame('5', $result->outputs['headers']['x-rate']);

        $this->http->assertSent(static fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === self::BASE.'/orders?status=open'
            && $r['status'] === 'open'
            && $r->header('Authorization') === ['Bearer tok-SECRET']
            && $r->header('X-Trace') === ['abc']);
    }

    public function test_basic_and_custom_header_auth(): void
    {
        $this->http->fake(['*' => Factory::response('ok', 200)]);

        $this->execute($this->node(['auth' => ['type' => 'basic', 'username' => 'u', 'password' => 'p']]), []);
        $this->http->assertSent(static fn (Request $r): bool => $r->header('Authorization') === ['Basic '.base64_encode('u:p')]);

        $this->execute($this->node(['auth' => ['type' => 'header', 'header' => 'X-Api-Key', 'value' => 'k-1']]), []);
        $this->http->assertSent(static fn (Request $r): bool => $r->header('X-Api-Key') === ['k-1']);
    }

    public function test_post_sends_an_array_body_as_json_and_a_string_body_verbatim(): void
    {
        $this->http->fake(['*' => Factory::response('', 201)]);

        $this->execute($this->node(), ['method' => 'post', 'path' => 'orders', 'body' => ['sku' => 'A']]);
        $this->http->assertSent(static fn (Request $r): bool => $r->method() === 'POST' && $r->data() === ['sku' => 'A'] && str_contains(strtolower($r->header('Content-Type')[0] ?? ''), 'json'));

        $this->execute($this->node(), ['method' => 'POST', 'body' => 'plain text']);
        $this->http->assertSent(static fn (Request $r): bool => $r->body() === 'plain text');
    }

    public function test_a_get_never_sends_a_body(): void
    {
        $this->http->fake(['*' => Factory::response('', 200)]);

        $this->execute($this->node(), ['body' => ['ignored' => true]]);

        $this->http->assertSent(static fn (Request $r): bool => $r->body() === '');
    }

    public function test_an_error_status_fails_the_node_without_leaking_the_body(): void
    {
        $this->http->fake(['*' => Factory::response('internal secret detail', 500)]);

        $result = $this->execute($this->node(), []);

        $this->assertFalse($result->success);
        $this->assertInstanceOf(HttpRequestFailedException::class, $result->error);
        $this->assertSame(500, $result->error->status);
        $this->assertStringNotContainsString('secret detail', $result->error->getMessage());
        $this->assertStringContainsString('[api]', $result->error->getMessage());
    }

    public function test_fail_on_error_false_returns_the_error_response(): void
    {
        $this->http->fake(['*' => Factory::response(['error' => 'nope'], 404, ['Content-Type' => 'application/json'])]);

        $result = $this->execute($this->node(), ['failOnError' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(404, $result->outputs['status']);
        $this->assertFalse($result->outputs['ok']);
        $this->assertSame(['error' => 'nope'], $result->outputs['body']);
    }

    public function test_a_redirect_is_returned_not_followed(): void
    {
        $this->http->fake(['*' => Factory::response('', 302, ['Location' => 'https://evil.test/'])]);

        $result = $this->execute($this->node(), ['failOnError' => false]);

        $this->assertSame(302, $result->outputs['status']);
        $this->assertSame('https://evil.test/', $result->outputs['headers']['location']);
        $this->http->assertSentCount(1);
    }

    public function test_non_json_and_invalid_json_bodies_come_back_as_strings(): void
    {
        $this->http->fake(['*' => $this->http->sequence()
            ->push('<html>', 200, ['Content-Type' => 'text/html'])
            ->push('{not json', 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame('<html>', $this->execute($this->node(), [])->outputs['body']);
        $this->assertSame('{not json', $this->execute($this->node(), [])->outputs['body']);
    }

    public function test_a_response_over_the_size_cap_fails_the_node(): void
    {
        $this->http->fake(['*' => Factory::response(str_repeat('x', 100), 200)]);

        $result = $this->execute($this->node(['max_response_bytes' => 16]), []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exceeded the 16 byte limit', $result->error?->getMessage() ?? '');
    }

    public function test_a_declared_content_length_over_the_cap_fails_before_reading(): void
    {
        $this->http->fake(['*' => Factory::response('x', 200, ['Content-Length' => '999999'])]);

        $result = $this->execute($this->node(['max_response_bytes' => 1024]), []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('byte limit', $result->error?->getMessage() ?? '');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'absolute url' => ['https://evil.test/x'],
            'scheme' => ['file:///etc/passwd'],
            'protocol relative' => ['//evil.test/x'],
            'parent traversal' => ['../admin'],
            'nested traversal' => ['a/../../b'],
            'backslash' => ['a\\b'],
            'whitespace' => ['a b'],
            'newline' => ["a\r\nHost: evil"],
            'encoded traversal' => ['%2e%2e/admin'],
            'encoded traversal upper' => ['%2E%2E/admin'],
            'encoded slash traversal' => ['..%2fadmin'],
            'double encoded traversal' => ['%252e%252e/admin'],
            'encoded backslash' => ['a%5cb'],
            'encoded scheme' => ['https%3A//evil.test'],
            'encoded control char' => ['a%0d%0aHost:%20evil'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_a_path_that_could_leave_the_connection_is_refused_before_any_request(string $path): void
    {
        $result = $this->execute($this->node(), ['path' => $path]);

        $this->assertFalse($result->success);
        $this->assertInstanceOf(HttpRequestFailedException::class, $result->error);
        $this->http->assertNothingSent();
    }

    public function test_an_unlisted_host_is_denied_and_nothing_is_sent(): void
    {
        $result = $this->execute($this->node(allowedHosts: []), []);

        $this->assertFalse($result->success);
        $this->assertInstanceOf(EgressDeniedException::class, $result->error);
        $this->http->assertNothingSent();
    }

    public function test_a_host_resolving_to_a_private_address_is_denied(): void
    {
        $result = $this->execute($this->node(addresses: ['10.0.0.5']), []);

        $this->assertInstanceOf(EgressDeniedException::class, $result->error);
        $this->http->assertNothingSent();
    }

    public function test_allow_private_network_lets_an_internal_service_through(): void
    {
        $this->http->fake(['*' => Factory::response('ok', 200)]);

        $result = $this->execute($this->node(['allow_private_network' => true], addresses: ['10.0.0.5']), []);

        $this->assertTrue($result->success);
    }

    public function test_an_unknown_or_malformed_connection_fails_only_the_node(): void
    {
        $unknown = (new HttpRequestNode(
            $this->http,
            new HttpConnectionRegistry(new Repository([])),
            new EgressGuard(['api.example.test']),
        ))->execute(new NodeContext('run-1', 'def', 'node-1', ['connection' => 'ghost']));

        $this->assertInstanceOf(InvalidHttpConnectionException::class, $unknown->error);

        $malformed = $this->execute($this->node(['base_url' => 'not a url']), []);
        $this->assertInstanceOf(InvalidHttpConnectionException::class, $malformed->error);
        $this->http->assertNothingSent();
    }

    public function test_a_disallowed_method_is_refused(): void
    {
        $result = $this->execute($this->node(), ['method' => 'TRACE']);

        $this->assertInstanceOf(HttpRequestFailedException::class, $result->error);
        $this->http->assertNothingSent();
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badHeaders(): array
    {
        return [
            'authorization' => [['Authorization' => 'Bearer stolen']],
            'proxy authorization' => [['Proxy-Authorization' => 'x']],
            'host' => [['Host' => 'evil.test']],
            'cookie' => [['Cookie' => 'a=b']],
            'content length' => [['Content-Length' => '0']],
            'header injection' => [['X-A' => "1\r\nX-B: 2"]],
            'invalid name' => [['bad name' => '1']],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('badHeaders')]
    public function test_a_node_cannot_override_connection_managed_headers_or_inject_new_ones(array $headers): void
    {
        $result = $this->execute($this->node(), ['headers' => $headers]);

        $this->assertInstanceOf(HttpRequestFailedException::class, $result->error);
        $this->http->assertNothingSent();
    }

    public function test_idempotency_key_is_stable_per_node_and_only_on_unsafe_methods(): void
    {
        $this->http->fake(['*' => Factory::response('', 200)]);
        $node = $this->node(['idempotency_header' => 'Idempotency-Key']);

        $this->execute($node, ['method' => 'POST']);
        $this->execute($node, ['method' => 'POST']);
        $this->execute($node, ['method' => 'GET']);

        $key = hash('sha256', 'run-1|node-1');
        $this->http->assertSent(static fn (Request $r): bool => $r->method() === 'POST' && $r->header('Idempotency-Key') === [$key]);
        $this->http->assertSent(static fn (Request $r): bool => $r->method() === 'GET' && ! $r->hasHeader('Idempotency-Key'));
    }

    public function test_a_set_cookie_response_header_is_not_returned(): void
    {
        $this->http->fake(['*' => Factory::response('', 200, ['Set-Cookie' => 'session=SECRET', 'X-Ok' => '1'])]);

        $headers = $this->execute($this->node(), [])->outputs['headers'];

        $this->assertArrayNotHasKey('set-cookie', $headers);
        $this->assertSame('1', $headers['x-ok']);
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $result = $this->execute($this->node(), [], dryRun: true);

        $this->assertTrue($result->dryRunSkipped);
        $this->http->assertNothingSent();
    }

    public function test_a_transport_failure_reports_the_class_not_the_url_or_secret(): void
    {
        $this->http->fake(static function (): never {
            throw new ConnectionException('cURL error 6: could not resolve https://api.example.test/v1?token=LEAKED');
        });

        $result = $this->execute($this->node(), []);

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString('LEAKED', $result->error?->getMessage() ?? '');
        $this->assertStringContainsString('ConnectionException', $result->error?->getMessage() ?? '');
    }

    public function test_the_credential_never_appears_in_the_outputs(): void
    {
        $this->http->fake(['*' => Factory::response(['ok' => true], 200, ['Content-Type' => 'application/json'])]);

        $result = $this->execute($this->node(), []);

        $this->assertStringNotContainsString('tok-SECRET', json_encode($result->outputs, JSON_THROW_ON_ERROR));
    }
}
