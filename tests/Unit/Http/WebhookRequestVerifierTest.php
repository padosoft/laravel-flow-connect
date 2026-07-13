<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Tests\Unit\Http;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Padosoft\LaravelFlowConnect\Exceptions\WebhookVerificationException;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookRequestVerifierTest extends TestCase
{
    private const SECRET = 'shh-its-a-secret';

    private function verifier(): WebhookRequestVerifier
    {
        return new WebhookRequestVerifier(new Repository(new ArrayStore));
    }

    private function signatureHeader(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }

    public function test_valid_signature_and_json_body_returns_the_decoded_payload(): void
    {
        $body = json_encode(['order_id' => 42]);

        $payload = $this->verifier()->verify(
            $this->signatureHeader($body),
            $body,
            self::SECRET,
            300,
            'test',
        );

        $this->assertSame(['order_id' => 42], $payload);
    }

    public function test_missing_signature_header_is_rejected_401(): void
    {
        $body = json_encode(['a' => 1]);

        try {
            $this->verifier()->verify(null, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
        }
    }

    public function test_malformed_signature_header_is_rejected_401(): void
    {
        $body = json_encode(['a' => 1]);

        try {
            $this->verifier()->verify('not-a-valid-header', $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
        }
    }

    public function test_tampered_body_is_rejected_401(): void
    {
        $originalBody = json_encode(['a' => 1]);
        $header = $this->signatureHeader($originalBody);
        $tamperedBody = json_encode(['a' => 999]);

        try {
            $this->verifier()->verify($header, $tamperedBody, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('signature', $e->getMessage());
        }
    }

    public function test_wrong_secret_is_rejected_401(): void
    {
        $body = json_encode(['a' => 1]);
        $header = $this->signatureHeader($body, secret: 'a-different-secret');

        try {
            $this->verifier()->verify($header, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
        }
    }

    public function test_expired_timestamp_is_rejected_401(): void
    {
        $body = json_encode(['a' => 1]);
        $staleTimestamp = time() - 3600;
        $header = $this->signatureHeader($body, $staleTimestamp);

        try {
            $this->verifier()->verify($header, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('replay window', $e->getMessage());
        }
    }

    public function test_a_future_timestamp_outside_the_window_is_also_rejected(): void
    {
        $body = json_encode(['a' => 1]);
        $futureTimestamp = time() + 3600;
        $header = $this->signatureHeader($body, $futureTimestamp);

        try {
            $this->verifier()->verify($header, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
        }
    }

    public function test_replaying_the_identical_request_within_the_window_is_rejected(): void
    {
        $body = json_encode(['a' => 1]);
        $header = $this->signatureHeader($body);
        $verifier = $this->verifier();

        $verifier->verify($header, $body, self::SECRET, 300, 'test');

        try {
            $verifier->verify($header, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException on replay');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('replayed', $e->getMessage());
        }
    }

    public function test_nonce_ttl_covers_the_signed_timestamps_own_replay_window_not_just_from_now(): void
    {
        // A future-skewed timestamp (accepted up to $window seconds ahead)
        // must keep its nonce alive until the timestamp's OWN validity
        // window ends ($timestamp + $window), not merely $window seconds
        // from acceptance time — otherwise the nonce could expire before a
        // replay arriving near the tail of the timestamp's valid window,
        // reopening exactly the gap this check exists to close.
        $window = 100;
        $timestamp = time() + 90; // near the future edge of a 100s window
        $body = json_encode(['a' => 1]);
        $header = $this->signatureHeader($body, $timestamp);

        $cache = new class(new ArrayStore) extends Repository
        {
            /** @var list<int> */
            public array $capturedTtls = [];

            public function add($key, $value, $ttl = null)
            {
                if (is_int($ttl)) {
                    $this->capturedTtls[] = $ttl;
                }

                return parent::add($key, $value, $ttl);
            }
        };

        (new WebhookRequestVerifier($cache))->verify($header, $body, self::SECRET, $window, 'test');

        $this->assertCount(1, $cache->capturedTtls);
        // Expected TTL ~= ($timestamp + $window) - now() ~= 190s, NOT the
        // naive $window (100s) the pre-fix implementation used.
        $this->assertGreaterThan($window, $cache->capturedTtls[0]);
    }

    public function test_two_different_nonce_prefixes_do_not_cross_contaminate_replay_state(): void
    {
        // Two different webhook slugs sharing the same secret must not let a
        // request accepted on one slug's route count as a replay on another.
        $body = json_encode(['a' => 1]);
        $header = $this->signatureHeader($body);
        $verifier = $this->verifier();

        $verifier->verify($header, $body, self::SECRET, 300, 'slug-a');

        $payload = $verifier->verify($header, $body, self::SECRET, 300, 'slug-b');

        $this->assertSame(['a' => 1], $payload);
    }

    public function test_malformed_json_body_is_rejected_422(): void
    {
        $body = '{not valid json';
        $header = $this->signatureHeader($body);

        try {
            $this->verifier()->verify($header, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(422, $e->statusCode);
        }
    }

    public function test_a_json_scalar_body_is_rejected_422(): void
    {
        $body = '"just-a-string"';
        $header = $this->signatureHeader($body);

        try {
            $this->verifier()->verify($header, $body, self::SECRET, 300, 'test');
            $this->fail('expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertSame(422, $e->statusCode);
        }
    }
}
