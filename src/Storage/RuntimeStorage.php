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

class RuntimeStorage implements StorageInterface
{
    /** @var array<string, \Nelexa\Doh\Storage\StorageItem> */
    private $cache = [];

    public function get(string $domainName): ?StorageItem
    {
        $storageItem = $this->cache[$domainName] ?? null;

        if ($storageItem !== null && $storageItem->getExpiredAt() < new \DateTimeImmutable()) {
            unset($this->cache[$domainName]);
            $storageItem = null;
        }

        return $storageItem;
    }

    public function save(string $domainName, StorageItem $item): void
    {
        $this->cache[$domainName] = $item;
    }
}
