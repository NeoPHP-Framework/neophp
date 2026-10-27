<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Console;

use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\WebPush\Keys;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'notifier:vapid', description: 'Generates the VAPID keys of the push notifications')]
class NotifierVapidCommand extends AbstractConsole
{
    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp(implode("\n", [
            'Copy the two lines into .env.local (the private key must never be committed).',
            'Generate them once: new keys invalidate every push subscription of the browsers.',
        ]));
        $this->addExample('notifier:vapid');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            [, $public, $private] = Keys::generate();
        } catch (NotifierException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln('VAPID_PUBLIC_KEY=' . Keys::encode($public));
        $output->writeln('VAPID_PRIVATE_KEY=' . Keys::encode($private));

        return self::SUCCESS;
    }
}