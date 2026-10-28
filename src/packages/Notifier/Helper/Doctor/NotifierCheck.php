<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Doctor;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Package\Notifier\Helper\Console\NotifierServiceWorkerCommand;
use NeoPHP\Package\Notifier\Provider\NotifierProvider;
use NeoPHP\Package\Notifier\WebPush\Vapid;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class NotifierCheck implements CheckInterface
{
    public function __construct(
        protected ?ConfigManagerInterface $config = null,
        #[Autowire(param: 'kernel.public_path')]
        protected string $publicPath = '',
    ) {
    }

    public function getName(): string
    {
        return 'Notifications';
    }

    public function run(): iterable
    {
        $config = $this->config !== null ? (array) ($this->config->get(NotifierProvider::CONFIG_KEY, []) ?? []) : [];
        $push = (array) ($config['push'] ?? []);
        $public = (string) ($push['public_key'] ?? '');
        $private = (string) ($push['private_key'] ?? '');

        if ($public === '' && $private === '') {
            yield CheckResult::info('Push', 'disabled: no VAPID keys', 'Run php bin/neo notifier:vapid and copy the keys into .env.local to send push notifications.');

            return;
        }

        if ($public === '' || $private === '') {
            yield CheckResult::error('VAPID keys', sprintf('%s is empty', $public === '' ? 'VAPID_PUBLIC_KEY' : 'VAPID_PRIVATE_KEY'), 'Copy both keys printed by php bin/neo notifier:vapid.');

            return;
        }

        if (!extension_loaded('openssl')) {
            yield CheckResult::error('Extension openssl', 'missing', 'Install and enable the PHP extension openssl: the push notifications are encrypted and signed with it.');

            return;
        }

        try {
            (new Vapid($public, $private, (string) ($push['subject'] ?? '')))->authorization('https://push.example.com/check');

            yield CheckResult::ok('VAPID keys', 'valid');
        } catch (Throwable $exception) {
            yield CheckResult::error('VAPID keys', $exception->getMessage(), 'Generate a new pair with php bin/neo notifier:vapid (the browsers will have to subscribe again).');
        }

        if ($this->publicPath !== '') {
            yield is_file($this->publicPath . DIRECTORY_SEPARATOR . NotifierServiceWorkerCommand::TARGET)
                ? CheckResult::ok('Service worker', 'public/' . NotifierServiceWorkerCommand::TARGET)
                : CheckResult::warning('Service worker', 'public/' . NotifierServiceWorkerCommand::TARGET . ' is missing', 'Run php bin/neo notifier:service-worker: the browsers cannot display the push notifications without it.');
        }
    }
}