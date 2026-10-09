<?php

declare(strict_types=1);

namespace NeoPHP\Tests\Component\Http;

use NeoPHP\Component\Http\Exception\BadRequestHttpException;
use NeoPHP\Component\Http\Request\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([]);
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function testCreateFillsTheQueryAndTheServer(): void
    {
        $request = Request::create('https://example.com:8443/posts?page=2', 'GET', ['sort' => 'title']);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/posts', $request->getPath());
        self::assertSame('2', $request->query->get('page'));
        self::assertSame('title', $request->query->get('sort'));
        self::assertTrue($request->isSecure());
        self::assertSame(8443, $request->getPort());
        self::assertSame('https://example.com:8443', $request->getSchemeAndHttpHost());
    }

    public function testPostCanOverrideTheMethod(): void
    {
        self::assertSame('DELETE', Request::create('/posts/1', 'POST', ['_method' => 'delete'])->getMethod());
        self::assertSame('POST', Request::create('/posts/1', 'POST', ['_method' => 'trace'])->getMethod());
    }

    public function testPathWithoutTheBasePath(): void
    {
        $request = Request::create('/app/posts', 'GET', [], ['SCRIPT_NAME' => '/app/index.php']);

        self::assertSame('/app', $request->getBasePath());
        self::assertSame('/posts', $request->getPath());
    }

    #[DataProvider('ipRanges')]
    public function testIpMatches(string $ip, string $range, bool $expected): void
    {
        self::assertSame($expected, Request::ipMatches($ip, $range));
    }

    public static function ipRanges(): iterable
    {
        yield 'same ip' => ['127.0.0.1', '127.0.0.1', true];
        yield 'ipv4 in range' => ['10.1.2.3', '10.0.0.0/8', true];
        yield 'ipv4 out of range' => ['11.1.2.3', '10.0.0.0/8', false];
        yield 'partial byte mask' => ['172.20.0.1', '172.16.0.0/12', true];
        yield 'ipv6 in range' => ['fe80::1', 'fe80::/10', true];
        yield 'ipv4 against ipv6 range' => ['127.0.0.1', '::1/128', false];
        yield 'invalid ip' => ['not-an-ip', '10.0.0.0/8', false];
    }

    public function testForwardedHeadersAreIgnoredWithoutTrustedProxy(): void
    {
        $request = Request::create('http://example.com/', 'GET', [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertSame('10.0.0.1', $request->getClientIp());
        self::assertFalse($request->isSecure());
    }

    public function testForwardedHeadersOfATrustedProxy(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);
        $request = Request::create('http://example.com/', 'GET', [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertSame('203.0.113.7', $request->getClientIp());
        self::assertTrue($request->isSecure());
    }

    public function testUntrustedHostIsRefused(): void
    {
        Request::setTrustedHosts(['example.com', '*.example.com']);

        self::assertSame('api.example.com', Request::create('http://api.example.com/')->getHost());

        $this->expectException(BadRequestHttpException::class);
        Request::create('http://evil.test/')->getHost();
    }
}