<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Console;

use NeoPHP\Package\Notifier\Model\DeliveryResult;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Model\Recipient;
use NeoPHP\Package\Notifier\NotifierManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'notifier:send', description: 'Sends a notification to a recipient, to check the channels')]
class NotifierSendCommand extends AbstractConsole
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('recipient', InputArgument::REQUIRED, 'The identifier of the recipient (the id of the user)', null, 'Identifier of the recipient');
        $input->addArgument('subject', InputArgument::REQUIRED, 'The subject of the notification', null, 'Subject');
        $input->addArgument('content', InputArgument::OPTIONAL, 'The content of the notification', '');
        $input->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address of the recipient (email channel)');
        $input->addOption('url', null, InputOption::VALUE_REQUIRED, 'Page opened by the notification');
        $input->addOption('importance', null, InputOption::VALUE_REQUIRED, 'low, normal, high or urgent', Notification::IMPORTANCE_NORMAL);
        $input->addOption('channel', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Channels to use instead of the channel policy');
        $this->addExample('notifier:send 12 "Hello" "A test notification" --importance=high');
        $this->addExample('notifier:send 12 "Hello" --channel=push --channel=email --email=me@example.com');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $notification = (new Notification((string) $input->getArgument('subject'), (string) $input->getArgument('content'), $input->getOption('url') !== null ? (string) $input->getOption('url') : null))
                ->importance((string) $input->getOption('importance'));
            $channels = array_values(array_map('strval', (array) $input->getOption('channel')));

            if ($channels !== []) {
                $notification->channels(...$channels);
            }

            $results = $this->notifier->send($notification, new Recipient((string) $input->getArgument('recipient'), $input->getOption('email') !== null ? (string) $input->getOption('email') : null));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->table(['Channel', 'Status', 'Message'], array_map(static fn (DeliveryResult $result): array => [$result->getChannel(), $result->getStatus(), (string) $result->getMessage()], $results));
        $failed = array_filter($results, static fn (DeliveryResult $result): bool => $result->isFailed());

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}