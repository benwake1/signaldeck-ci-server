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
     * Guard against SSRF: only allow http(s) URLs pointing at a public host.
     */
    public static function validate(string $url): void
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('Invalid URL provided');
        }

        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL must use http or https');
        }

        $host = strtolower($parsed['host']);

        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
        if (in_array($host, $blocked, true)) {
            throw new \InvalidArgumentException('Cannot use localhost or loopback addresses');
        }

        if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $host)) {
            throw new \InvalidArgumentException('Cannot use private network addresses');
        }
    }
}
