<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Helper\Console;

use FilesystemIterator;
use NeoPHP\Component\Upload\UploadManagerInterface;
use NeoPHP\Package\Image\Exception\ImageException;
use NeoPHP\Package\Image\ImageManagerInterface;
use NeoPHP\Package\Image\Model\Image;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @internal
 */
#[AsCommand(name: 'image:warmup', description: 'Generates the derivatives of the uploaded images in advance')]
class ImageWarmupCommand extends AbstractConsole
{
    public function __construct(protected ImageManagerInterface $images, protected UploadManagerInterface $uploads)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('preset', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The presets to generate (all by default)');
        $input->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Only the images of this sub-directory of the uploads');
        $this->setHelp('Without it, a derivative is generated the first time a page displays it.');
        $this->addExample('image:warmup');
        $this->addExample('image:warmup thumbnail medium --directory=photos');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $presets = array_values(array_map('strval', (array) $input->getArgument('preset')));
        $presets = $presets === [] ? array_keys($this->images->getPresets()) : $presets;
        $directory = $input->getOption('directory') !== null ? $this->uploads->path((string) $input->getOption('directory')) : $this->uploads->getUploadPath();

        if ($presets === []) {
            $output->warning('No preset in config/packages/image.yaml.');

            return self::SUCCESS;
        }

        if (!is_dir($directory)) {
            $output->warning(sprintf('The directory %s does not exist.', $directory));

            return self::SUCCESS;
        }

        $generated = 0;
        $failed = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file->isFile() || Image::formatFromExtension($file->getExtension()) === null) {
                continue;
            }

            foreach ($presets as $preset) {
                try {
                    $this->images->thumbnail($file->getPathname(), $preset);
                    $generated++;
                } catch (ImageException $exception) {
                    $failed++;
                    $output->writeln(sprintf('  <error>failed</error>  %s (%s): %s', $file->getPathname(), $preset, $exception->getMessage()));
                }
            }
        }

        $output->success(sprintf('%d derivative(s) ready, %d failure(s).', $generated, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}