<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

/**
 * @internal
 */
class QrCodeUrlViewHelper implements ViewFunctionInterface
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    public function getName(): string
    {
        return 'qr_code_url';
    }

    public function __invoke(string $content, array $options = [], string $format = 'svg'): string
    {
        return $this->qrCodes->imageUrl($content, $options, $format);
    }
}