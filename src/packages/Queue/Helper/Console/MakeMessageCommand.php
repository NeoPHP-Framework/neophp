<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\Exception\QueueException;
use NeoPHP\Package\Queue\Maker\MessageMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'make:message', description: 'Generates a message in src/Message/ and its handler in src/MessageHandler/')]
class MakeMessageCommand extends AbstractConsole
{
    public function __construct(protected MessageMaker $maker)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::REQUIRED, 'The message name', null, 'Name of the message class (e.g. SendNewsletter, Order/Ship)');
        $this->addExample('make:message SendNewsletter');
        $this->addExample('make:message Order/Ship --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            [$message, $messageFile, , $handlerFile] = $this->maker->make((string) $input->getArgument('name'), (bool) $input->getOption('force'));
        } catch (QueueException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $short = substr($message, (int) strrpos($message, '\\') + 1);
        $output->writeln(sprintf('  <success>created</success>  %s', $messageFile));
        $output->writeln(sprintf('  <success>created</success>  %s', $handlerFile));
        $output->success(sprintf('Message %s created.', $message));
        $output->text(sprintf('Dispatch it from a controller: <info>$this->dispatchMessage(new %s(42));</info>', $short));
        $output->text('Consume it: <info>php bin/neo queue:work</info>');

        return self::SUCCESS;
    }
}