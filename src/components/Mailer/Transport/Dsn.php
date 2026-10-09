<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Transport;

use NeoPHP\Component\Mailer\Exception\MailerException;
use Stringable;

class Dsn implements Stringable
{
    public function __construct(
        protected string $scheme,
        protected string $host = '',
        protected ?int $port = null,
        protected string $user = '',
        protected string $password = '',
        protected string $path = '',
        protected array $options = [],
    ) {
    }

    public static function fromString(string $dsn): self
    {
        $dsn = trim($dsn);

        if (preg_match('#^([a-z][a-z0-9+.-]*)://(.*)$#is', $dsn, $matches) !== 1) {
            throw new MailerException('The mailer DSN is not valid: expected "scheme://[user:password@]host[:port][?options]" (smtp, smtps, file, log, null).');
        }

        $scheme = strtolower($matches[1]);
        $rest = $matches[2];
        $split = strcspn($rest, '/?');
        $authority = substr($rest, 0, $split);
        $rest = substr($rest, $split);
        $query = '';

        if (str_contains($rest, '?')) {
            [$rest, $query] = explode('?', $rest, 2);
        }

        $user = '';
        $password = '';
        $at = strrpos($authority, '@');

        if ($at !== false) {
            $credentials = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
            [$user, $password] = str_contains($credentials, ':') ? explode(':', $credentials, 2) : [$credentials, ''];
            $user = rawurldecode($user);
            $password = rawurldecode($password);
        }

        $host = $authority;
        $port = null;

        if (preg_match('/^(.*):(\d+)$/', $authority, $parts) === 1) {
            $host = $parts[1];
            $port = (int) $parts[2];
        }

        parse_str($query, $options);

        return new self($scheme, rawurldecode($host), $port, $user, $password, rawurldecode($rest), $options);
    }

    public static function mask(string $dsn): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $dsn) !== 1) {
            return '****';
        }

        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://[^:@/?]*):.*@#is', '$1:****@', $dsn);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(?int $default = null): ?int
    {
        return $this->port ?? $default;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function __toString(): string
    {
        return $this->scheme . '://' . ($this->user !== '' ? rawurlencode($this->user) . ($this->password !== '' ? ':****' : '') . '@' : '') . $this->host . ($this->port !== null ? ':' . $this->port : '') . $this->path;
    }
}