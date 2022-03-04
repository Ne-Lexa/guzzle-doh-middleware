<div align="center">
    <img src="logo.svg" alt="nelexa/guzzle-doh-middleware" />
    <h1>guzzle-doh-middleware</h1>
    <strong>A DNS over HTTPS (DoH) middleware for <a href="https://github.com/guzzle/guzzle" target="_blank">Guzzle</a>.</strong>
</div>

[![Latest Stable Version](https://poser.pugx.org/nelexa/guzzle-doh-middleware/v)](https://packagist.org/packages/nelexa/guzzle-doh-middleware)
[![PHP Version Require](https://poser.pugx.org/nelexa/guzzle-doh-middleware/require/php)](https://packagist.org/packages/nelexa/guzzle-doh-middleware)
[![Tests](https://github.com/Ne-Lexa/guzzle-doh-middleware/actions/workflows/tests.yml/badge.svg)](https://github.com/Ne-Lexa/guzzle-doh-middleware/actions/workflows/tests.yml)
[![Analysis](https://github.com/Ne-Lexa/guzzle-doh-middleware/actions/workflows/analysis.yml/badge.svg)](https://github.com/Ne-Lexa/guzzle-doh-middleware/actions/workflows/analysis.yml)
[![Build Status](https://scrutinizer-ci.com/g/Ne-Lexa/guzzle-doh-middleware/badges/build.png?b=main)](https://scrutinizer-ci.com/g/Ne-Lexa/guzzle-doh-middleware/build-status/main)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Ne-Lexa/guzzle-doh-middleware/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/Ne-Lexa/guzzle-doh-middleware/?branch=main)
[![Code Coverage](https://scrutinizer-ci.com/g/Ne-Lexa/guzzle-doh-middleware/badges/coverage.png?b=main)](https://scrutinizer-ci.com/g/Ne-Lexa/guzzle-doh-middleware/?branch=main)
[![License](https://poser.pugx.org/nelexa/guzzle-doh-middleware/license)](https://packagist.org/packages/nelexa/guzzle-doh-middleware)

## Goals
- Resolving domains, via DoH before sending HTTP requests.
- Bypassing blocked sites, through DNS packet spoofing.
- Support for caching DNS responses, via <a href="https://packagist.org/providers/psr/cache-implementation" target="_blank">PSR-6</a> and <a href="https://packagist.org/providers/psr/simple-cache-implementation" target="_blank">PSR-16</a> compatible packages.
- Support for multiple DoH providers.

## Install
```bash
composer require nelexa/guzzle-doh-middleware
```

## Usage
```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Nelexa\Doh\DohMiddleware;

// Create default HandlerStack
$stack = HandlerStack::create();

// Add this middleware to the top with `push`
$stack->push(DohMiddleware::create(), 'doh');

// Initialize the client with the handler option
$client = new Client(['handler' => $stack]);
```

### Setup cache
It is very important to configure caching of DNS requests, so that you do not have to contact a DNS server to resolve domains for every HTTP request.

Install a <a href="https://packagist.org/providers/psr/cache-implementation" target="_blank">PSR-6</a> or <a href="https://packagist.org/providers/psr/simple-cache-implementation" target="_blank">PSR-16</a> compatible caching package.

For example, you can install the popular <a href="https://symfony.com/doc/current/components/cache.html#cache-component-psr6-caching" target="_blank">symfony/cache</a> package.
```bash
composer require symfony/cache
```
Example init PSR-6 redis cache
```php
$cache = new \Symfony\Component\Cache\Adapter\RedisAdapter(
    \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection()
);
```
Example init PSR-16 redis cache
```php
$cache = new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter(
        \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection()
    )
);
```

You can pass the configured cache as the first argument when creating middleware.  
If you don't pass the argument or pass `null`, the cache will only be stored for the lifetime of the PHP process.
```php
$stack->push(DohMiddleware::create($cache), 'doh');
```

### Setup DoH Servers
You can specify which DoH servers to use as a second argument when creating middleware. They will be chosen in random order.

The defaults are <a href="https://developers.cloudflare.com/1.1.1.1/encrypted-dns/dns-over-https/encrypted-dns-browsers/" target="_blank">Cloudflare (for Mozilla)</a> and <a href="https://developers.google.com/speed/public-dns/docs/doh">Google</a>.

Example:
```php
$dohServers = [
    'https://mozilla.cloudflare-dns.com/dns-query',
    'https://dns.google/dns-query',
    'https://doh.cleanbrowsing.org/doh/security-filter',
    \Nelexa\Doh\DohServers::SERVER_ADGUARD_FAMILY,
    'https://doh.opendns.com/dns-query',
];
$stack->push(DohMiddleware::create($cache, $dohServers), 'doh');
```

### Setup Logger & Debug
You can add the <a href="https://packagist.org/providers/psr/log-implementation" target="_blank">PSR-3 compatible Logger</a> as a 3rd argument when creating middleware.

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('doh');
$logger->pushHandler(new StreamHandler('path/to/doh.log', Logger::DEBUG));

$stack->push(DohMiddleware::create(
    cache: $cache,
    logger: $logger
), 'doh');
```

For debugging and console output of all DoH requests to servers, you can pass `true` as 4th parameter when creating middleware.
```php
$stack->push(DohMiddleware::create(
    cache: $cache,
    debug: true,
), 'doh');
```
<details>
  <summary>Example debug info</summary>
  
```text
*   Trying 104.16.248.249:443...
* TCP_NODELAY set
* connect to 104.16.248.249 port 443 failed: Connection refused
*   Trying 104.16.249.249:443...
* TCP_NODELAY set
* Connected to mozilla.cloudflare-dns.com (104.16.249.249) port 443 (#0)
* ALPN, offering http/1.1
* successfully set certificate verify locations:
*   CAfile: /etc/ssl/certs/ca-certificates.crt
  CApath: /etc/ssl/certs
* SSL connection using TLSv1.3 / TLS_AES_256_GCM_SHA384
* ALPN, server accepted to use http/1.1
* Server certificate:
*  subject: C=US; ST=California; L=San Francisco; O=Cloudflare, Inc.; CN=cloudflare-dns.com
*  start date: Oct 25 00:00:00 2021 GMT
*  expire date: Oct 25 23:59:59 2022 GMT
*  subjectAltName: host "mozilla.cloudflare-dns.com" matched cert's "*.cloudflare-dns.com"
*  issuer: C=US; O=DigiCert Inc; CN=DigiCert TLS Hybrid ECC SHA384 2020 CA1
*  SSL certificate verify ok.
> GET /dns-query?dns=q80BAAABAAAAAAAACmdvb2dsZW1haWwBbAZnb29nbGUDY29tAAABAAE HTTP/1.1
Host: mozilla.cloudflare-dns.com
Accept: application/dns-udpwireformat, application/dns-message
User-Agent: DoH-Client

* old SSL session ID is stale, removing
* Mark bundle as not supporting multiuse
< HTTP/1.1 200 OK
< Server: cloudflare
< Date: Thu, 03 Mar 2022 09:58:15 GMT
< Content-Type: application/dns-message
< Connection: keep-alive
< Access-Control-Allow-Origin: *
< Content-Length: 105
< CF-RAY: 6e6183398ec716f0-DME
< 
* Connection #0 to host mozilla.cloudflare-dns.com left intact
```

</details>

## Request options
You can configure requests created and transmitted by the client using request options.

### Option "doh"

<dl>
    <dt>Summary</dt>
    <dd><p>Set <code class="notranslate">false</code> to disable domain resolving, via DoH.</p></dd>
    <dt>Types</dt>
    <dd>
        <ul>
            <li>bool</li>
        </ul>
    </dd>
    <dt>Default</dt>
    <dd><p><code class="notranslate">true</code></p></dd>
    <dt>Constant</dt>
    <dd><p><code class="notranslate">\Nelexa\Doh\DohMiddleware::OPTION_DOH_ENABLED</code></p></dd>
</dl>

```php
// Disable DoH for concrete request
$client->request('GET', 'https://...', [
    'doh' => false,
]);
```

To disable DoH middleware by default, pass `false` for the `doh` option when creating the HTTP client.

```php
$stack = HandlerStack::create();
$stack->push(DohMiddleware::create($cache), 'doh');
$client = new Client([
    'handler' => $stack,
    'doh' => false,
]);
```

### Option "doh_ttl"

<dl>
    <dt>Summary</dt>
    <dd><p>Forced setting of caching time for resolving results. If the option is not passed or <code class="notranslate">null</code> is passed, the caching time from the DNS server is used.</p></dd>
    <dt>Types</dt>
    <dd>
        <ul>
            <li>integer</li>
            <li>\DateInterval</li>
            <li>null</li>
        </ul>
    </dd>
    <dt>Default</dt>
    <dd><p><code class="notranslate">null</code></p></dd>
    <dt>Constant</dt>
    <dd><p><code class="notranslate">\Nelexa\Doh\DohMiddleware::OPTION_DOH_TTL</code></p></dd>
</dl>

```php
$client->request('GET', 'https://...', [
    'doh_ttl' => \DateInterval::createFromDateString('1 hour'),
]);
```

### Option "doh_shuffle"

<dl>
    <dt>Summary</dt>
    <dd><p>Set <code class="notranslate">true</code> to enable <a href="https://curl.se/libcurl/c/CURLOPT_DNS_SHUFFLE_ADDRESSES.html" target="_blank">shuffling of ip addresses</a> in random order when more than one ip address has been received as a result of domain resolving.</p></dd>
    <dt>Types</dt>
    <dd>
        <ul>
            <li>bool</li>
        </ul>
    </dd>
    <dt>Default</dt>
    <dd><p><code class="notranslate">false</code></p></dd>
    <dt>Constant</dt>
    <dd><p><code class="notranslate">\Nelexa\Doh\DohMiddleware::OPTION_DOH_SHUFFLE</code></p></dd>
</dl>

```php
// Enable shuffle ip addresses
$client->request('GET', 'https://...', [
    'doh_shuffle' => true,
]);
```

To enable ip mixing for all requests by default, pass `true` for the `ttl_shuffle` option when creating the HTTP client.

```php
$stack = HandlerStack::create();
$stack->push(DohMiddleware::create($cache), 'doh');
$client = new Client([
    'handler' => $stack,
    'doh_shuffle' => false,
]);
```

## Symfony config DI
```yaml
# config/services.yaml
parameters:
    
    doh.servers:
        - 'https://mozilla.cloudflare-dns.com/dns-query',
        - 'https://dns.google/dns-query',
        - 'https://doh.opendns.com/dns-query'

services:

    app.client.doh_middleware:
        factory: Nelexa\Doh\DohMiddleware::create
        class: Nelexa\Doh\DohMiddleware
        arguments:
            - '@cache.app'
            - '%doh.servers%'
            - '@logger'
            - '%kernel.debug%'

    app.client.handler_stack:
        factory: GuzzleHttp\HandlerStack::create
        class: GuzzleHttp\HandlerStack
        calls:
            - [ push, [ '@app.client.doh_middleware' ] ]

    app.client:
        class: GuzzleHttp\Client
        arguments:
            app.client:
            class: GuzzleHttp\Client
            arguments:
                - handler: '@app.client.handler_stack'
                  doh: true
                  # doh_ttl: 3600
                  # doh_shuffle: true

    # Aliases
    GuzzleHttp\Client: '@app.client'
```

## Credits
* [Ne-Lexa](https://github.com/Ne-Lexa)
* [All contributors](https://github.com/Ne-Lexa/guzzle-doh-middleware/graphs/contributors)

## Changelog
Changes are documented in the [releases page](https://github.com/Ne-Lexa/guzzle-doh-middleware/releases).

## License
The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
