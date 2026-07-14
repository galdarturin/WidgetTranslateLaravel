<?php

namespace Newtxt\Laravel\Security;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

class CallbackSignatureVerifier
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * Verify the signed callback body and timestamp.
     *
     * The signature format is HMAC-SHA256 over "{timestamp}.{rawBody}". The
     * received header may contain either the raw hex digest or "sha256=<hex>".
     */
    public function verify(Request $request): bool
    {
        $secret = trim((string) $this->config->get('newtxt.callback_secret', ''));
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

        return hash_equals($expected, $signature);
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
        $tolerance = max(30, (int) $this->config->get('newtxt.callback_tolerance_seconds', 300));

        return abs(time() - (int) $timestamp) <= $tolerance;
    }
}
