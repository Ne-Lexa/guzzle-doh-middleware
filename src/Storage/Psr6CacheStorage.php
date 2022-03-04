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

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class Psr6CacheStorage implements StorageInterface
{
    /** @var CacheItemPoolInterface */
    private $cachePool;

    public function __construct(CacheItemPoolInterface $cachePool)
    {
        $this->cachePool = $cachePool;
    }

    public function get(string $domainName): ?DnsRecord
    {
        $cacheKey = $this->getCacheKey($domainName);

        /** @psalm-suppress InvalidCatch */
        try {
            $dnsRecord = $this->cachePool->getItem($cacheKey)->get();

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

        /** @psalm-suppress InvalidCatch */
        try {
            $cacheItem = $this->cachePool->getItem($cacheKey);
            $cacheItem->expiresAfter($dnsRecord->getTTL());
            $cacheItem->set($dnsRecord);
            $this->cachePool->save($cacheItem);
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException('Invalid cache key ' . $cacheKey, 0, $e);
        }
    }

    private function getCacheKey(string $domainName): string
    {
        return rawurlencode('guzzle.doh.psr6.' . $domainName);
    }
}
