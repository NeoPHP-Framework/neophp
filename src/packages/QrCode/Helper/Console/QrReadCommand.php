<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\Console;

use NeoPHP\Package\QrCode\Exception\QrCodeException;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'qr:read', description: 'Reads the content of a QR code or its short code and prints it in JSON')]
class QrReadCommand extends AbstractConsole
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('input', InputArgument::REQUIRED, 'Scanned content or short code (e.g. 7Q2K-4MXA)');
        $this->setHelp('Same result as QrCodeManagerInterface::read(): a signed content is verified (signature, expiration, single use). The exit code is 1 when the result is not valid.');
        $this->addExample('qr:read 7Q2K-4MXA');
        $this->addExample('qr:read "NQR1.eyJkIjp7...}.abc"');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->qrCodes->read((string) $input->getArgument('input'));
        } catch (QrCodeException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result->isValid() ? self::SUCCESS : self::FAILURE;
    }
}