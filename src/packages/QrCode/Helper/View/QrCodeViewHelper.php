<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

/**
 * @internal
 */
class QrCodeViewHelper implements ViewFunctionInterface, ViewSafeHtmlInterface
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    public function getName(): string
    {
        return 'qr_code';
    }

    /**
     * @param array<string, mixed> $options size, margin, error_correction, foreground, background, logo, logo_size
     */
    public function __invoke(string $content, array $options = []): string
    {
        unset($options['code']);

        return $this->qrCodes->create($content, $options)->toSvg();
    }
}