<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\Console;

use NeoPHP\Package\QrCode\Exception\QrCodeException;
use NeoPHP\Package\QrCode\Model\QrCode;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'qr:generate', description: 'Generates a QR code in the console, an SVG or a PNG file')]
class QrGenerateCommand extends AbstractConsole
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('content', InputArgument::REQUIRED, 'Text, URL, or JSON data with --signed');
        $input->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write (.svg or .png), otherwise the code is drawn in the console');
        $input->addOption('size', 's', InputOption::VALUE_REQUIRED, 'Size of the image in pixels');
        $input->addOption('error-correction', null, InputOption::VALUE_REQUIRED, 'low, medium, quartile or high');
        $input->addOption('signed', null, InputOption::VALUE_NONE, 'Signs the content with APP_SECRET (JSON content is decoded first)');
        $input->addOption('rotate', null, InputOption::VALUE_REQUIRED, 'With --signed: validity of a rotating code in seconds');
        $input->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'With --signed: lifetime in seconds');
        $input->addOption('once', null, InputOption::VALUE_NONE, 'With --signed: refused after the first read');
        $input->addOption('code', 'c', InputOption::VALUE_NONE, 'Registers the short code of the content');
        $this->setHelp('The short code (e.g. 7Q2K-4MXA) is typed in the reader when the QR code cannot be scanned. A signed QR code always has one.');
        $this->addExample('qr:generate "https://example.com"');
        $this->addExample('qr:generate "https://example.com" --output=public/qr.png --size=600');
        $this->addExample('qr:generate \'{"ticket": "TA-0042"}\' --signed --ttl=3600');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $content = (string) $input->getArgument('content');
        $options = array_filter([
            'size' => $input->getOption('size') !== null ? (int) $input->getOption('size') : null,
            'error_correction' => $input->getOption('error-correction'),
            'code' => (bool) $input->getOption('code') ?: null,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            if ($input->getOption('signed')) {
                $data = json_decode($content, true);
                $qr = $this->qrCodes->signed(
                    json_last_error() === JSON_ERROR_NONE ? $data : $content,
                    $input->getOption('rotate') !== null ? (int) $input->getOption('rotate') : null,
                    $input->getOption('ttl') !== null ? (int) $input->getOption('ttl') : null,
                    (bool) $input->getOption('once'),
                    $options,
                );
            } else {
                $qr = $this->qrCodes->create($content, $options);
            }

            $file = $input->getOption('output');

            if ($file === null) {
                $output->writeln($qr->toTerminal());
            } else {
                $format = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
                $this->write((string) $file, $qr->render($format === '' ? 'svg' : $format));
                $output->success(sprintf('QR code written to %s.', $file));
            }
        } catch (QrCodeException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->summary($output, $qr);

        return self::SUCCESS;
    }

    protected function summary(OutputInterface $output, QrCode $qr): void
    {
        $matrix = $qr->getMatrix();
        $definitions = [
            'Version' => sprintf('%d (%d x %d modules)', $matrix->getVersion(), $matrix->getSize(), $matrix->getSize()),
            'Error correction' => $matrix->getErrorCorrection()->value,
            'Content' => strlen($qr->getContent()) > 80 ? substr($qr->getContent(), 0, 77) . '...' : $qr->getContent(),
        ];

        if ($qr->getCode() !== null) {
            $definitions['Short code'] = $qr->getCode();
        }

        if ($qr->getExpiresAt() !== null) {
            $definitions['Expires'] = $qr->getExpiresAt()->format('Y-m-d H:i:s');
        }

        $output->definitionList($definitions);
    }

    /**
     * @throws QrCodeException When the file cannot be written
     */
    protected function write(string $file, string $data): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new QrCodeException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        if (@file_put_contents($file, $data) === false) {
            throw new QrCodeException('The file "{file}" cannot be written.', 0, null, ['file' => $file]);
        }
    }
}