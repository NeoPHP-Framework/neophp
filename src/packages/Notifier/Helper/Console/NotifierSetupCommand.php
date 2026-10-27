<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Console;

use NeoPHP\Package\Notifier\NotifierManagerInterface;
use NeoPHP\Package\Notifier\Storage\NotificationStore;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'notifier:setup', description: 'Creates the tables of the notifications and of the push subscriptions')]
class NotifierSetupCommand extends AbstractConsole
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp('Only needed when database.auto_setup is false in config/packages/notifier.yaml.');
        $this->addExample('notifier:setup');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->notifier->setup();
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('The tables %s and %s are ready.', NotificationStore::TABLE, NotificationStore::SUBSCRIPTIONS_TABLE));

        return self::SUCCESS;
    }
}