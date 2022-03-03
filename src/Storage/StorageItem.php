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

use LibDNS\Records\ResourceTypes;

class StorageItem implements \Countable
{
    /** @var bool */
    public $cacheHit = false;

    /** @var string */
    private $domainName;

    /** @var string[] */
    private $data;

    /** @var int */
    private $resourceType;

    /** @var \DateInterval */
    private $ttl;

    /** @var \DateTimeImmutable */
    private $expiredAt;

    /**
     * @param string   $domainName
     * @param string[] $data
     * @param int      $resourceType
     * @param mixed    $ttl
     */
    public function __construct(string $domainName, array $data, int $resourceType, $ttl)
    {
        $this->domainName = $domainName;
        $this->data = $data;
        $this->resourceType = $resourceType;

        if (\is_int($ttl)) {
            $ttl .= ' seconds';
        }

        if (\is_string($ttl)) {
            /** @var \DateInterval|false $interval */
            $interval = \DateInterval::createFromDateString($ttl);

            if ($interval === false) {
                throw new \RuntimeException('Error create DateInterval from ' . $ttl);
            }

            $ttl = $interval;
        }

        if (!$ttl instanceof \DateInterval) {
            throw new \InvalidArgumentException('Invalid ttl type. Support only integer, string or DateInterval type.');
        }

        $this->ttl = $ttl;
        $this->expiredAt = (new \DateTimeImmutable())->add($ttl);
    }

    /**
     * @return string
     */
    public function getDomainName(): string
    {
        return $this->domainName;
    }

    /**
     * @return string[]
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return \DateInterval
     */
    public function getTTL(): \DateInterval
    {
        return $this->ttl;
    }

    /**
     * @return \DateTimeImmutable
     */
    public function getExpiredAt(): \DateTimeImmutable
    {
        return $this->expiredAt;
    }

    /**
     * @return int
     */
    public function getResourceType(): int
    {
        return $this->resourceType;
    }

    public function isCnameRecord(): bool
    {
        return $this->resourceType === ResourceTypes::CNAME;
    }

    public function isARecord(): bool
    {
        return $this->resourceType === ResourceTypes::A || $this->resourceType === ResourceTypes::AAAA;
    }

    public function count(): int
    {
        return \count($this->data);
    }
}
