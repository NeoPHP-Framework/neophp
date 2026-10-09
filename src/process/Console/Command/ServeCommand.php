<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Command;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'serve', description: 'Starts the PHP development server')]
class ServeCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('host', null, InputOption::VALUE_REQUIRED, 'The host to listen on', '127.0.0.1');
        $input->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'The port to listen on', '8000');
        $this->addExample('serve');
        $this->addExample('serve --host=0.0.0.0 --port=8080');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');
        $publicPath = (string) $this->container->get('kernel.public_path');

        if (!is_file($publicPath . DIRECTORY_SEPARATOR . 'index.php')) {
            $output->error(sprintf('No front controller found in "%s". Run "php vendor/bin/neo install" first.', $publicPath));

            return self::FAILURE;
        }

        $output->success(sprintf('NeoPHP server running on http://%s:%s (Ctrl+C to stop)', $host, $port));

        passthru(sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host . ':' . $port),
            escapeshellarg($publicPath),
            escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'router.php'),
        ), $exitCode);

        return (int) $exitCode;
    }
}