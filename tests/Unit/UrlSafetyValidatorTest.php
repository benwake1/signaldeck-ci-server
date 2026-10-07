<?php

namespace Tests\Unit;

use App\Support\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlSafetyValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        UrlSafetyValidator::$resolver = fn (string $host) => match ($host) {
            'example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
            'evil.test' => ['93.184.215.14', '10.0.0.5'],
            'metadata.test' => ['169.254.169.254'],
            default => [],
        };
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::$resolver = null;
    }

    public static function blocked(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com'],
            'no host' => ['not a url'],
            'localhost' => ['http://localhost:8000'],
            'sub.localhost' => ['http://app.localhost'],
            'loopback' => ['http://127.0.0.1'],
            'loopback other' => ['http://127.0.0.2'],
            'zero' => ['http://0.0.0.0'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'metadata ip' => ['http://169.254.169.254/latest/meta-data'],
            'private 10' => ['http://10.1.2.3'],
            'private 172' => ['http://172.20.0.1'],
            'private 192' => ['http://192.168.1.1'],
            'cgnat' => ['http://100.64.1.1'],
            'ipv6 ula' => ['http://[fd00::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
            'decimal ip' => ['http://2130706433/'],
            'short ip' => ['http://127.1/'],
            'hex ip' => ['http://0x7f000001/'],
            'dns to private' => ['http://evil.test'],
            'dns to metadata' => ['http://metadata.test'],
            'unresolvable' => ['http://nope.invalid'],
        ];
    }

    #[DataProvider('blocked')]
    public function test_blocks_unsafe_urls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UrlSafetyValidator::validate($url);
    }

    public static function allowed(): array
    {
        return [
            'public host' => ['https://example.com/path'],
            'public ip' => ['http://93.184.215.14'],
            'public ipv6' => ['http://[2606:4700:4700::1111]/'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_allows_public_urls(string $url): void
    {
        UrlSafetyValidator::validate($url);
        $this->addToAssertionCount(1);
    }
}
