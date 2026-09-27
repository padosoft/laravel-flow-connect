<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Http\Client;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\LaravelFlowConnect\Exceptions\InvalidHttpConnectionException;

/**
 * Resolves a connection NAME (the only thing a graph carries) to its validated
 * {@see HttpConnection}. Read from config on every call, lazily: a malformed
 * entry fails the node that uses it, never application boot, and a config
 * change under Octane is picked up without a stale cache.
 *
 * @internal
 */
final class HttpConnectionRegistry
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * @throws InvalidHttpConnectionException
     */
    public function get(string $name): HttpConnection
    {
        $connections = $this->config->get('laravel-flow-connect.http.connections', []);

        if (! is_array($connections) || ! array_key_exists($name, $connections)) {
            throw new InvalidHttpConnectionException(sprintf('Unknown HTTP connection [%s]; declare it under laravel-flow-connect.http.connections.', $name));
        }

        return HttpConnection::fromConfig($name, $connections[$name]);
    }
}
