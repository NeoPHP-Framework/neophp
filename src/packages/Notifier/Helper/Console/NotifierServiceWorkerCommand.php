<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Console;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'notifier:service-worker', description: 'Writes the service worker that displays the push notifications into public/')]
class NotifierServiceWorkerCommand extends AbstractConsole
{
    public const SOURCE = __DIR__ . '/../../Resources/service-worker.js';

    public const TARGET = 'notifier-sw.js';

    public function __construct(
        #[Autowire(param: 'kernel.public_path')]
        protected ?string $publicPath = null
    ) {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp('The service worker must be served from the root of the site to receive the notifications of every page.');
        $this->addExample('notifier:service-worker');
        $this->addExample('notifier:service-worker --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $target = rtrim($this->publicPath ?? (getcwd() . '/public'), '/\\') . DIRECTORY_SEPARATOR . self::TARGET;

        if (is_file($target) && !(bool) $input->getOption('force')) {
            $output->warning(sprintf('%s already exists: use --force to overwrite it.', $target));

            return self::SUCCESS;
        }

        if (@copy(self::SOURCE, $target) === false) {
            $output->error(sprintf('%s cannot be written.', $target));

            return self::FAILURE;
        }

        $output->success(sprintf('%s written: register it with navigator.serviceWorker.register(\'/%s\').', $target, self::TARGET));

        return self::SUCCESS;
    }
}