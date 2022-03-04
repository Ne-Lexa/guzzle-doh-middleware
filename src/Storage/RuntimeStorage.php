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
    /** @var array<string, \Nelexa\Doh\Storage\DnsRecord> */
    private $cache = [];

    public function get(string $domainName): ?DnsRecord
    {
        $dnsRecord = $this->cache[$domainName] ?? null;

        if ($dnsRecord !== null && $dnsRecord->getExpiredAt() < new \DateTimeImmutable()) {
            unset($this->cache[$domainName]);
            $dnsRecord = null;
        }

        return $dnsRecord;
    }

    public function save(string $domainName, DnsRecord $dnsRecord): void
    {
        $this->cache[$domainName] = $dnsRecord;
    }
}
