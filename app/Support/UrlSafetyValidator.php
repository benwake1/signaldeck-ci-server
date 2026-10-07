<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Support;

class UrlSafetyValidator
{
    /**
     * Overridable for tests: fn (string $host): string[] returning resolved IPs.
     *
     * @var (\Closure(string): array<int, string>)|null
     */
    public static ?\Closure $resolver = null;

    /**
     * Guard against SSRF: only allow http(s) URLs whose host resolves exclusively
     * to public addresses (no loopback, private, link-local/metadata or reserved).
     */
    public static function validate(string $url): void
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('Invalid URL provided');
        }

        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL must use http or https');
        }

        $host = rtrim(strtolower(trim($parsed['host'], '[]')), '.');

        if (in_array($host, self::allowedPrivateHosts(), true)) {
            return;
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \InvalidArgumentException('Cannot use localhost or loopback addresses');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } elseif (preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+)){0,3}$/i', $host)) {
            // Decimal/hex/octal/short IPv4 forms (2130706433, 0x7f.1, 127.1) that
            // HTTP clients happily interpret but aren't canonical dotted quads.
            throw new \InvalidArgumentException('Cannot use non-standard IP address formats');
        } else {
            $ips = self::resolve($host);
            if ($ips === []) {
                throw new \InvalidArgumentException('Could not resolve host');
            }
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \InvalidArgumentException('Cannot use private, loopback or reserved network addresses');
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // IPv4-mapped / IPv4-compatible IPv6 (::ffff:127.0.0.1, ::127.0.0.1) — check the embedded v4.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10))) {
            $tail = substr($packed, 10, 2);
            if ($tail === "\xff\xff" || $tail === "\0\0") {
                $v4 = inet_ntop(substr($packed, 12));
                if ($tail === "\xff\xff" || $v4 !== '0.0.0.1') {
                    return self::isPublicIp($v4);
                }
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT (100.64.0.0/10) isn't covered by PHP's flags.
        if (strlen($packed) === 4) {
            $long = ip2long($ip);
            if (($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private static function allowedPrivateHosts(): array
    {
        if (!function_exists('app') || !app()->bound('config')) {
            return [];
        }

        return array_map('strtolower', (array) config('ai.allowed_private_hosts', []));
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
