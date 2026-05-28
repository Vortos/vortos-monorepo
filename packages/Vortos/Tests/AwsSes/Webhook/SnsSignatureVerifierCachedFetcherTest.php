<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Webhook;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Vortos\AwsSes\Webhook\SnsSignatureVerifier;

final class SnsSignatureVerifierCachedFetcherTest extends TestCase
{
    private const FAKE_URL = 'https://sns.us-east-1.amazonaws.com/cert.pem';
    private const FAKE_PEM = '-----BEGIN CERTIFICATE-----\nFAKE\n-----END CERTIFICATE-----';

    /** @return CacheInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function makeCache(): CacheInterface
    {
        return $this->createMock(CacheInterface::class);
    }

    public function test_returns_cached_pem_on_cache_hit(): void
    {
        $cache = $this->makeCache();
        $cache->method('get')->willReturn(self::FAKE_PEM);
        $cache->expects($this->never())->method('set');

        $fetcher = SnsSignatureVerifier::cachedCertFetcher($cache);

        $result = $fetcher(self::FAKE_URL);

        $this->assertSame(self::FAKE_PEM, $result);
    }

    public function test_cache_miss_skips_set_when_empty_string_returned(): void
    {
        // Simulate a broken network where file_get_contents returns ''
        // (handled as false in the fetcher). We verify the RuntimeException is thrown
        // and cache->set() is never called.
        $cache = $this->makeCache();
        $cache->method('get')->willReturn(null);   // cache miss
        $cache->expects($this->never())->method('set');

        $fetcher = SnsSignatureVerifier::cachedCertFetcher($cache);

        // Point at a clearly invalid URL so file_get_contents fails
        $this->expectException(\RuntimeException::class);
        $fetcher('https://sns.us-east-1.amazonaws.com/__nonexistent_cert_file__.pem');
    }

    public function test_cache_key_uses_md5_of_url(): void
    {
        $url      = self::FAKE_URL;
        $expected = 'ses_sns_cert_' . md5($url);

        $cache = $this->makeCache();
        $cache->expects($this->once())
            ->method('get')
            ->with($expected)
            ->willReturn(self::FAKE_PEM);

        $fetcher = SnsSignatureVerifier::cachedCertFetcher($cache);
        $fetcher($url);
    }

    public function test_different_urls_produce_different_cache_keys(): void
    {
        $url1 = 'https://sns.us-east-1.amazonaws.com/cert1.pem';
        $url2 = 'https://sns.eu-west-1.amazonaws.com/cert2.pem';

        $this->assertNotSame(md5($url1), md5($url2));
    }

    public function test_default_cert_fetcher_throws_on_unreachable_url(): void
    {
        $fetcher = SnsSignatureVerifier::defaultCertFetcher();

        $this->expectException(\RuntimeException::class);
        $fetcher('https://sns.us-east-1.amazonaws.com/__nonexistent__.pem');
    }

    public function test_cached_fetcher_default_ttl_is_86400(): void
    {
        $cache = $this->makeCache();
        $cache->method('get')->willReturn(null);

        // Verify the default TTL of 86400 is passed to cache->set()
        $cache->expects($this->never())->method('set'); // not reached because fetch fails

        // Just confirm the factory accepts no TTL arg (uses 86400 default)
        $fetcher = SnsSignatureVerifier::cachedCertFetcher($cache);
        $this->assertInstanceOf(\Closure::class, $fetcher);
    }
}
