<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Helper\Doctor;

use NeoPHP\Component\Kernel\Helper\Doctor\ApplicationCheck;
use NeoPHP\Component\Upload\UploadManagerInterface;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;

/**
 * @internal
 */
class UploadCheck implements CheckInterface
{
    public function __construct(protected UploadManagerInterface $uploads)
    {
    }

    public function getName(): string
    {
        return 'Uploads';
    }

    public function run(): iterable
    {
        yield ApplicationCheck::writable('Directory', $this->uploads->getUploadPath());

        yield extension_loaded('fileinfo')
            ? CheckResult::ok('Extension fileinfo', 'loaded')
            : CheckResult::error('Extension fileinfo', 'missing', 'Install and enable the PHP extension fileinfo: the type of the uploaded files cannot be detected without it.');

        yield filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)
            ? CheckResult::ok('PHP limits', sprintf('upload_max_filesize %s, post_max_size %s', ini_get('upload_max_filesize'), ini_get('post_max_size')))
            : CheckResult::error('PHP limits', 'file_uploads is disabled', 'Set file_uploads=On in php.ini.');
    }
}