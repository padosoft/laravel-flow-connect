<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Http\Client;

use Padosoft\LaravelFlowConnect\Exceptions\EgressDeniedException;
use Padosoft\LaravelFlowConnect\Exceptions\InvalidHttpConnectionException;
use Padosoft\LaravelFlowConnect\Http\Client\EgressGuard;
use Padosoft\LaravelFlowConnect\Http\Client\HttpConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EgressGuardTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function connection(string $baseUrl = 'https://api.example.test/v1', array $overrides = []): HttpConnection
    {
        return HttpConnection::fromConfig('api', ['base_url' => $baseUrl, ...$overrides]);
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $allowed
     */
    private function guard(array $allowed = ['api.example.test'], array $addresses = ['93.184.216.34']): EgressGuard
    {
        return new EgressGuard($allowed, static fn (): array => $addresses);
    }

    public function test_an_allow_listed_public_host_passes_and_returns_its_addresses(): void
    {
        $this->assertSame(['93.184.216.34'], $this->guard()->assertAllowed($this->connection(), 'https://api.example.test/v1/x'));
    }

    public function test_an_empty_allow_list_denies_everything(): void
    {
        $this->expectException(EgressDeniedException::class);

        $this->guard([])->assertAllowed($this->connection(), 'https://api.example.test/v1');
    }

    public function test_the_url_host_must_equal_the_connection_host(): void
    {
        $this->expectException(EgressDeniedException::class);
        $this->expectExceptionMessage('does not match the connection host');

        $this->guard(['api.example.test', 'other.test'])->assertAllowed($this->connection(), 'https://other.test/v1');
    }

    public function test_urls_with_credentials_or_a_foreign_scheme_are_refused(): void
    {
        foreach (['https://user:pw@api.example.test/v1', 'ftp://api.example.test/v1', 'https:///v1'] as $url) {
            try {
                $this->guard()->assertAllowed($this->connection(), $url);
                $this->fail('expected a denial for '.$url);
            } catch (EgressDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_wildcard_matches_subdomains_but_not_the_apex(): void
    {
        $guard = $this->guard(['*.example.test']);

        $this->assertSame(['93.184.216.34'], $guard->assertAllowed($this->connection(), 'https://api.example.test/v1'));

        $this->expectException(EgressDeniedException::class);
        $guard->assertAllowed($this->connection('https://example.test'), 'https://example.test');
    }

    public function test_a_wildcard_does_not_match_a_lookalike_suffix(): void
    {
        $this->expectException(EgressDeniedException::class);

        $this->guard(['*.example.test'])->assertAllowed($this->connection('https://evilexample.test'), 'https://evilexample.test');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10/8' => ['10.1.2.3'],
            'private 172.16/12' => ['172.20.0.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'link-local / cloud metadata' => ['169.254.169.254'],
            'CGNAT' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unique local' => ['fd00::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_a_host_resolving_to_a_non_public_address_is_denied(string $address): void
    {
        $this->expectException(EgressDeniedException::class);
        $this->expectExceptionMessage('private or reserved');

        $this->guard(addresses: [$address])->assertAllowed($this->connection(), 'https://api.example.test/v1');
    }

    public function test_one_private_address_among_public_ones_is_enough_to_deny(): void
    {
        $this->expectException(EgressDeniedException::class);

        $this->guard(addresses: ['93.184.216.34', '10.0.0.9'])->assertAllowed($this->connection(), 'https://api.example.test/v1');
    }

    public function test_allow_private_network_permits_an_internal_address(): void
    {
        $connection = $this->connection(overrides: ['allow_private_network' => true]);

        $this->assertSame(['10.0.0.9'], $this->guard(addresses: ['10.0.0.9'])->assertAllowed($connection, 'https://api.example.test/v1'));
    }

    public function test_an_unresolvable_host_is_denied(): void
    {
        $this->expectException(EgressDeniedException::class);
        $this->expectExceptionMessage('did not resolve');

        $this->guard(addresses: [])->assertAllowed($this->connection(), 'https://api.example.test/v1');
    }

    public function test_an_ip_literal_host_is_judged_without_dns(): void
    {
        $guard = new EgressGuard(['10.0.0.9'], static fn (): array => throw new \LogicException('no DNS lookup expected'));

        $this->expectException(EgressDeniedException::class);
        $guard->assertAllowed($this->connection('http://10.0.0.9'), 'http://10.0.0.9/x');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badConnections(): array
    {
        return [
            'not an array' => ['nope'],
            'no base url' => [[]],
            'not http' => [['base_url' => 'ftp://x.test']],
            'credentials in url' => [['base_url' => 'https://u:p@x.test']],
            'query in url' => [['base_url' => 'https://x.test?a=1']],
            'unknown auth' => [['base_url' => 'https://x.test', 'auth' => ['type' => 'oauth']]],
            'bearer without token' => [['base_url' => 'https://x.test', 'auth' => ['type' => 'bearer']]],
            'basic without password' => [['base_url' => 'https://x.test', 'auth' => ['type' => 'basic', 'username' => 'u']]],
            'bad auth header name' => [['base_url' => 'https://x.test', 'auth' => ['type' => 'header', 'header' => 'bad name', 'value' => 'v']]],
            'bad static header' => [['base_url' => 'https://x.test', 'headers' => ['X' => "a\r\nb"]]],
            'zero timeout' => [['base_url' => 'https://x.test', 'timeout' => 0]],
            'bad idempotency header' => [['base_url' => 'https://x.test', 'idempotency_header' => 'bad name']],
        ];
    }

    #[DataProvider('badConnections')]
    public function test_a_malformed_connection_is_rejected(mixed $config): void
    {
        $this->expectException(InvalidHttpConnectionException::class);

        HttpConnection::fromConfig('api', $config);
    }

    public function test_a_valid_connection_normalises_the_base_url_and_defaults(): void
    {
        $connection = HttpConnection::fromConfig('api', ['base_url' => ' https://API.example.test/v1/ ']);

        $this->assertSame('https://API.example.test/v1', $connection->baseUrl);
        $this->assertSame('api.example.test', $connection->host);
        $this->assertSame('none', $connection->authType);
        $this->assertSame(30, $connection->timeout);
        $this->assertSame(5, $connection->connectTimeout);
        $this->assertTrue($connection->verify);
        $this->assertFalse($connection->allowPrivateNetwork);
        $this->assertSame(1_048_576, $connection->maxResponseBytes);
        $this->assertNull($connection->idempotencyHeader);
    }
}
