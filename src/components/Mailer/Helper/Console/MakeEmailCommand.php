<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Mailer\Exception\MailerException;
use NeoPHP\Component\Mailer\Maker\EmailMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'make:email', description: 'Generates an email class in src/Email/')]
class MakeEmailCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::REQUIRED, 'The email name (the "Email" suffix is added)', null, 'Name of the email class (e.g. Welcome, Order/Shipped)');
        $this->addExample('make:email Welcome');
        $this->addExample('make:email Order/Shipped --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) $this->container->get('kernel.root_path');
        $maker = new EmailMaker($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Email');

        try {
            [$class, $file] = $maker->make((string) $input->getArgument('name'), (bool) $input->getOption('force'));
        } catch (MailerException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->success(sprintf('Email %s created.', $class));
        $output->text(sprintf('Send it from a controller: <info>$this->sendEmail(new %s(\'alice@example.com\', \'Alice\'));</info>', $short));

        return self::SUCCESS;
    }
}