<?php

declare(strict_types=1);

/*
 * Copyright (c) Ne-Lexa
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/Ne-Lexa/guzzle-doh-middleware
 */

namespace Nelexa\Doh\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Nelexa\Doh\DohMiddleware;
use Nelexa\Doh\DohServers;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * @internal
 *
 * @small
 */
final class DohMiddlewareTest extends TestCase
{
    /**
     * @dataProvider provideCache
     *
     * @param mixed  $cache
     * @param string $url
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exception
     */
    public function testCache($cache, string $url): void
    {
        $stack = HandlerStack::create();
        $stack->push(DohMiddleware::create($cache, [], null, true), 'doh');
        $httpClient = new Client(['handler' => $stack]);

        $primaryIp = null;
        $response = $httpClient->request('GET', $url, [
            RequestOptions::HEADERS => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.7113.93 Safari/537.36',
            ],
            RequestOptions::DEBUG => true,
            'on_stats' => static function (TransferStats $stats) use (&$primaryIp): void {
                $primaryIp = $stats->getHandlerStats()['primary_ip'] ?? null;
            },
        ]);
        self::assertNotNull($primaryIp);

        $resolvedIps = explode(',', $response->getHeaderLine(DohMiddleware::HEADER_RESPONSE_RESOLVED_IPS));
        self::assertContains($primaryIp, $resolvedIps);

        $cachedDnsResolveResponse = $httpClient->request('GET', $url, [
            RequestOptions::HEADERS => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.7113.93 Safari/537.36',
            ],
            RequestOptions::DEBUG => true,
        ]);

        $cacheHit = $cachedDnsResolveResponse->getHeaderLine(DohMiddleware::HEADER_RESPONSE_CACHE_HIT);
        self::assertSame('true', $cacheHit);

        if ($cacheHit === 'true') {
            $cacheTtl = (int) $cachedDnsResolveResponse->getHeaderLine(DohMiddleware::HEADER_RESPONSE_CACHE_TTL);
            self::assertGreaterThan(0, $cacheTtl);
        }
    }

    public function provideCache(): iterable
    {
        $caches = [
            'runtime' => null,
            'psr6' => new FilesystemAdapter('doh.test.psr6'),
            'psr16' => new Psr16Cache(new FilesystemAdapter('doh.test.psr16')),
        ];

        $sites = [
            'https://httpbin.org/get',
        ];

        // matrix
        foreach ($caches as $key => $cache) {
            foreach ($sites as $url) {
                yield $key . ' - ' . $url => [$cache, $url];
            }
        }
    }

    /**
     * @dataProvider providerDohServers
     *
     * @param string $dohServer
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testDifferentServers(string $dohServer): void
    {
        $stack = HandlerStack::create();
        $stack->push(DohMiddleware::create(null, [$dohServer], new NullLogger(), true), 'doh');
        $httpClient = new Client([
            'handler' => $stack,
        ]);

        $response = $httpClient->request('GET', 'https://httpbin.org/get', [
            RequestOptions::DEBUG => true,
            'on_stats' => static function (TransferStats $stats) use (&$primaryIp): void {
                $primaryIp = $stats->getHandlerStats()['primary_ip'] ?? null;
            },
        ]);
        self::assertNotNull($primaryIp);

        $resolvedIps = explode(',', $response->getHeaderLine(DohMiddleware::HEADER_RESPONSE_RESOLVED_IPS));
        self::assertContains($primaryIp, $resolvedIps);
    }

    public function providerDohServers(): iterable
    {
        $refClass = new \ReflectionClass(DohServers::class);
        $constants = $refClass->getConstants();
        $constants = array_filter($constants, static function (string $constantName) {
            return strpos($constantName, 'SERVER_') === 0;
        }, \ARRAY_FILTER_USE_KEY);

        foreach ($constants as $constantName => $serverUrl) {
            yield $constantName => [$serverUrl];
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testDisableDoh(): void
    {
        $stack = HandlerStack::create();
        $stack->push(DohMiddleware::create(null, [], null, true), 'doh');
        $httpClient = new Client(['handler' => $stack]);

        $response = $httpClient->request('GET', 'https://www.google.com', [
            RequestOptions::DEBUG => true,
            DohMiddleware::OPTION_DOH_ENABLED => false,
        ]);

        self::assertFalse($response->hasHeader(DohMiddleware::HEADER_RESPONSE_RESOLVED_IPS));
        self::assertFalse($response->hasHeader(DohMiddleware::HEADER_RESPONSE_CACHE_HIT));
        self::assertFalse($response->hasHeader(DohMiddleware::HEADER_RESPONSE_CACHE_TTL));
    }
}
