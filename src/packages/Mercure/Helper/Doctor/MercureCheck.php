<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Helper\Doctor;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Package\Mercure\Provider\MercureProvider;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class MercureCheck implements CheckInterface
{
    public const MINIMUM_SECRET_LENGTH = 32;

    public const TIMEOUT = 3;

    public function __construct(protected ?ConfigManagerInterface $config = null, protected ?HttpClientManagerInterface $http = null)
    {
    }

    public function getName(): string
    {
        return 'Mercure';
    }

    public function run(): iterable
    {
        $config = $this->config !== null ? (array) ($this->config->get(MercureProvider::CONFIG_KEY, []) ?? []) : [];
        $url = (string) ($config['url'] ?? '');

        if ($url === '') {
            yield CheckResult::info('MERCURE_URL', 'empty: real-time updates disabled', 'Set MERCURE_URL to use a Mercure hub (see the Mercure documentation).');

            return;
        }

        yield CheckResult::ok('MERCURE_URL', $url);

        $jwt = (array) ($config['jwt'] ?? []);
        $secret = (string) ($jwt['secret'] ?? '');

        yield match (true) {
            (string) ($jwt['token'] ?? '') !== '' => CheckResult::ok('Publisher JWT', 'static token'),
            $secret === '' => CheckResult::error('MERCURE_JWT_SECRET', 'empty', 'Set MERCURE_JWT_SECRET to the publisher key of the hub.'),
            strlen($secret) < self::MINIMUM_SECRET_LENGTH => CheckResult::warning('MERCURE_JWT_SECRET', sprintf('%d characters', strlen($secret)), sprintf('Use at least %d random characters, the same as the hub.', self::MINIMUM_SECRET_LENGTH)),
            default => CheckResult::ok('MERCURE_JWT_SECRET', 'set'),
        };

        if ($this->http === null) {
            return;
        }

        try {
            $status = $this->http->get($url, ['timeout' => self::TIMEOUT])->getStatusCode();

            yield CheckResult::ok('Hub', sprintf('reachable (HTTP %d)', $status));
        } catch (Throwable $exception) {
            yield CheckResult::warning('Hub', 'unreachable: ' . $exception->getMessage(), 'Start the hub (FrankenPHP or the Mercure binary) and check MERCURE_URL: the updates fail until it answers.');
        }
    }
}