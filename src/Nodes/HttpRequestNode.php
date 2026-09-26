<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Nodes;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\HttpNodeException;
use Padosoft\LaravelFlowConnect\Exceptions\HttpRequestFailedException;
use Padosoft\LaravelFlowConnect\Http\Client\EgressGuard;
use Padosoft\LaravelFlowConnect\Http\Client\HttpConnection;
use Padosoft\LaravelFlowConnect\Http\Client\HttpConnectionRegistry;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Calls an HTTP API through a NAMED connection.
 *
 * The graph carries a connection NAME, never a credential: base URL, auth,
 * timeouts and the size cap live in `laravel-flow-connect.http.connections`,
 * so a stored graph, a run row or a dashboard cannot leak a secret. Every
 * request passes the {@see EgressGuard} (host must equal the connection's host
 * and be allow-listed; private/reserved addresses refused; redirects are never
 * followed).
 *
 * `connection`, `method` and `path` are declared `requiresTrusted`, so core's
 * taint analysis refuses to publish a graph that feeds them from an untrusted
 * source (a model completion, a fetched page). The response `body` and
 * `headers` are `Untrusted`: they are someone else's data.
 *
 * Retries are deliberately NOT built in — a non-idempotent call must not retry
 * implicitly. Opt in per node with `config['retry']`; set the connection's
 * `idempotency_header` and every attempt of one node carries the same key.
 * A dry run performs no request.
 *
 * @api
 */
#[FlowNode(type: 'connect.http.request', category: 'connect', description: 'Calls an HTTP API through a named, allow-listed connection.')]
final class HttpRequestNode implements FlowNodeHandler
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    /** Headers a node input may never set: the connection owns identity and framing. */
    private const RESERVED_HEADERS = ['authorization', 'proxy-authorization', 'host', 'content-length', 'cookie'];

    #[Input(type: PortType::Text, required: true, requiresTrusted: true)]
    public string $connection;

    #[Input(type: PortType::Text, required: false, requiresTrusted: true)]
    public string $method = 'GET';

    #[Input(type: PortType::Text, required: false, requiresTrusted: true)]
    public string $path = '';

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $query = [];

    /** @var array<string, string> */
    #[Input(type: PortType::Json, required: false)]
    public array $headers = [];

    #[Input(type: PortType::Any, required: false)]
    public mixed $body = null;

    #[Input(type: PortType::Bool, required: false)]
    public bool $failOnError = true;

    #[Output(type: PortType::Int, provenance: PortProvenance::Untrusted)]
    public int $status;

    #[Output(type: PortType::Bool, provenance: PortProvenance::Untrusted)]
    public bool $ok;

    // `key:` names the port `body` / `headers`; the properties carry a
    // different name only because the inputs already use those.
    #[Output(type: PortType::Any, key: 'body', provenance: PortProvenance::Untrusted)]
    public mixed $responseBody;

    /** @var array<string, string> */
    #[Output(type: PortType::Json, key: 'headers', provenance: PortProvenance::Untrusted)]
    public array $responseHeaders;

    public function __construct(
        private readonly Factory $http,
        private readonly HttpConnectionRegistry $connections,
        private readonly EgressGuard $guard,
    ) {}

    public function execute(NodeContext $context): NodeResult
    {
        if ($context->dryRun) {
            return NodeResult::dryRunSkipped();
        }

        $name = (string) ($context->inputs['connection'] ?? '');

        try {
            $connection = $this->connections->get($name);
            $method = strtoupper((string) ($context->inputs['method'] ?? 'GET'));

            if (! in_array($method, self::METHODS, true)) {
                throw new HttpRequestFailedException(sprintf('HTTP method [%s] is not allowed; use one of: %s.', $method, implode(', ', self::METHODS)));
            }

            $url = $this->url($connection, (string) ($context->inputs['path'] ?? ''));
            $addresses = $this->guard->assertAllowed($connection, $url);

            $response = $this->send($context, $connection, $method, $url, $addresses);
        } catch (HttpNodeException $e) {
            return NodeResult::failed($e);
        } catch (Throwable $e) {
            // A transport failure's message can embed the URL (and so a query
            // token); report the class only.
            return NodeResult::failed(HttpRequestFailedException::forTransport($name, $e::class));
        }

        $status = $response['status'];

        if ($status >= 400 && (bool) ($context->inputs['failOnError'] ?? true)) {
            return NodeResult::failed(HttpRequestFailedException::forStatus($name, $status));
        }

        return NodeResult::success([
            'status' => $status,
            'ok' => $status >= 200 && $status < 300,
            'body' => $response['body'],
            'headers' => $response['headers'],
        ]);
    }

    /**
     * @throws HttpNodeException
     */
    private function url(HttpConnection $connection, string $path): string
    {
        // `path` is relative to the connection's base URL. Anything that could
        // change the host, smuggle a header or climb out of the base is refused.
        if ($path !== '' && (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $path) === 1
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
            || in_array('..', explode('/', explode('?', $path, 2)[0]), true))) {
            throw new HttpRequestFailedException(sprintf('Connection [%s]: `path` must be a plain relative path.', $connection->name));
        }

        return $connection->baseUrl.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * @param  list<string>  $addresses
     * @return array{status: int, body: mixed, headers: array<string, string>}
     *
     * @throws HttpNodeException
     */
    private function send(NodeContext $context, HttpConnection $connection, string $method, string $url, array $addresses): array
    {
        $request = $this->http
            ->withOptions($this->options($connection, $url, $addresses))
            ->withHeaders($this->requestHeaders($context, $connection, $method));

        $request = $this->authenticate($request, $connection);
        $options = [];

        $query = $context->inputs['query'] ?? [];
        if (is_array($query) && $query !== []) {
            $options['query'] = $query;
        }

        $body = $context->inputs['body'] ?? null;
        if ($body !== null && ! in_array($method, ['GET', 'HEAD'], true)) {
            if (is_array($body)) {
                $options['json'] = $body;
            } else {
                $options['body'] = is_scalar($body) ? (string) $body : json_encode($body, JSON_THROW_ON_ERROR);
            }
        }

        $response = $request->send($method, $url, $options);
        $psr = $response->toPsrResponse();

        $declared = $psr->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $connection->maxResponseBytes) {
            throw HttpRequestFailedException::forOversizedResponse($connection->name, $connection->maxResponseBytes);
        }

        $raw = $this->readBounded($psr->getBody(), $connection);

        return [
            'status' => $response->status(),
            'body' => $this->decode($raw, $psr->getHeaderLine('Content-Type')),
            'headers' => $this->responseHeaders($psr->getHeaders()),
        ];
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, mixed>
     */
    private function options(HttpConnection $connection, string $url, array $addresses): array
    {
        $options = [
            'allow_redirects' => false,
            'timeout' => $connection->timeout,
            'connect_timeout' => $connection->connectTimeout,
            'verify' => $connection->verify,
            'stream' => true,
        ];

        // Best effort against DNS rebinding: make libcurl connect to the address
        // the guard just approved instead of resolving the name a second time.
        if (defined('CURLOPT_RESOLVE') && $addresses !== []) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
            $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $connection->host, $port, $ip)]];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     *
     * @throws HttpNodeException
     */
    private function requestHeaders(NodeContext $context, HttpConnection $connection, string $method): array
    {
        $headers = $connection->headers;
        $input = $context->inputs['headers'] ?? [];

        foreach (is_array($input) ? $input : [] as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1
                || ! is_scalar($value) || preg_match('/[\r\n]/', (string) $value) === 1
            ) {
                throw new HttpRequestFailedException(sprintf('Connection [%s]: an entry of `headers` is not a valid header.', $connection->name));
            }

            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new HttpRequestFailedException(sprintf('Connection [%s]: header [%s] is managed by the connection and cannot be set by the node.', $connection->name, strtolower($name)));
            }

            $headers[$name] = (string) $value;
        }

        // One key per node, stable across retries, so a receiver can de-duplicate.
        if ($connection->idempotencyHeader !== null && ! in_array($method, ['GET', 'HEAD'], true)) {
            $headers[$connection->idempotencyHeader] = hash('sha256', $context->flowRunId.'|'.$context->nodeId);
        }

        return $headers;
    }

    private function authenticate(PendingRequest $request, HttpConnection $connection): PendingRequest
    {
        return match ($connection->authType) {
            'bearer' => $request->withToken($connection->auth['token']),
            'basic' => $request->withBasicAuth($connection->auth['username'], $connection->auth['password']),
            'header' => $request->withHeaders([$connection->auth['header'] => $connection->auth['value']]),
            default => $request,
        };
    }

    /**
     * Reads at most the connection's cap. The body is streamed, so an oversized
     * response is refused without ever being held in memory whole.
     *
     * @throws HttpNodeException
     */
    private function readBounded(StreamInterface $body, HttpConnection $connection): string
    {
        $raw = '';

        while (! $body->eof()) {
            $raw .= $body->read(8192);

            if (strlen($raw) > $connection->maxResponseBytes) {
                throw HttpRequestFailedException::forOversizedResponse($connection->name, $connection->maxResponseBytes);
            }
        }

        return $raw;
    }

    private function decode(string $raw, string $contentType): mixed
    {
        if ($raw === '' || ! str_contains(strtolower($contentType), 'json')) {
            return $raw;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }
    }

    /**
     * @param  array<string, array<string>>  $headers
     * @return array<string, string>
     */
    private function responseHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);

            // A session cookie is a credential; never persist it as run data.
            if ($name === 'set-cookie') {
                continue;
            }

            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}
