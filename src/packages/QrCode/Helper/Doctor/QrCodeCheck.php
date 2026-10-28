<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\Doctor;

use NeoPHP\Package\QrCode\Exception\QrCodeException;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class QrCodeCheck implements CheckInterface
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    public function getName(): string
    {
        return 'QR codes';
    }

    public function run(): iterable
    {
        try {
            $code = $this->qrCodes->code('neo-doctor-check', 60);
            $result = $this->qrCodes->read($code);

            yield $result->isValid() && $result->getContent() === 'neo-doctor-check'
                ? CheckResult::ok('Short codes', sprintf('%d characters, kept in the cache', strlen(str_replace('-', '', $code))))
                : CheckResult::error('Short codes', 'not found after being registered', 'The cache pool forgets the entries at once: check cache_pool in config/packages/qr_code.yaml (the array adapter only lives during one request).');
        } catch (QrCodeException $exception) {
            yield CheckResult::error('Short codes', 'unavailable', $exception->getMessage());
        } catch (Throwable $exception) {
            yield CheckResult::error('Short codes', 'unavailable', $exception->getMessage());
        }

        try {
            $signed = $this->qrCodes->signed(['check' => true], null, 60);
            yield $this->qrCodes->read($signed->getContent())->isValid()
                ? CheckResult::ok('Signed QR codes', 'signed and verified with APP_SECRET')
                : CheckResult::error('Signed QR codes', 'verification failed', 'APP_SECRET changes between the requests: define it once in .env.local.');
        } catch (Throwable $exception) {
            yield CheckResult::error('Signed QR codes', 'unavailable', $exception->getMessage());
        }

        yield extension_loaded('gd')
            ? CheckResult::ok('PNG images', 'GD available')
            : CheckResult::info('PNG images', 'GD missing', 'The SVG QR codes work without it; install the PHP extension gd for the PNG format.');

        if ($this->qrCodes->getConfig()['reader']['route'] ?? false) {
            yield CheckResult::info('Read route', sprintf('POST %s/read is public', $this->qrCodes->getPath()), 'It tells whether a code is valid to anyone: prefer a controller of the application protected by a role (e.g. the organizers) and set reader.route to false.');
        }
    }
}