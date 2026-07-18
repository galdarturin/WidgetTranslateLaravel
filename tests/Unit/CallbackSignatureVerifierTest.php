<?php

namespace Newtxt\Laravel\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Newtxt\Laravel\Security\CallbackSignatureVerifier;
use PHPUnit\Framework\TestCase;

class CallbackSignatureVerifierTest extends TestCase
{
    public function test_it_accepts_a_fresh_valid_hmac_signature(): void
    {
        $body = json_encode(['action' => 'health.check'], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'callback-secret');
        $request = Request::create('/newtxt/callback', 'POST', [], [], [], [], $body);
        $request->headers->set('X-NewTXT-Timestamp', $timestamp);
        $request->headers->set('X-NewTXT-Signature', 'sha256=' . $signature);

        $verifier = new CallbackSignatureVerifier($this->config(), $this->cache());

        $this->assertTrue($verifier->verify($request));
    }

    public function test_it_rejects_replayed_signed_callbacks(): void
    {
        $body = json_encode(['action' => 'health.check'], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'callback-secret');
        $request = Request::create('/newtxt/callback', 'POST', [], [], [], [], $body);
        $request->headers->set('X-NewTXT-Timestamp', $timestamp);
        $request->headers->set('X-NewTXT-Signature', 'sha256=' . $signature);
        $verifier = new CallbackSignatureVerifier($this->config(), $this->cache());

        $this->assertTrue($verifier->verify($request));
        $this->assertFalse($verifier->verify($request));
    }

    public function test_it_rejects_stale_signatures(): void
    {
        $body = json_encode(['action' => 'health.check'], JSON_THROW_ON_ERROR);
        $timestamp = (string) (time() - 600);
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'callback-secret');
        $request = Request::create('/newtxt/callback', 'POST', [], [], [], [], $body);
        $request->headers->set('X-NewTXT-Timestamp', $timestamp);
        $request->headers->set('X-NewTXT-Signature', $signature);

        $verifier = new CallbackSignatureVerifier($this->config(), $this->cache());

        $this->assertFalse($verifier->verify($request));
    }

    public function test_it_rejects_placeholder_callback_secrets(): void
    {
        $body = json_encode(['action' => 'health.check'], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'placeholder-private-key');
        $request = Request::create('/newtxt/callback', 'POST', [], [], [], [], $body);
        $request->headers->set('X-NewTXT-Timestamp', $timestamp);
        $request->headers->set('X-NewTXT-Signature', $signature);

        $verifier = new CallbackSignatureVerifier(new ConfigRepository([
            'newtxt' => [
                'callback_secret' => 'placeholder-private-key',
                'callback_timestamp_header' => 'X-NewTXT-Timestamp',
                'callback_signature_header' => 'X-NewTXT-Signature',
                'callback_tolerance_seconds' => 300,
            ],
        ]), $this->cache());

        $this->assertFalse($verifier->verify($request));
    }

    private function config(): ConfigRepository
    {
        return new ConfigRepository([
            'newtxt' => [
                'callback_secret' => 'callback-secret',
                'callback_timestamp_header' => 'X-NewTXT-Timestamp',
                'callback_signature_header' => 'X-NewTXT-Signature',
                'callback_tolerance_seconds' => 300,
            ],
        ]);
    }

    private function cache(): CacheRepository
    {
        return new CacheRepository(new ArrayStore());
    }
}
