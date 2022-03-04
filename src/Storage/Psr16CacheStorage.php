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

namespace Nelexa\Doh\Storage;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class Psr16CacheStorage implements StorageInterface
{
    /** @var CacheInterface */
    private $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $domainName): ?DnsRecord
    {
        $cacheKey = $this->getCacheKey($domainName);

        /** @psalm-suppress InvalidCatch */
        try {
            $dnsRecord = $this->cache->get($cacheKey);

            if (!$dnsRecord instanceof DnsRecord) {
                return null;
            }

            return $dnsRecord;
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException('Invalid cache key ' . $cacheKey, 0, $e);
        }
    }

    public function save(string $domainName, DnsRecord $dnsRecord): void
    {
        $cacheKey = $this->getCacheKey($domainName);

        /** @psalm-suppress InvalidCatch,InvalidArgument */
        try {
            $this->cache->set($cacheKey, $dnsRecord, $dnsRecord->getTTL());
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException('Invalid cache key ' . $cacheKey, 0, $e);
        }
    }

    private function getCacheKey(string $domainName): string
    {
        return rawurlencode('guzzle.doh.psr16.' . $domainName);
    }
}
