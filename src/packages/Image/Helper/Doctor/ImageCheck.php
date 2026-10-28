<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Helper\Doctor;

use NeoPHP\Component\Kernel\Helper\Doctor\ApplicationCheck;
use NeoPHP\Package\Image\ImageManagerInterface;
use NeoPHP\Package\Image\Model\Image;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;

/**
 * @internal
 */
class ImageCheck implements CheckInterface
{
    public function __construct(protected ImageManagerInterface $images)
    {
    }

    public function getName(): string
    {
        return 'Images';
    }

    public function run(): iterable
    {
        if (!extension_loaded('gd')) {
            yield CheckResult::error('Extension gd', 'missing', 'Install and enable the PHP extension gd (e.g. apt install php-gd): the thumbnails cannot be generated without it.');

            return;
        }

        yield CheckResult::ok('Extension gd', (string) gd_info()['GD Version']);

        $formats = [];

        foreach ($this->images->getPresets() as $name => $preset) {
            if ($preset['format'] !== null && $preset['format'] !== '') {
                $formats[strtolower($preset['format'])][] = $name;
            }
        }

        foreach ($formats as $format => $presets) {
            yield Image::supports($format)
                ? CheckResult::ok('Format ' . $format, 'supported')
                : CheckResult::error('Format ' . $format, sprintf('not supported by GD (presets %s)', implode(', ', $presets)), sprintf('Install a GD built with %s, or change the format of these presets in config/packages/image.yaml.', $format));
        }

        if (!extension_loaded('exif')) {
            yield CheckResult::info('Extension exif', 'missing', 'Recommended: the orientation of the photos is read faster with it.');
        }

        yield ApplicationCheck::writable('Cache', $this->images->getCachePath());
    }
}