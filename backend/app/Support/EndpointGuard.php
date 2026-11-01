<?php

namespace App\Support;

use App\Exceptions\ShippingQuoteException;

/**
 * SSRF policy for Agen-supplied routing endpoints (KMSIT/OSRM).
 *
 * Rejects: non-http(s) schemes, embedded credentials, loopback, link-local (incl. cloud metadata 169.254.0.0/16),
 * private/reserved ranges and any host that resolves to one of them — checked on EVERY resolved address.
 * A private KMSIT server is only reachable when its exact host is in services.kmsit.trusted_hosts (an operator-
 * controlled env allow-list; an Agen cannot add to it). Plain http is allowed only for such a trusted host.
 * The caller pins the vetted IP for the actual request (see pin()) so DNS cannot be re-answered between the check and the call.
 */
class EndpointGuard
{
    /** @var (callable(string): array<int,string>)|null Test seam for DNS. */
    public static $resolver = null;

    /** @return array{url:string,host:string,port:int,ip:string} */
    public static function vet(string $url): array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new ShippingQuoteException('Endpoint tidak valid.');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ShippingQuoteException('Endpoint harus http(s).');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ShippingQuoteException('Endpoint tidak boleh memuat kredensial.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ShippingQuoteException('Endpoint tidak boleh memuat query atau fragment.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $trusted = in_array($host, self::trustedHosts(), true);
        if (! $trusted && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            throw new ShippingQuoteException('Endpoint mengarah ke alamat jaringan yang tidak diizinkan.');
        }
        if ($scheme === 'http' && ! $trusted) {
            throw new ShippingQuoteException('Endpoint http hanya diizinkan untuk host tepercaya.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new ShippingQuoteException('Port endpoint tidak valid.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new ShippingQuoteException('Host endpoint tidak dapat di-resolve.');
        }
        foreach ($ips as $ip) {
            // Public-only unless the operator explicitly trusts this exact host.
            if (! $trusted && ! self::isPublic($ip)) {
                throw new ShippingQuoteException('Endpoint mengarah ke alamat jaringan yang tidak diizinkan.');
            }
            if (self::isAlwaysBlocked($ip)) {
                throw new ShippingQuoteException('Endpoint mengarah ke alamat jaringan yang tidak diizinkan.');
            }
        }

        return ['url' => $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '', '/'),
            'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** Guzzle options that pin the vetted IP and disable redirects (defeats DNS rebinding and redirect-to-internal). */
    public static function pin(array $vetted): array
    {
        // proxy '' = never use an ambient HTTP(S)_PROXY: a proxy would resolve the host itself and bypass the vetted-IP pin below.
        // TLS verification stays at Guzzle's default (on); it is deliberately not touched here.
        $opts = ['allow_redirects' => false, 'proxy' => ''];
        $curl = [];
        if (defined('CURLOPT_NOPROXY')) {
            $curl[CURLOPT_NOPROXY] = '*';
        }
        if (defined('CURLOPT_RESOLVE') && ! filter_var($vetted['host'], FILTER_VALIDATE_IP)) {
            $curl[CURLOPT_RESOLVE] = ["{$vetted['host']}:{$vetted['port']}:{$vetted['ip']}"];
        }
        if ($curl !== []) {
            $opts['curl'] = $curl;
        }

        return $opts;
    }

    /** @return array<int,string> */
    private static function trustedHosts(): array
    {
        return array_values(array_filter(array_map(
            fn ($h) => strtolower(trim((string) $h)),
            (array) config('services.kmsit.trusted_hosts', [])
        )));
    }

    /** @return array<int,string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }

    private static function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Never reachable even for a trusted host: cloud metadata + link-local + unspecified. */
    private static function isAlwaysBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        if (strlen($packed) === 4) {
            $o = array_values(unpack('C4', $packed));

            return $o[0] === 169 && $o[1] === 254 || $o[0] === 0;
        }
        $hex = bin2hex($packed);

        return str_starts_with($hex, 'fe80') || $hex === str_repeat('0', 32)
            || (str_starts_with($hex, '00000000000000000000ffff') && self::isAlwaysBlocked(inet_ntop(substr($packed, 12))));
    }
}
