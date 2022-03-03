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

final class DohServers
{
    public const SERVER_CLOUDFLARE_MOZILLA = 'https://mozilla.cloudflare-dns.com/dns-query';

    public const SERVER_CLOUDFLARE = 'https://cloudflare-dns.com/dns-query';

    public const SERVER_GOOGLE = 'https://dns.google/dns-query';

    public const SERVER_CLEANBROWSING_SECURITY = 'https://doh.cleanbrowsing.org/doh/security-filter';

    public const SERVER_CLEANBROWSING_FAMILY = 'https://doh.cleanbrowsing.org/doh/family-filter';

    public const SERVER_CLEANBROWSING_ADULT = 'https://doh.cleanbrowsing.org/doh/adult-filter';

    public const SERVER_ADGUARD = 'https://dns.adguard.com/dns-query';

    public const SERVER_ADGUARD_FAMILY = 'https://dns-family.adguard.com/dns-query';

    public const SERVER_OPENDNS = 'https://doh.opendns.com/dns-query';

    public const SERVER_OPENDNS_FAMILY = 'https://doh.familyshield.opendns.com/dns-query';

    public const DEFAULT_SERVERS = [
        self::SERVER_CLOUDFLARE_MOZILLA,
        self::SERVER_GOOGLE,
    ];
}
