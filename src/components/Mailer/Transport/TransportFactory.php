<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Transport;

use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Mailer\Contract\TransportInterface;
use NeoPHP\Component\Mailer\Exception\MailerException;

class TransportFactory
{
    public const SCHEMES = ['smtp', 'smtps', 'file', 'log', 'null'];

    public const DEFAULT_DIRECTORY = 'var/mails';

    public function __construct(protected string $rootPath = '', protected ?LoggerInterface $logger = null)
    {
    }

    public function create(Dsn|string $dsn): TransportInterface
    {
        $dsn = $dsn instanceof Dsn ? $dsn : Dsn::fromString($dsn);

        return match ($dsn->getScheme()) {
            'smtp', 'smtps' => $this->smtp($dsn),
            'file' => new FileTransport($this->directory($dsn)),
            'log' => $this->log($dsn),
            'null' => new NullTransport(),
            default => throw new MailerException('The mailer scheme "{scheme}" is not supported: use {schemes}.', 0, null, ['scheme' => $dsn->getScheme(), 'schemes' => implode(', ', self::SCHEMES)]),
        };
    }

    protected function smtp(Dsn $dsn): SmtpTransport
    {
        if ($dsn->getHost() === '') {
            throw new MailerException('The SMTP DSN "{dsn}" has no host.', 0, null, ['dsn' => (string) $dsn]);
        }

        $tls = $dsn->getScheme() === 'smtps' || $dsn->getPort() === 465;
        $options = array_intersect_key($dsn->getOptions(), SmtpTransport::DEFAULT_OPTIONS);

        return new SmtpTransport($dsn->getHost(), $dsn->getPort($tls ? 465 : 25) ?? 25, $tls, $dsn->getUser(), $dsn->getPassword(), $options);
    }

    protected function directory(Dsn $dsn): string
    {
        if ($dsn->getHost() === 'default' || ($dsn->getHost() === '' && $dsn->getPath() === '')) {
            return rtrim($this->rootPath !== '' ? $this->rootPath : (string) getcwd(), '/\\') . '/' . self::DEFAULT_DIRECTORY;
        }

        if ($dsn->getHost() !== '') {
            return rtrim($this->rootPath !== '' ? $this->rootPath : (string) getcwd(), '/\\') . '/' . $dsn->getHost() . $dsn->getPath();
        }

        return $dsn->getPath();
    }

    protected function log(Dsn $dsn): LogTransport
    {
        if ($this->logger === null) {
            throw new MailerException('The "log" mailer transport requires the Logger component.');
        }

        return new LogTransport($this->logger, $dsn->getHost() !== '' && $dsn->getHost() !== 'default' ? $dsn->getHost() : 'info');
    }
}