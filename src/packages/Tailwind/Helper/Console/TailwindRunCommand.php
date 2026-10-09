<?php

declare(strict_types=1);

namespace NeoPHP\Package\Tailwind\Helper\Console;

use NeoPHP\Component\Asset\AssetManagerInterface;
use NeoPHP\Package\Tailwind\Exception\TailwindException;
use NeoPHP\Package\Tailwind\TailwindManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'tailwind:run', description: 'Compiles the Tailwind CSS file and publishes it with the assets')]
class TailwindRunCommand extends AbstractConsole
{
    public function __construct(protected TailwindManagerInterface $tailwind, protected AssetManagerInterface $asset)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('input', InputArgument::OPTIONAL, 'The Tailwind CSS file, relative to assets/ (default: "input" of config/packages/tailwind.yaml)');
        $input->addOption('watch', 'w', InputOption::VALUE_NONE, 'Recompile on every change of the templates or the CSS (stop with Ctrl+C)');
        $input->addOption('minify', 'm', InputOption::VALUE_NONE, 'Minify the CSS');
        $this->setHelp(implode("\n", [
            'Tailwind compiles assets/{input} into var/tailwind/{input}; the asset system publishes it as public/builds/{input}-{hash}.css.',
            'In templates, asset(\'css/app.css\') always gives the URL of the compiled file. With --watch, asset() picks the new file on each request in debug.',
        ]));
        $this->addExample('tailwind:run');
        $this->addExample('tailwind:run --watch');
        $this->addExample('tailwind:run --minify');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($input->isArgumentProvided('input') || $this->tailwind->getInput() !== null) {
            return;
        }

        $input->setArgument('input', $output->ask('Tailwind CSS file in assets/', TailwindManagerInterface::DEFAULT_INPUT, static function (mixed $value): string {
            $value = trim((string) $value);

            if (!str_ends_with(strtolower($value), '.css')) {
                throw new InvalidInputException('Give a .css file relative to assets/ (e.g. css/app.css).');
            }

            return $value;
        }));
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $watch = (bool) $input->getOption('watch');
        $minify = (bool) $input->getOption('minify');

        try {
            $argument = $input->getArgument('input');
            $save = $this->tailwind->getInput() === null;

            if (is_string($argument) && $argument !== '') {
                $this->tailwind->setInput($argument);
            }

            $source = $this->tailwind->getInput() ?? TailwindManagerInterface::DEFAULT_INPUT;

            if ($save) {
                $this->tailwind->setInput($source);
                $output->writeln(sprintf('  <success>saved</success>  %s', $this->tailwind->saveConfig()));
            }

            $this->asset->setSourceFile($source, $this->tailwind->getOutputFile($source));
            $output->text(sprintf('%s -> %s%s', $this->tailwind->getSourceFile($source), $this->tailwind->getOutputFile($source), $minify ? ' <muted>(minified)</muted>' : ''));

            if ($watch) {
                $output->text(sprintf('Watching the templates: <info>asset(\'%s\')</info> serves the new CSS on each request in debug. Stop with Ctrl+C.', $source));
            }

            $output->newLine();
            $code = $this->tailwind->run($source, $watch, $minify);

            if ($code !== 0) {
                $output->error(sprintf('Tailwind stopped with the exit code %d.', $code));

                return self::FAILURE;
            }

            $url = $this->asset->compile($source);
        } catch (TailwindException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('%s compiled: %s', $source, $url));

        return self::SUCCESS;
    }
}