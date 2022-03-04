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
use GuzzleHttp\RequestOptions;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Messages\Message;
use LibDNS\Records\Record;
use LibDNS\Records\Resource;
use LibDNS\Records\ResourceTypes;
use Nelexa\Doh\Storage\DnsRecord;
use Nelexa\Doh\Storage\StorageInterface;

final class DomainResolver
{
    /** @var \GuzzleHttp\Client */
    private $client;

    /** @var \Nelexa\Doh\Storage\StorageInterface */
    private $storage;

    /** @var string[] */
    private $dohServers;

    /**
     * @param \Nelexa\Doh\Storage\StorageInterface $storage
     * @param string[]                             $dohServers
     * @param bool                                 $debug
     */
    public function __construct(StorageInterface $storage, array $dohServers, bool $debug)
    {
        $this->storage = $storage;
        $this->dohServers = empty($dohServers) ? DohServers::DEFAULT_SERVERS : $dohServers;

        $curlOptions = [];

        if (\defined('CURLOPT_IPRESOLVE') && \defined('CURL_IPRESOLVE_V4')) {
            $curlOptions[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
        }

        $this->client = new Client([
            RequestOptions::HEADERS => [
                'Accept' => 'application/dns-udpwireformat, application/dns-message',
                'User-Agent' => 'DoH-Client',
            ],
            RequestOptions::CONNECT_TIMEOUT => 10.0,
            RequestOptions::TIMEOUT => 10.0,
            RequestOptions::VERSION => '2.0',
            RequestOptions::DEBUG => $debug,
            'curl' => $curlOptions,
        ]);
    }

    /**
     * @param string $domainName
     * @param array  $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return \Nelexa\Doh\Storage\DnsRecord|null
     *
     * @psalm-suppress InvalidThrow
     */
    public function resolveDomain(string $domainName, array $options): ?DnsRecord
    {
        $dnsRecord = $this->findDnsRecordInStorage($domainName, $options);

        if ($dnsRecord === null) {
            $dnsMessage = $this->doDnsRequest($domainName);
            $dnsRecords = $this->indexingDnsRecords($dnsMessage);
            /** @var \DateInterval|int|string|null $defaultTtl */
            $defaultTtl = $options[DohMiddleware::OPTION_DOH_TTL] ?? null;
            $savedRecords = $this->saveDnsRecords($dnsRecords, $defaultTtl);
            $dnsRecord = $savedRecords[$domainName] ?? null;

            while ($dnsRecord !== null && $dnsRecord->isCnameRecord()) {
                foreach ($dnsRecord->getData() as $cnameDomain) {
                    if ($cnameDomain !== $domainName) { // infinite loop protection
                        $dnsRecord = $savedRecords[$cnameDomain] ?? null;
                    }
                }
            }
        }

        return $dnsRecord;
    }

    /**
     * @param string $domainName
     * @param array  $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @psalm-suppress InvalidThrow
     *
     * @return \Nelexa\Doh\Storage\DnsRecord|null
     */
    private function findDnsRecordInStorage(string $domainName, array $options): ?DnsRecord
    {
        $dnsRecord = $this->storage->get($domainName);

        if ($dnsRecord !== null) {
            // resolve cname record
            if ($dnsRecord->isCnameRecord()) {
                $cnameDomains = $dnsRecord->getData();
                $dnsRecord = null;

                foreach ($cnameDomains as $cnameDomain) {
                    if ($cnameDomain !== $domainName) { // infinite loop protection
                        $dnsRecord = $this->resolveDomain($cnameDomain, $options);

                        if ($dnsRecord !== null) {
                            break;
                        }
                    }
                }
            }

            if ($dnsRecord !== null) {
                $dnsRecord->cacheHit = true;
            }
        }

        return $dnsRecord;
    }

    /**
     * @param \LibDNS\Messages\Message $dnsMessage
     *
     * @return array<string, array<int, array{data?: list<string>, ttl?: list<int>}>>
     */
    private function indexingDnsRecords(Message $dnsMessage): array
    {
        $entries = [];

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
                        $entries[$answerDomainName][$resourceType]['data'][] = (string) $dataField;
                    }

                    $entries[$answerDomainName][$resourceType]['ttl'][] = $answerRecord->getTTL();
                }
            }
        }

        return $entries;
    }

    /**
     * @param string $domainName
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @psalm-suppress InvalidThrow
     *
     * @return \LibDNS\Messages\Message
     */
    private function doDnsRequest(string $domainName): Message
    {
        $dnsQuery = self::encodeRequest(self::generateDnsQuery($domainName));
        $serverUrl = $this->dohServers[array_rand($this->dohServers)];

        $rawContents = $this->client
            ->request('GET', $serverUrl, [
                RequestOptions::QUERY => [
                    'dns' => $dnsQuery,
                ],
            ])
            ->getBody()
            ->getContents()
        ;

        return (new DecoderFactory())->create()->decode($rawContents);
    }

    private static function generateDnsQuery(string $domainName): string
    {
        $encodedDomainName = implode(
            '',
            array_map(static function (string $domainBit) {
                return \chr(\strlen($domainBit)) . $domainBit;
            }, explode('.', $domainName))
        );

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

    /**
     * @param array<string, array<int, array{data?: list<string>, ttl?: list<int>}>> $entries
     * @param \DateInterval|int|string|null                                          $defaultTtl
     *
     * @return array<string, DnsRecord>
     */
    private function saveDnsRecords(array $entries, $defaultTtl): array
    {
        $storages = [];
        foreach ($entries as $answerDomainName => $answerEntry) {
            foreach ($answerEntry as $resourceType => $entryValue) {
                $ttl = $defaultTtl ?? max(10, min(...($entryValue['ttl'] ?? [])));
                $dnsRecord = new DnsRecord($answerDomainName, $entryValue['data'] ?? [], $resourceType, $ttl);
                $storages[$answerDomainName] = $dnsRecord;
                $this->storage->save($answerDomainName, $dnsRecord);
            }
        }

        return $storages;
    }
}
