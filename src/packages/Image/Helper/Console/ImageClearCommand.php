<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Helper\Console;

use NeoPHP\Package\Image\Exception\ImageException;
use NeoPHP\Package\Image\ImageManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'image:clear', description: 'Deletes the cached derivatives of the image presets')]
class ImageClearCommand extends AbstractConsole
{
    public function __construct(protected ImageManagerInterface $images)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('preset', InputArgument::OPTIONAL, 'Only the derivatives of this preset');
        $this->setHelp('Run it after a change of a preset in config/packages/image.yaml: the derivatives are generated again on the next display.');
        $this->addExample('image:clear');
        $this->addExample('image:clear thumbnail');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $preset = $input->getArgument('preset');

        try {
            $deleted = $this->images->clear($preset !== null ? (string) $preset : null);
        } catch (ImageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('%d derivative(s) deleted from %s.', $deleted, $this->images->getCachePath()));

        return self::SUCCESS;
    }
}