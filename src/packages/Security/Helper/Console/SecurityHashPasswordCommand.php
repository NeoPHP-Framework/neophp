<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Console;

use NeoPHP\Package\Security\Hasher\UserPasswordHasher;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\InputArgument;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'security:hash-password', description: 'Hashes a password with the configured hasher')]
class SecurityHashPasswordCommand extends AbstractConsole
{
    public function __construct(protected UserPasswordHasher $hasher)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('password', InputArgument::OPTIONAL, 'The plain password (asked without echo when omitted)');
        $input->addArgument('user-class', InputArgument::OPTIONAL, 'The user class whose hasher is used', UserPasswordHasher::DEFAULT_KEY);
        $this->setHelp('Omit the password to type it without echo: it then stays out of the shell history.');
        $this->addExample('security:hash-password');
        $this->addExample('security:hash-password secret');
        $this->addExample('security:hash-password secret "App\\Entity\\User"');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $password = (string) ($input->getArgument('password') ?? '');

        if ($password === '') {
            if (!$output->isInteractive()) {
                throw new InvalidInputException('Not enough arguments (missing: "password").');
            }

            $password = (string) $output->secret('Password to hash', static function (mixed $value): string {
                if (!is_string($value) || $value === '') {
                    throw new InvalidInputException('The password cannot be empty.');
                }

                return $value;
            });
        }

        $class = (string) $input->getArgument('user-class');

        try {
            $hash = $this->hasher->getPasswordHasher($class)->hash($password);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->table(['Key', 'Value'], [
            ['Hasher', $class],
            ['Password hash', $hash],
        ]);

        return self::SUCCESS;
    }
}