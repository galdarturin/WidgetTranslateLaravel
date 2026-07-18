<?php

namespace Newtxt\Laravel\Security;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Newtxt\Laravel\Support\ConfigCredential;

class CallbackSignatureVerifier
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * Verify the signed callback body and timestamp.
     *
     * The signature format is HMAC-SHA256 over "{timestamp}.{rawBody}". The
     * received header may contain either the raw hex digest or "sha256=<hex>".
     */
    public function verify(Request $request): bool
    {
        $secret = ConfigCredential::value($this->config->get('newtxt.callback_secret', ''));
        if ($secret === '') {
            return false;
        }

        $timestamp = $this->timestamp($request);
        if ($timestamp === null || !$this->timestampIsFresh($timestamp)) {
            return false;
        }

        $signature = $this->signature($request);
        if ($signature === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return $this->markCallbackAsFresh($timestamp, $signature, $request->getContent());
    }

    /**
     * Read and normalize the timestamp header.
     */
    private function timestamp(Request $request): ?string
    {
        $header = (string) $this->config->get('newtxt.callback_timestamp_header', 'X-NewTXT-Timestamp');
        $timestamp = trim((string) $request->headers->get($header, ''));

        return ctype_digit($timestamp) ? $timestamp : null;
    }

    /**
     * Read and normalize the signature header.
     */
    private function signature(Request $request): ?string
    {
        $header = (string) $this->config->get('newtxt.callback_signature_header', 'X-NewTXT-Signature');
        $signature = strtolower(trim((string) $request->headers->get($header, '')));
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 ? $signature : null;
    }

    /**
     * Reject stale callbacks to reduce replay risk.
     */
    private function timestampIsFresh(string $timestamp): bool
    {
        $tolerance = $this->callbackToleranceSeconds();

        return abs(time() - (int) $timestamp) <= $tolerance;
    }

    /**
     * Reserve a signed callback digest so it cannot be replayed.
     */
    private function markCallbackAsFresh(string $timestamp, string $signature, string $body): bool
    {
        if (!(bool) $this->config->get('newtxt.callback_replay_protection', true)) {
            return true;
        }

        $prefix = trim((string) $this->config->get('newtxt.callback_replay_cache_prefix', 'newtxt:callback-replay'), ':');
        $digest = sha1($timestamp . '.' . $signature . '.' . hash('sha256', $body));

        return $this->cache->add($prefix . ':' . $digest, gmdate('c'), $this->callbackToleranceSeconds());
    }

    /**
     * Return the accepted timestamp and replay window.
     */
    private function callbackToleranceSeconds(): int
    {
        return max(30, (int) $this->config->get('newtxt.callback_tolerance_seconds', 300));
    }
}
