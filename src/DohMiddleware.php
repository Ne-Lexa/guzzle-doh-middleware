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

namespace Nelexa\Doh;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Nelexa\Doh\Storage\DnsRecord;
use Nelexa\Doh\Storage\StorageFactory;
use Nelexa\Doh\Storage\StorageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DohMiddleware
{
    public const OPTION_DOH_ENABLED = 'doh';

    public const OPTION_DOH_TTL = 'doh_ttl';

    public const OPTION_DOH_SHUFFLE = 'doh_shuffle';

    public const HEADER_RESPONSE_RESOLVED_IPS = 'X-DoH-Ips';

    public const HEADER_RESPONSE_CACHE_HIT = 'X-DoH-Cache-Hit';

    public const HEADER_RESPONSE_CACHE_TTL = 'X-DoH-Cache-TTL';

    private const DEFAULT_OPTIONS = [
        self::OPTION_DOH_ENABLED => true,
        self::OPTION_DOH_TTL => null,
        self::OPTION_DOH_SHUFFLE => false,
    ];

    /** @var \Closure(\Psr\Http\Message\RequestInterface, array): \GuzzleHttp\Promise\PromiseInterface */
    private $nextHandler;

    /** @var \Nelexa\Doh\DomainResolver */
    private $domainResolver;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /**
     * @psalm-param \Closure(\Psr\Http\Message\RequestInterface, array $options): \GuzzleHttp\Promise\PromiseInterface $nextHandler
     *
     * @param \Closure                             $nextHandler
     * @param \Nelexa\Doh\Storage\StorageInterface $storage
     * @param string[]                             $dohServers
     * @param \Psr\Log\LoggerInterface             $logger
     * @param bool                                 $debug
     */
    private function __construct(
        \Closure $nextHandler,
        StorageInterface $storage,
        array $dohServers,
        LoggerInterface $logger,
        bool $debug = false
    ) {
        $this->nextHandler = $nextHandler;
        $this->domainResolver = new DomainResolver($storage, $dohServers, $debug);
        $this->logger = $logger;
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $options += self::DEFAULT_OPTIONS;
        $domainName = $request->getUri()->getHost();

        if (
            !\function_exists('curl_init')
            || $options[self::OPTION_DOH_ENABLED] === false
            || $this->isIpOrLocalDomainName($domainName)
        ) {
            return ($this->nextHandler)($request, $options);
        }

        $dnsRecord = $this->resolve($domainName, $options);

        if ($dnsRecord !== null && $dnsRecord->count() > 0) {
            return $this->appendDnsRecord($request, $options, $dnsRecord);
        }

        $this->logger->warning(sprintf('DoH client could not resolve ip addresses for %s domain', $domainName));

        return ($this->nextHandler)($request, $options);
    }

    /**
     * @param \Psr\SimpleCache\CacheInterface|\Psr\Cache\CacheItemPoolInterface|StorageInterface|null $cache
     * @param string[]                                                                                $dohServers
     * @param \Psr\Log\LoggerInterface|null                                                           $logger
     * @param bool                                                                                    $debug
     *
     * @return callable
     */
    public static function create(
        $cache = null,
        array $dohServers = [],
        ?LoggerInterface $logger = null,
        bool $debug = false
    ): callable {
        $storage = StorageFactory::create($cache);

        return static function (\Closure $handler) use ($storage, $dohServers, $logger, $debug) {
            /** @psalm-var \Closure(\Psr\Http\Message\RequestInterface, array $options): \GuzzleHttp\Promise\PromiseInterface $handler */
            return new self($handler, $storage, $dohServers, $logger ?? new NullLogger(), $debug);
        };
    }

    private function resolve(string $domainName, array $options): ?DnsRecord
    {
        /** @psalm-suppress InvalidCatch */
        try {
            return $this->domainResolver->resolveDomain($domainName, $options);
        } catch (GuzzleException $e) {
            $this->logger->error(
                sprintf('[DoH] Error resolving %s domain', $domainName),
                [
                    'domain' => $domainName,
                    'exception' => $e,
                ]
            );
        }

        return null;
    }

    private function appendDnsRecord(RequestInterface $request, array $options, DnsRecord $dnsRecord): PromiseInterface
    {
        /** @var array<int, mixed> $curlOptions */
        $curlOptions = [
            \CURLOPT_DNS_USE_GLOBAL_CACHE => false, // disable global cache
        ];

        $ipAddresses = $dnsRecord->getData();

        if ($options[self::OPTION_DOH_SHUFFLE]) {
            if ($this->isSupportShuffleIps()) {
                /**
                 * @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection
                 * @psalm-suppress UndefinedConstant
                 */
                $curlOptions[\CURLOPT_DNS_SHUFFLE_ADDRESSES] = true;
            } else {
                shuffle($ipAddresses);
            }
        }

        if (!$this->isSupportMultipleIps()) {
            $ipAddresses = \array_slice($ipAddresses, 0, 1);
        }

        $domainName = $dnsRecord->getDomainName();
        $ipAddressesString = implode(',', $ipAddresses);
        $curlOptions[\CURLOPT_RESOLVE] = [
            $domainName . ':80:' . $ipAddressesString,
            $domainName . ':443:' . $ipAddressesString,
        ];

        $port = $request->getUri()->getPort();

        if ($port !== null && $port !== 80 && $port !== 443) {
            $curlOptions[\CURLOPT_RESOLVE][] = $domainName . ':' . $port . ':' . $ipAddressesString;
        }

        $this->logger->debug(sprintf('[DoH] Set ip addresses %s for domain %s', $ipAddressesString, $domainName));

        $options['curl'] = $curlOptions;

        return ($this->nextHandler)($request, $options)->then(
            static function (ResponseInterface $response) use ($ipAddressesString, $dnsRecord) {
                if ($dnsRecord->cacheHit) {
                    $cacheTtl = max(0, $dnsRecord->getExpiredAt()->getTimestamp() - time());
                    $response = $response->withHeader(self::HEADER_RESPONSE_CACHE_TTL, (string) $cacheTtl);
                }

                return $response
                    ->withHeader(self::HEADER_RESPONSE_CACHE_HIT, var_export($dnsRecord->cacheHit, true))
                    ->withHeader(self::HEADER_RESPONSE_RESOLVED_IPS, $ipAddressesString)
                ;
            }
        );
    }

    /**
     * @see https://curl.haxx.se/libcurl/c/CURLOPT_RESOLVE.html Support for providing multiple IP
     *                                                          addresses per entry was added in 7.59.0.
     *
     * @return bool
     */
    private function isSupportMultipleIps(): bool
    {
        return version_compare((string) curl_version()['version'], '7.59.0', '>=');
    }

    private function isSupportShuffleIps(): bool
    {
        return \PHP_VERSION_ID >= 70300
            && version_compare(
                (string) curl_version()['version'],
                '7.60.0',
                '>='
            );
    }

    private function isIpOrLocalDomainName(string $domainName): bool
    {
        return filter_var($domainName, \FILTER_VALIDATE_IP)
            || strcasecmp($domainName, 'localhost') === 0;
    }
}
