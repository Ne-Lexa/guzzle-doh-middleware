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

    public function get(string $domainName): ?StorageItem
    {
        $cacheKey = $this->getCacheKey($domainName);

        /** @psalm-suppress InvalidCatch */
        try {
            $storageItem = $this->cache->get($cacheKey);

            if (!$storageItem instanceof StorageItem) {
                return null;
            }

            return $storageItem;
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException('Invalid cache key ' . $cacheKey, 0, $e);
        }
    }

    public function save(string $domainName, StorageItem $item): void
    {
        $cacheKey = $this->getCacheKey($domainName);

        /** @psalm-suppress InvalidCatch */
        try {
            $this->cache->set($cacheKey, $item, $item->getTTL());
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException('Invalid cache key ' . $cacheKey, 0, $e);
        }
    }

    private function getCacheKey(string $domainName): string
    {
        return rawurlencode('guzzle.doh.psr16.' . $domainName);
    }
}
