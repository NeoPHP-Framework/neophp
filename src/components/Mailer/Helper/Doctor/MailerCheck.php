<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Helper\Doctor;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Mailer\Transport\Dsn;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class MailerCheck implements CheckInterface
{
    public const CONFIG_KEY = 'framework.mailer.dsn';

    public const TIMEOUT = 3;

    public function __construct(
        protected ?ConfigManagerInterface $config = null,
        #[Autowire(param: 'kernel.environment')]
        protected string $environment = 'dev',
    ) {
    }

    public function getName(): string
    {
        return 'Mailer';
    }

    public function run(): iterable
    {
        try {
            $dsn = Dsn::fromString((string) ($this->config?->get(self::CONFIG_KEY, 'null://null') ?? 'null://null'));
        } catch (Throwable $exception) {
            yield CheckResult::error('MAILER_DSN', $exception->getMessage(), 'Use smtp://user:password@host:587, smtps://user:password@host:465, file://, log:// or null:// in MAILER_DSN.');

            return;
        }

        $scheme = $dsn->getScheme();

        if (in_array($scheme, ['null', 'file', 'log'], true)) {
            yield $this->environment === 'prod'
                ? CheckResult::warning('MAILER_DSN', $scheme . '://: the emails are not sent', 'Set MAILER_DSN to your SMTP server in .env.local (smtp://user:password@host:587).')
                : CheckResult::ok('MAILER_DSN', $scheme . '://');

            return;
        }

        $host = $dsn->getHost();

        if ($host === '') {
            yield CheckResult::error('MAILER_DSN', 'the SMTP server has no host', 'Use smtp://user:password@host:587 in MAILER_DSN.');

            return;
        }

        $tls = $scheme === 'smtps' || $dsn->getPort() === 465;
        $port = (int) $dsn->getPort($tls ? 465 : 25);

        if (!extension_loaded('openssl')) {
            yield $tls
                ? CheckResult::error('Extension openssl', 'missing', 'Install and enable the PHP extension openssl: smtps:// encrypts the connection with it.')
                : CheckResult::warning('Extension openssl', 'missing', 'Install and enable the PHP extension openssl: the connection cannot be encrypted with STARTTLS without it.');
        }

        $socket = @fsockopen($host, $port, $code, $error, self::TIMEOUT);

        if ($socket === false) {
            yield CheckResult::error('SMTP server', sprintf('%s:%d unreachable (%s)', $host, $port, $error !== '' ? $error : 'timeout'), 'Check MAILER_DSN, the firewall of the server and the port (587 or 25 for smtp://, 465 for smtps://).');

            return;
        }

        fclose($socket);

        yield CheckResult::ok('SMTP server', sprintf('%s:%d reachable', $host, $port));
    }
}