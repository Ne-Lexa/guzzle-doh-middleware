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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Messages\Message;
use LibDNS\Records\Record;
use LibDNS\Records\Resource;
use LibDNS\Records\ResourceTypes;
use Nelexa\Doh\Storage\StorageFactory;
use Nelexa\Doh\Storage\StorageInterface;
use Nelexa\Doh\Storage\StorageItem;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

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

    /** @var \Nelexa\Doh\Storage\StorageInterface */
    private $storage;

    /** @var string[] */
    private $dohServers;

    /** @var \Psr\Log\LoggerInterface|null */
    private $logger;

    /** @var callable */
    private $nextHandler;

    /** @var bool */
    private $debug;

    /**
     * @param callable                             $nextHandler
     * @param \Nelexa\Doh\Storage\StorageInterface $storage
     * @param string[]                             $dohServers
     * @param \Psr\Log\LoggerInterface|null        $logger
     * @param bool                                 $debug
     */
    private function __construct(
        callable $nextHandler,
        StorageInterface $storage,
        array $dohServers,
        ?LoggerInterface $logger = null,
        bool $debug = false
    ) {
        $this->nextHandler = $nextHandler;
        $this->storage = $storage;
        $this->dohServers = $dohServers;
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return mixed
     */
    public function __invoke(RequestInterface $request, array &$options)
    {
        $options += self::DEFAULT_OPTIONS;

        $handler = $this->nextHandler;
        $domainName = $request->getUri()->getHost();

        if (
            !\function_exists('curl_init')
            || $options[self::OPTION_DOH_ENABLED] === false
            || $this->isIpOrLocalDomainName($domainName)
        ) {
            return $handler($request, $options);
        }

        /** @psalm-suppress InvalidCatch */
        try {
            $resolvedItem = $this->resolveDomain($domainName, $options);

            if ($resolvedItem && $resolvedItem->count() > 0) {
                /** @var array<int, mixed> $curlOptions */
                $curlOptions = [
                    \CURLOPT_DNS_USE_GLOBAL_CACHE => false, // disable global cache
                ];

                $ipAddresses = $resolvedItem->getData();

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

                $ipAddressesString = implode(',', $ipAddresses);
                $curlOptions[\CURLOPT_RESOLVE] = [
                    $domainName . ':80:' . $ipAddressesString,
                    $domainName . ':443:' . $ipAddressesString,
                ];

                $port = $request->getUri()->getPort();

                if ($port !== null && $port !== 80 && $port !== 443) {
                    $curlOptions[\CURLOPT_RESOLVE][] = $domainName . ':' . $port . ':' . $ipAddressesString;
                }

                if ($this->logger !== null) {
                    $this->logger->debug(
                        sprintf('DoH client adds resolving for %s', $domainName),
                        [
                            'domain' => $domainName,
                            'ipAddresses' => $ipAddresses,
                        ]
                    );
                }

                $options['curl'] = $curlOptions;

                /** @var \GuzzleHttp\Promise\PromiseInterface $promise */
                $promise = $handler($request, $options);

                return $promise->then(
                    static function (ResponseInterface $response) use ($ipAddressesString, $resolvedItem) {
                        if ($resolvedItem->cacheHit) {
                            $cacheTtl = max(0, $resolvedItem->getExpiredAt()->getTimestamp() - time());
                            $response = $response->withHeader(self::HEADER_RESPONSE_CACHE_TTL, (string) $cacheTtl);
                        }

                        return $response
                            ->withHeader(self::HEADER_RESPONSE_CACHE_HIT, var_export($resolvedItem->cacheHit, true))
                            ->withHeader(self::HEADER_RESPONSE_RESOLVED_IPS, $ipAddressesString)
                        ;
                    }
                );
            }

            if ($this->logger !== null) {
                $this->logger->warning(sprintf('DoH client could not resolve ip addresses for %s domain', $domainName));
            }
        } catch (GuzzleException $e) {
            if ($this->logger !== null) {
                $this->logger->error(
                    sprintf('Error DoH request for %s', $domainName),
                    [
                        'domain' => $domainName,
                        'exception' => $e,
                    ]
                );
            }
        }

        return $handler($request, $options);
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
        if (empty($dohServers)) {
            $dohServers = DohServers::DEFAULT_SERVERS;
        }

        $storage = StorageFactory::create($cache);

        return static function (callable $handler) use ($storage, $dohServers, $logger, $debug) {
            return new self($handler, $storage, $dohServers, $logger, $debug);
        };
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

    /**
     * @param string $domainName
     * @param array  $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return \Nelexa\Doh\Storage\StorageItem|null
     */
    private function resolveDomain(string $domainName, array $options): ?StorageItem
    {
        $storageItem = $this->storage->get($domainName);

        if ($storageItem !== null) {
            // resolve cname record
            if ($storageItem->isCnameRecord()) {
                $cnameDomains = $storageItem->getData();
                $storageItem = null;

                foreach ($cnameDomains as $cnameDomain) {
                    if ($cnameDomain !== $domainName) { // infinite loop protection
                        $storageItem = $this->resolveDomain($cnameDomain, $options);

                        if ($storageItem !== null) {
                            break;
                        }
                    }
                }
            }

            if ($storageItem !== null) {
                $storageItem->cacheHit = true;

                return $storageItem;
            }
        }

        $dnsMessage = $this->doDnsRequest($domainName);

        $indexedAnswerEntries = [];

        /** @var Record $answerRecord */
        foreach ($dnsMessage->getAnswerRecords() as $answerRecord) {
            if ($answerRecord instanceof Resource) {
                $answerDomainName = (string) $answerRecord->getName()->getValue();
                $resourceType = $answerRecord->getType();

                if (
                        $resourceType === ResourceTypes::A
                        || $resourceType === ResourceTypes::AAAA
                        || $resourceType === ResourceTypes::CNAME
                ) {
                    /** @var \LibDNS\Records\Types\IPv4Address|\LibDNS\Records\Types\IPv6Address $dataField */
                    foreach ($answerRecord->getData() as $dataField) {
                        $indexedAnswerEntries[$answerDomainName][$resourceType]['data'][] = (string) $dataField;
                    }

                    $indexedAnswerEntries[$answerDomainName][$resourceType]['ttl'][] = $answerRecord->getTTL();
                }
            }
        }

        /** @var \DateInterval|int|string|null $defaultTtl */
        $defaultTtl = $options[self::OPTION_DOH_TTL] ?? null;
        $storages = [];
        foreach ($indexedAnswerEntries as $answerDomainName => $answerEntry) {
            foreach ($answerEntry as $resourceType => $entryValue) {
                $ttl = $defaultTtl ?? max(10, min($entryValue['ttl'] ?? [0]));
                $storageItem = new StorageItem($answerDomainName, $entryValue['data'] ?? [], $resourceType, $ttl);
                $storages[$answerDomainName] = $storageItem;
                $this->storage->save($answerDomainName, $storageItem);
            }
        }

        $storageItem = $storages[$domainName] ?? null;

        while ($storageItem !== null && $storageItem->isCnameRecord()) {
            foreach ($storageItem->getData() as $cnameDomain) {
                if ($cnameDomain !== $domainName) { // infinite loop protection
                    $storageItem = $storages[$cnameDomain] ?? null;
                }
            }
        }

        return $storageItem;
    }

    /**
     * @param string $domainName
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return \LibDNS\Messages\Message
     */
    private function doDnsRequest(string $domainName): Message
    {
        $dnsQuery = self::encodeRequest(self::generateDnsQuery($domainName));
        $serverUrl = $this->dohServers[array_rand($this->dohServers)];

        $requestOptions = [
            RequestOptions::HEADERS => [
                'Accept' => 'application/dns-udpwireformat, application/dns-message',
                'User-Agent' => 'DoH-Client',
            ],
            RequestOptions::CONNECT_TIMEOUT => 10.0,
            RequestOptions::TIMEOUT => 10.0,
            RequestOptions::VERSION => '2.0',
            RequestOptions::DEBUG => $this->debug,
            RequestOptions::QUERY => [
                'dns' => $dnsQuery,
            ],
        ];

        if (\defined('CURLOPT_IPRESOLVE') && \defined('CURL_IPRESOLVE_V4')) {
            $requestOptions['curl'][\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
        }

        $rawContents = (new Client())
            ->request('GET', $serverUrl, $requestOptions)
            ->getBody()
            ->getContents()
        ;

        return (new DecoderFactory())->create()->decode($rawContents);
    }

    private static function generateDnsQuery(string $domainName): string
    {
        $encodedDomainName = implode('', array_map(static function (string $domainBit) {
            return \chr(\strlen($domainBit)) . $domainBit;
        }, explode('.', $domainName)));

        return "\xab\xcd"
            . \chr(1) . \chr(0)
            . \chr(0) . \chr(1)  // qdc
            . \chr(0) . \chr(0)  // anc
            . \chr(0) . \chr(0)  // nsc
            . \chr(0) . \chr(0)  // arc
            . $encodedDomainName . \chr(0) // domain name
            . \chr(0) . \chr(ResourceTypes::A) // resource type
            . \chr(0) . \chr(1);  // qclass
    }

    private static function encodeRequest(string $request): string
    {
        return str_replace('=', '', strtr(base64_encode($request), '+/', '-_'));
    }
}
