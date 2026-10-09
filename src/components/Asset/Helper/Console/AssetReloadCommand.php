<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Helper\Console;

use NeoPHP\Component\Asset\AssetManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'asset:reload', description: 'Compiles assets/ into public/builds/ and rebuilds the manifest')]
class AssetReloadCommand extends AbstractConsole
{
    public function __construct(protected AssetManagerInterface $asset)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('minify', 'm', InputOption::VALUE_NONE, 'Minify the CSS and JS files');
        $this->addExample('asset:reload');
        $this->addExample('asset:reload --minify');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $minify = (bool) $input->getOption('minify');

        $output->title('Compiling assets');
        $output->text(sprintf('%s -> %s%s', $this->asset->getSourcePath(), $this->asset->getBuildPath(), $minify ? ' <muted>(minified)</muted>' : ''));
        $output->newLine();

        $built = $this->asset->reload($minify);

        if ($built === []) {
            $output->note('No asset found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($built as $path => $url) {
            $rows[] = [$path, $url];
        }

        $output->table(['Asset', 'Build'], $rows);
        $output->success(sprintf('%d asset(s) compiled. Manifest: %s', count($built), $this->asset->getManifest()->getFile()));

        return self::SUCCESS;
    }
}