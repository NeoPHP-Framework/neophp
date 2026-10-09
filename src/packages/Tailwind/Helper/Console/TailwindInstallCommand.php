<?php

declare(strict_types=1);

namespace NeoPHP\Package\Tailwind\Helper\Console;

use NeoPHP\Package\Tailwind\Exception\TailwindException;
use NeoPHP\Package\Tailwind\TailwindManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'tailwind:install', description: 'Downloads the Tailwind CSS standalone CLI and prepares the Tailwind CSS file in assets/')]
class TailwindInstallCommand extends AbstractConsole
{
    public function __construct(protected TailwindManagerInterface $tailwind)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('input', InputArgument::OPTIONAL, 'The Tailwind CSS file, relative to assets/', $this->tailwind->getInput() ?? TailwindManagerInterface::DEFAULT_INPUT, 'Tailwind CSS file in assets/');
        $input->addOption('tailwind-version', null, InputOption::VALUE_REQUIRED, 'The Tailwind version to download (e.g. 4.1.13, "latest" by default)');
        $this->setHelp(implode("\n", [
            'The binary is downloaded from GitHub into var/tailwind/ (no Node.js needed); --force downloads it again.',
            'The CSS file is created with @import "tailwindcss"; (or the import is added to an existing file) and saved in config/packages/tailwind.yaml.',
        ]));
        $this->addExample('tailwind:install');
        $this->addExample('tailwind:install css/app.css --tailwind-version=4.1.13');
        $this->addExample('tailwind:install --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->tailwind->setInput((string) $input->getArgument('input'));
            $version = $input->getOption('tailwind-version');
            $version = is_string($version) && $version !== '' ? $version : null;

            $output->text(sprintf('Platform: <info>%s</info>', $this->tailwind->getPlatform()));
            $output->text(sprintf('Download: <muted>%s</muted>', $this->tailwind->getDownloadUrl($version)));

            [$binary, $installed, $downloaded] = $this->tailwind->install($version, (bool) $input->getOption('force'));
            $source = (string) $this->tailwind->getInput();
            $status = $this->tailwind->initSource($source);
            $config = $this->tailwind->saveConfig();
        } catch (TailwindException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->newLine();
        $output->writeln(sprintf('  <success>%s</success>  %s%s', $downloaded ? 'downloaded' : 'kept', $binary, $installed !== null ? ' <muted>(v' . $installed . ')</muted>' : ''));
        $output->writeln(sprintf('  <success>%s</success>  %s', $status, $this->tailwind->getSourceFile($source)));
        $output->writeln(sprintf('  <success>saved</success>  %s', $config));
        $output->success('Tailwind is installed.');
        $output->text([
            'Compile the CSS: <info>php bin/neo tailwind:run</info> (<info>--watch</info> while developing, <info>--minify</info> for production)',
            sprintf('Use it in a template: <info>{{ asset(\'%1$s\') }}</info> or <info><?= $this->asset(\'%1$s\') ?></info>', $source),
        ]);

        return self::SUCCESS;
    }
}