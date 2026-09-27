<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Http\Client;

use Padosoft\LaravelFlowConnect\Exceptions\InvalidHttpConnectionException;

/**
 * One validated entry of `laravel-flow-connect.http.connections`: where to
 * call, how to authenticate, and the limits that apply. This is the ONLY place
 * a credential lives — a graph names a connection, it never carries the
 * secret, so a stored graph, a run row or a dashboard cannot leak one.
 *
 * @internal
 */
final readonly class HttpConnection
{
    private const AUTH_TYPES = ['none', 'bearer', 'basic', 'header'];

    /**
     * @param  array<string, string>  $auth  secret material for `bearer` / `basic` / `header`
     * @param  array<string, string>  $headers  static headers sent on every request
     */
    private function __construct(
        public string $name,
        public string $baseUrl,
        public string $host,
        public string $authType,
        public array $auth,
        public array $headers,
        public int $timeout,
        public int $connectTimeout,
        public bool $verify,
        public bool $allowPrivateNetwork,
        public int $maxResponseBytes,
        public ?string $idempotencyHeader,
    ) {}

    /**
     * @throws InvalidHttpConnectionException
     */
    public static function fromConfig(string $name, mixed $config): self
    {
        if (! is_array($config)) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] must be an array.', $name));
        }

        $baseUrl = $config['base_url'] ?? null;
        $parts = is_string($baseUrl) ? parse_url(trim($baseUrl)) : false;

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] needs an http(s) `base_url` with a host and no credentials, query or fragment.', $name));
        }

        [$authType, $auth] = self::auth($name, $config['auth'] ?? null);

        return new self(
            $name,
            rtrim(trim((string) $baseUrl), '/'),
            // parse_url() keeps the brackets of an IPv6 literal ("[::1]"); the guard
            // and the DNS pin work on the bare address.
            strtolower(trim((string) $parts['host'], '[]')),
            $authType,
            $auth,
            self::headers($name, $config['headers'] ?? []),
            self::positive($name, 'timeout', $config['timeout'] ?? 30),
            self::positive($name, 'connect_timeout', $config['connect_timeout'] ?? 5),
            (bool) ($config['verify'] ?? true),
            (bool) ($config['allow_private_network'] ?? false),
            self::positive($name, 'max_response_bytes', $config['max_response_bytes'] ?? 1_048_576),
            self::idempotencyHeader($name, $config['idempotency_header'] ?? null),
        );
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function auth(string $name, mixed $auth): array
    {
        if ($auth === null) {
            return ['none', []];
        }

        $type = is_array($auth) ? ($auth['type'] ?? null) : null;

        if (! is_string($type) || ! in_array($type, self::AUTH_TYPES, true)) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] has an unknown `auth.type`; use one of: %s.', $name, implode(', ', self::AUTH_TYPES)));
        }

        /** @var array<string, mixed> $auth */
        $need = match ($type) {
            'bearer' => ['token'],
            'basic' => ['username', 'password'],
            'header' => ['header', 'value'],
            default => [],
        };
        $secret = [];

        foreach ($need as $key) {
            if (! isset($auth[$key]) || ! is_string($auth[$key]) || $auth[$key] === '') {
                throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] auth type [%s] needs a non-empty `%s`.', $name, $type, $key));
            }

            if ($type === 'header' && $key === 'header' && ! self::isHeaderName($auth[$key])) {
                throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] has an invalid `auth.header` name.', $name));
            }

            $secret[$key] = $auth[$key];
        }

        return [$type, $secret];
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $name, mixed $headers): array
    {
        if (! is_array($headers)) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] `headers` must be an array.', $name));
        }

        $clean = [];

        foreach ($headers as $header => $value) {
            if (! is_string($header) || ! self::isHeaderName($header) || ! is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] has an invalid entry in `headers`.', $name));
            }

            $clean[$header] = $value;
        }

        return $clean;
    }

    private static function positive(string $name, string $key, mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] `%s` must be a positive integer.', $name, $key));
        }

        $int = (int) $value;

        if ($int < 1) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] `%s` must be a positive integer.', $name, $key));
        }

        return $int;
    }

    private static function idempotencyHeader(string $name, mixed $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (! is_string($header) || ! self::isHeaderName($header)) {
            throw new InvalidHttpConnectionException(sprintf('HTTP connection [%s] has an invalid `idempotency_header` name.', $name));
        }

        return $header;
    }

    private static function isHeaderName(string $header): bool
    {
        return preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header) === 1;
    }
}
