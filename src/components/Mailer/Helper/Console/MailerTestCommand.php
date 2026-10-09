<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Helper\Console;

use NeoPHP\Component\Mailer\Exception\MailerException;
use NeoPHP\Component\Mailer\Exception\TransportException;
use NeoPHP\Component\Mailer\MailerManager;
use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Component\Mailer\Mime\Email;
use NeoPHP\Component\Mailer\Transport\Dsn;
use NeoPHP\Component\Mailer\Transport\TransportFactory;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'mailer:test', description: 'Sends a test email to check the mailer configuration')]
class MailerTestCommand extends AbstractConsole
{
    public function __construct(protected MailerManagerInterface $mailer, protected TransportFactory $factory)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('to', InputArgument::REQUIRED, 'The recipient', null, 'Recipient email address');
        $input->addOption('from', null, InputOption::VALUE_REQUIRED, 'The sender (default: "from" of config/framework/mailer.yaml)');
        $input->addOption('subject', 's', InputOption::VALUE_REQUIRED, 'The subject', 'NeoPHP test email');
        $input->addOption('body', 'b', InputOption::VALUE_REQUIRED, 'The text body', 'This is a test email sent by the NeoPHP mailer:test command.');
        $input->addOption('dsn', 'd', InputOption::VALUE_REQUIRED, 'Use this DSN instead of MAILER_DSN');
        $this->setHelp('With -v, the dialogue with the SMTP server is displayed (passwords are hidden).');
        $this->addExample('mailer:test me@example.com');
        $this->addExample('mailer:test me@example.com --from=noreply@example.com -v');
        $this->addExample('mailer:test me@example.com --dsn="smtp://user:pass@smtp.example.com:587"');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $dsn = $input->getOption('dsn');

        try {
            $mailer = is_string($dsn) && $dsn !== '' ? new MailerManager($this->factory->create($dsn), null, $this->mailer->getConfig()) : $this->mailer;
            $email = (new Email())
                ->to((string) $input->getArgument('to'))
                ->subject((string) $input->getOption('subject'))
                ->text((string) $input->getOption('body'))
                ->html('<p>' . htmlspecialchars((string) $input->getOption('body'), ENT_QUOTES) . '</p><p><small>' . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES) . '</small></p>');
            $from = $input->getOption('from');

            if (is_string($from) && $from !== '') {
                $email->from($from);
            }
        } catch (MailerException $exception) {
            $output->error($exception->getMessage());

            return self::INVALID;
        }

        $output->text(sprintf('Transport: <info>%s</info>', Dsn::mask((string) $mailer->getTransport())));

        try {
            $message = $mailer->send($email);
        } catch (TransportException $exception) {
            $this->debug($exception->getDebug(), $output);
            $output->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($message === null) {
            $output->warning('The email was rejected by a listener of MessageEvent.');

            return self::FAILURE;
        }

        $this->debug($message->getDebug(), $output);
        $output->definitionList([
            'Message-ID' => $message->getMessageId(),
            'Envelope' => $message->getEnvelope()->getSender()->getAddress() . ' -> ' . implode(', ', array_map('strval', $message->getEnvelope()->getRecipients())),
            'Transport id' => (string) ($message->getTransportId() ?? '-'),
        ]);
        $output->success('Test email sent.');

        return self::SUCCESS;
    }

    protected function debug(string $debug, OutputInterface $output): void
    {
        if ($debug === '' || !$output->isVerbose()) {
            return;
        }

        $output->section('Transport log');

        foreach (explode("\n", rtrim($debug)) as $line) {
            $output->writeln('  <muted>' . Formatter::escape($line) . '</muted>', OutputInterface::VERBOSITY_VERBOSE);
        }

        $output->newLine();
    }
}