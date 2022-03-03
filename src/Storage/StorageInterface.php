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

interface StorageInterface
{
    public function get(string $domainName): ?StorageItem;

    public function save(string $domainName, StorageItem $item): void;
}
