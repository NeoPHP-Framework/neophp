<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

/**
 * @internal
 */
class QrCodeShortCodeViewHelper implements ViewFunctionInterface
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    public function getName(): string
    {
        return 'qr_code_short';
    }

    public function __invoke(string $content, ?int $ttl = null): string
    {
        return $this->qrCodes->code($content, $ttl);
    }
}