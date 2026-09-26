<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Http\Client;

use Closure;
use Padosoft\LaravelFlowConnect\Exceptions\EgressDeniedException;

/**
 * Decides whether the HTTP node may call a URL. A URL is allowed only when ALL
 * of these hold:
 *
 *  1. it is http(s) and its host EQUALS the connection's own host — a node
 *     cannot redirect a connection's credentials to another host through its
 *     `path` input;
 *  2. the host is on `laravel-flow-connect.http.allowed_hosts` (exact, or
 *     `*.example.com` for subdomains). An empty list allows nothing, so the
 *     node is inert until a host opts in;
 *  3. unless the connection sets `allow_private_network`, every address the
 *     host resolves to is public — loopback, private, link-local (cloud
 *     metadata), CGNAT and reserved ranges are refused, IPv4-mapped IPv6
 *     included.
 *
 * Returns the resolved addresses so the caller can pin the connection to them
 * (best effort against DNS rebinding between this check and the request).
 *
 * @internal
 */
final class EgressGuard
{
    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param  list<string>  $allowedHosts
     * @param  (Closure(string): list<string>)|null  $resolver  test seam; defaults to the system resolver
     */
    public function __construct(
        private readonly array $allowedHosts,
        ?Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::systemResolver(...);
    }

    /**
     * @return list<string> the addresses the host resolves to
     *
     * @throws EgressDeniedException
     */
    public function assertAllowed(HttpConnection $connection, string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new EgressDeniedException(sprintf('Connection [%s]: the request URL is not a plain http(s) URL.', $connection->name));
        }

        if ($host !== $connection->host) {
            throw new EgressDeniedException(sprintf('Connection [%s]: the request host does not match the connection host.', $connection->name));
        }

        if (! $this->hostIsAllowed($host)) {
            throw new EgressDeniedException(sprintf('Connection [%s]: host [%s] is not on laravel-flow-connect.http.allowed_hosts.', $connection->name, $host));
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new EgressDeniedException(sprintf('Connection [%s]: host [%s] did not resolve.', $connection->name, $host));
        }

        if (! $connection->allowPrivateNetwork) {
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    throw new EgressDeniedException(sprintf('Connection [%s]: host [%s] resolves to a private or reserved address.', $connection->name, $host));
                }
            }
        }

        return $addresses;
    }

    private function hostIsAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if ($allowed === $host) {
                return true;
            }

            // `*.example.com` matches subdomains only, never example.com itself.
            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)) && $host !== substr($allowed, 2)) {
                return true;
            }
        }

        return false;
    }

    private static function isPublic(string $address): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) must be judged as the IPv4 it wraps.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // CGNAT 100.64.0.0/10 is neither private nor reserved to PHP, but is not public either.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function systemResolver(string $host): array
    {
        $addresses = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
