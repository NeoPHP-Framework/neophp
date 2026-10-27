<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Response;

use DateTimeInterface;
use NeoPHP\Component\Http\Bag\HeaderBag;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;

class Response
{
    public const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    public HeaderBag $headers;

    protected string $content = '';

    protected int $statusCode = 200;

    protected string $protocolVersion = '1.1';

    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->headers = new HeaderBag($headers);
        $this->setContent($content);
        $this->setStatusCode($status);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): static
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new HttpException(500, 'Invalid HTTP status code {status}.', [], ['status' => $statusCode]);
        }

        $this->statusCode = $statusCode;

        return $this;
    }

    public function getReasonPhrase(): string
    {
        return self::PHRASES[$this->statusCode] ?? '';
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function setProtocolVersion(string $version): static
    {
        $this->protocolVersion = $version;

        return $this;
    }

    public function setHeader(string $name, string|array $values, bool $replace = true): static
    {
        $this->headers->set($name, $values, $replace);

        return $this;
    }

    public function setCookie(
        string $name,
        string $value = '',
        int|DateTimeInterface $expires = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): static {
        $cookie = rawurlencode($name) . '=' . rawurlencode($value);
        $timestamp = $expires instanceof DateTimeInterface ? $expires->getTimestamp() : $expires;

        if ($timestamp !== 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
            $cookie .= '; Max-Age=' . max(0, $timestamp - time());
        }

        $cookie .= '; Path=' . $path;

        if ($domain !== null) {
            $cookie .= '; Domain=' . $domain;
        }

        if ($secure) {
            $cookie .= '; Secure';
        }

        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }

        if ($sameSite !== '') {
            $cookie .= '; SameSite=' . $sameSite;
        }

        $this->headers->set('Set-Cookie', $cookie, false);

        return $this;
    }

    public function clearCookie(string $name, string $path = '/', ?string $domain = null): static
    {
        return $this->setCookie($name, '', 1, $path, $domain);
    }

    public function isInformational(): bool
    {
        return $this->statusCode >= 100 && $this->statusCode < 200;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirection(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    public function isEmpty(): bool
    {
        return in_array($this->statusCode, [204, 304], true) || $this->isInformational();
    }

    public function prepare(Request $request): static
    {
        if ($this->isEmpty()) {
            $this->content = '';
            $this->headers->remove('Content-Type');
            $this->headers->remove('Content-Length');

            return $this;
        }

        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($request->isMethod('HEAD')) {
            $this->headers->set('Content-Length', (string) strlen($this->content));
            $this->content = '';
        }

        return $this;
    }

    public function send(): static
    {
        $this->sendHeaders();
        $this->sendContent();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        return $this;
    }

    public function sendHeaders(): static
    {
        if (headers_sent()) {
            return $this;
        }

        foreach ($this->headers->all() as $name => $values) {
            $replace = $name !== 'set-cookie';

            foreach ($values as $value) {
                header($this->formatHeaderName($name) . ': ' . $value, $replace, $this->statusCode);
                $replace = false;
            }
        }

        header(sprintf('HTTP/%s %d %s', $this->protocolVersion, $this->statusCode, $this->getReasonPhrase()), true, $this->statusCode);

        return $this;
    }

    public function sendContent(): static
    {
        echo $this->content;

        return $this;
    }

    public function __toString(): string
    {
        $head = sprintf('HTTP/%s %d %s', $this->protocolVersion, $this->statusCode, $this->getReasonPhrase()) . "\r\n";

        foreach ($this->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $head .= $this->formatHeaderName($name) . ': ' . $value . "\r\n";
            }
        }

        return $head . "\r\n" . $this->content;
    }

    protected function formatHeaderName(string $name): string
    {
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
    }
}