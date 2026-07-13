<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Http;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;
use Padosoft\LaravelFlow\WebhookDeliveryClient;
use Padosoft\LaravelFlowConnect\Exceptions\WebhookVerificationException;

/**
 * Verifies an inbound webhook request against the SAME HMAC scheme core's
 * OUTBOUND delivery uses ({@see WebhookDeliveryClient}):
 * header `X-Laravel-Flow-Signature: t={unix timestamp},v1={hex(hmac_sha256(
 * "{timestamp}.{raw body}", $secret))}`. Inbound and outbound share this
 * convention deliberately — a host application signing its own webhooks (to
 * receive core's outbound deliveries) already owns compatible signing code.
 *
 * Three independent checks, in order (each throws a distinct, safe-to-return
 * reason): (1) the signature header is present, well-formed, and its HMAC
 * matches (`hash_equals()` — timing-safe); (2) the embedded timestamp falls
 * within `$replayWindowSeconds` of now; (3) the exact signature has not
 * already been consumed within that same window (a replay of the identical
 * request reproduces the identical signature, since the signature is a
 * function of the timestamp+body — so caching "seen" signatures for the
 * window IS the nonce, no separate request-id header needed). Pure logic —
 * takes raw strings, not a framework `Request`, so it is testable without
 * HTTP request construction.
 *
 * @internal
 */
final class WebhookRequestVerifier
{
    private const SIGNATURE_HEADER_PATTERN = '/^t=(\d+),v1=([0-9a-f]{64})$/';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @return array<string, mixed> the decoded JSON payload on success
     */
    public function verify(
        ?string $signatureHeader,
        string $body,
        string $secret,
        int $replayWindowSeconds,
        string $nonceCacheKeyPrefix,
    ): array {
        $signature = $this->verifySignature($signatureHeader, $body, $secret);
        $this->verifyReplayWindow($signature['timestamp'], $replayWindowSeconds);
        $this->verifyNotReplayed($nonceCacheKeyPrefix, $signature['raw'], $signature['timestamp'], $replayWindowSeconds);

        return $this->decodePayload($body);
    }

    /**
     * @return array{timestamp: int, raw: string}
     */
    private function verifySignature(?string $signatureHeader, string $body, string $secret): array
    {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new WebhookVerificationException('missing signature', 401);
        }

        if (preg_match(self::SIGNATURE_HEADER_PATTERN, $signatureHeader, $matches) !== 1) {
            throw new WebhookVerificationException('malformed signature header', 401);
        }

        $timestamp = $matches[1];
        $providedSignature = $matches[2];
        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw new WebhookVerificationException('invalid signature', 401);
        }

        return ['timestamp' => (int) $timestamp, 'raw' => $signatureHeader];
    }

    private function verifyReplayWindow(int $timestamp, int $replayWindowSeconds): void
    {
        if (abs(time() - $timestamp) > $replayWindowSeconds) {
            throw new WebhookVerificationException('timestamp outside the replay window', 401);
        }
    }

    private function verifyNotReplayed(string $keyPrefix, string $signatureHeader, int $timestamp, int $replayWindowSeconds): void
    {
        // The signature header already binds timestamp+body+secret, so it is
        // itself a valid nonce: a genuine replay of the SAME request carries
        // the IDENTICAL header value. `add()` is atomic (fails if the key
        // already exists) so two concurrent deliveries of the same replayed
        // request cannot both pass this check via a read-then-write race.
        $key = $keyPrefix.':'.hash('sha256', $signatureHeader);

        // TTL is measured from the SIGNED timestamp's own validity window
        // end ($timestamp + $replayWindowSeconds), NOT from "now": a
        // future-skewed timestamp (accepted by verifyReplayWindow() up to
        // $replayWindowSeconds ahead) would otherwise have its nonce expire
        // at "now + window" — earlier than the timestamp's OWN window end —
        // leaving a gap where a replay could slip through after the nonce
        // expired but before the timestamp itself would be rejected as
        // stale. max(1, ...) guards against a non-positive TTL for a
        // timestamp already at/past the edge of its window.
        $ttl = max(1, ($timestamp + $replayWindowSeconds) - time());

        if (! $this->cache->add($key, true, $ttl)) {
            throw new WebhookVerificationException('replayed request', 401);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookVerificationException('malformed JSON payload', 422);
        }

        if (! is_array($decoded)) {
            throw new WebhookVerificationException('JSON payload must decode to an object/array', 422);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
