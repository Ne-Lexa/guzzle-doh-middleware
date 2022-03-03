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
use Psr\SimpleCache\CacheInterface;

final class StorageFactory
{
    /**
     * @param \Psr\SimpleCache\CacheInterface|\Psr\Cache\CacheItemPoolInterface|\Nelexa\Doh\Storage\StorageInterface|null $cache
     */
    public static function create($cache): StorageInterface
    {
        if ($cache instanceof StorageInterface) {
            return $cache;
        }

        if ($cache instanceof CacheInterface) {
            return new Psr16CacheStorage($cache);
        }

        if ($cache instanceof CacheItemPoolInterface) {
            return new Psr6CacheStorage($cache);
        }

        return new RuntimeStorage();
    }
}
