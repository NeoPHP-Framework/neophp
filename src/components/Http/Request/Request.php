<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Request;

use NeoPHP\Component\Http\Bag\FileBag;
use NeoPHP\Component\Http\Bag\HeaderBag;
use NeoPHP\Component\Http\Bag\ParameterBag;
use NeoPHP\Component\Http\Exception\BadRequestHttpException;

class Request
{
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'];

    public ParameterBag $query;

    public ParameterBag $request;

    public ParameterBag $attributes;

    public ParameterBag $cookies;

    public FileBag $files;

    public ParameterBag $server;

    public HeaderBag $headers;

    protected ?string $content;

    protected ?string $method = null;

    protected static array $trustedProxies = [];

    protected static array $trustedHostPatterns = [];

    public function __construct(
        array $query = [],
        array $request = [],
        array $attributes = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ) {
        $this->query = new ParameterBag($query);
        $this->request = new ParameterBag($request);
        $this->attributes = new ParameterBag($attributes);
        $this->cookies = new ParameterBag($cookies);
        $this->files = new FileBag($files);
        $this->server = new ParameterBag($server);
        $this->headers = HeaderBag::fromServer($server);
        $this->content = $content;
    }

    public static function fromGlobals(): static
    {
        $request = new static($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER);

        if ($request->isJson() && in_array($request->getRealMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $data = json_decode($request->getContent(), true);

            if (is_array($data)) {
                $request->request->replace($data);
            }
        } elseif (
            str_starts_with((string) $request->headers->get('Content-Type'), 'application/x-www-form-urlencoded')
            && in_array($request->getRealMethod(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            parse_str($request->getContent(), $data);
            $request->request->replace($data);
        }

        return $request;
    }

    public static function create(string $uri, string $method = 'GET', array $parameters = [], array $server = [], ?string $content = null): static
    {
        $parts = parse_url($uri) ?: [];
        $method = strtoupper($method);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '/';
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if (in_array($method, ['GET', 'HEAD'], true)) {
            $query = array_replace($query, $parameters);
            $parameters = [];
        }

        $queryString = http_build_query($query, '', '&');

        $server = array_replace([
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'HTTP_HOST' => $host . (in_array($port, [80, 443], true) ? '' : ':' . $port),
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path . ($queryString !== '' ? '?' . $queryString : ''),
            'QUERY_STRING' => $queryString,
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ], $server);

        return new static($query, $parameters, [], [], [], $server, $content);
    }

    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = array_values(array_filter(array_map(static fn (mixed $proxy): string => trim((string) $proxy), $proxies), static fn (string $proxy): bool => $proxy !== ''));
    }

    public static function getTrustedProxies(): array
    {
        return self::$trustedProxies;
    }

    public static function setTrustedHosts(array $hosts): void
    {
        self::$trustedHostPatterns = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9.\-*]+$/', $host) === 1) {
                $host = '^' . str_replace('\\*', '[a-z0-9-]+', preg_quote($host, '#')) . '$';
            }

            self::$trustedHostPatterns[] = '#' . str_replace('#', '\\#', $host) . '#i';
        }
    }

    public static function getTrustedHosts(): array
    {
        return self::$trustedHostPatterns;
    }

    public static function ipMatches(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            $binary = @inet_pton($ip);

            return $binary !== false && $binary === @inet_pton($range);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary) || !ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits > strlen($ipBinary) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($ipBinary, $subnetBinary, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }

    public function isFromTrustedProxy(): bool
    {
        $remote = (string) $this->server->get('REMOTE_ADDR', '');

        return $remote !== '' && $this->isTrustedProxy($remote);
    }

    public function getMethod(): string
    {
        if ($this->method !== null) {
            return $this->method;
        }

        $method = $this->getRealMethod();

        if ($method === 'POST') {
            $override = strtoupper((string) ($this->headers->get('X-HTTP-Method-Override') ?? $this->request->get('_method', '')));

            if ($override !== '' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return $this->method = $method;
    }

    public function getRealMethod(): string
    {
        return strtoupper((string) $this->server->get('REQUEST_METHOD', 'GET'));
    }

    public function setMethod(string $method): void
    {
        $this->method = null;
        $this->server->set('REQUEST_METHOD', strtoupper($method));
    }

    public function isMethod(string $method): bool
    {
        return $this->getMethod() === strtoupper($method);
    }

    public function getPath(): string
    {
        $uri = (string) $this->server->get('REQUEST_URI', '/');

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri) === 1) {
            $uri = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        }

        $path = explode('?', $uri, 2)[0];

        return $path === '' ? '/' : $path;
    }

    public function getQueryString(): ?string
    {
        $query = (string) $this->server->get('QUERY_STRING', '');

        return $query === '' ? null : $query;
    }

    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        if (($proto = $this->getForwardedValue('X-Forwarded-Proto')) !== null) {
            return in_array(strtolower($proto), ['https', 'on', 'ssl', '1'], true);
        }

        $https = strtolower((string) $this->server->get('HTTPS', ''));

        return ($https !== '' && $https !== 'off') || (int) $this->server->get('SERVER_PORT') === 443;
    }

    public function getHost(): string
    {
        $host = $this->getForwardedValue('X-Forwarded-Host') ?? (string) ($this->headers->get('Host') ?? $this->server->get('SERVER_NAME', 'localhost'));
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));

        if ($host !== '' && preg_match('/^\[?[a-z0-9\-._:\]]+$/', $host) !== 1) {
            throw new BadRequestHttpException('Invalid host "{host}".', ['host' => $host]);
        }

        if (self::$trustedHostPatterns !== []) {
            foreach (self::$trustedHostPatterns as $pattern) {
                if (preg_match($pattern, $host) === 1) {
                    return $host;
                }
            }

            throw new BadRequestHttpException('Untrusted host "{host}" (see trusted_hosts in config/framework/app.yaml).', ['host' => $host]);
        }

        return $host;
    }

    public function getPort(): int
    {
        if (($port = $this->getForwardedValue('X-Forwarded-Port')) !== null && ctype_digit($port)) {
            return (int) $port;
        }

        if (($forwardedHost = $this->getForwardedValue('X-Forwarded-Host')) !== null) {
            return preg_match('/:(\d+)$/', $forwardedHost, $m) === 1 ? (int) $m[1] : ($this->isSecure() ? 443 : 80);
        }

        if ($this->getForwardedValue('X-Forwarded-Proto') !== null) {
            return $this->isSecure() ? 443 : 80;
        }

        $host = (string) $this->headers->get('Host', '');

        if (preg_match('/:(\d+)$/', $host, $m) === 1) {
            return (int) $m[1];
        }

        return (int) $this->server->get('SERVER_PORT', $this->isSecure() ? 443 : 80);
    }

    public function getSchemeAndHttpHost(): string
    {
        $port = $this->getPort();
        $default = $this->isSecure() ? 443 : 80;

        return $this->getScheme() . '://' . $this->getHost() . ($port === $default ? '' : ':' . $port);
    }

    public function getUri(): string
    {
        $query = $this->getQueryString();

        return $this->getSchemeAndHttpHost() . $this->getPath() . ($query !== null ? '?' . $query : '');
    }

    public function getClientIp(): ?string
    {
        $ip = $this->server->get('REMOTE_ADDR');
        $ip = is_string($ip) && $ip !== '' ? $ip : null;

        if ($ip === null || !$this->isTrustedProxy($ip) || !$this->headers->has('X-Forwarded-For')) {
            return $ip;
        }

        $chain = array_values(array_filter(array_map('trim', explode(',', (string) $this->headers->get('X-Forwarded-For'))), static fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP) !== false));

        for ($index = count($chain) - 1; $index >= 0; --$index) {
            if (!$this->isTrustedProxy($chain[$index])) {
                return $chain[$index];
            }
        }

        return $chain[0] ?? $ip;
    }

    protected function isTrustedProxy(string $ip): bool
    {
        foreach (self::$trustedProxies as $proxy) {
            if ($proxy === 'REMOTE_ADDR' ? $ip === (string) $this->server->get('REMOTE_ADDR', '') : self::ipMatches($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    protected function getForwardedValue(string $header): ?string
    {
        if (self::$trustedProxies === [] || !$this->isFromTrustedProxy()) {
            return null;
        }

        $value = trim(explode(',', (string) $this->headers->get($header, ''))[0]);

        return $value === '' ? null : $value;
    }

    public function getContent(): string
    {
        if ($this->content === null) {
            $this->content = (string) file_get_contents('php://input');
        }

        return $this->content;
    }

    public function toArray(): array
    {
        $content = $this->getContent();

        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new BadRequestHttpException('The request body is not a valid JSON object: {error}', ['error' => json_last_error_msg()]);
        }

        return $data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->attributes->has($key)) {
            return $this->attributes->get($key);
        }

        if ($this->query->has($key)) {
            return $this->query->get($key);
        }

        return $this->request->get($key, $default);
    }

    public function getContentType(): ?string
    {
        $type = $this->headers->get('Content-Type');

        return $type === null ? null : strtolower(trim(explode(';', $type)[0]));
    }

    public function isJson(): bool
    {
        $type = (string) $this->getContentType();

        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    public function wantsJson(): bool
    {
        $accept = strtolower((string) $this->headers->get('Accept', ''));

        return (str_contains($accept, 'application/json') || str_contains($accept, '+json')) && !str_contains($accept, 'text/html');
    }

    public function isXmlHttpRequest(): bool
    {
        return $this->headers->get('X-Requested-With') === 'XMLHttpRequest';
    }
}