<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

/**
 * @internal
 */
class QrReaderViewHelper implements ViewFunctionInterface, ViewSafeHtmlInterface
{
    public const ATTRIBUTES = ['id', 'class', 'cooldown', 'facing', 'autostart', 'manual', 'csrf_token', 'csrf_header', 'label_start', 'label_stop', 'label_torch', 'label_manual', 'label_submit', 'label_placeholder', 'label_unsupported', 'label_denied'];

    protected bool $scriptIncluded = false;

    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    public function getName(): string
    {
        return 'qr_reader';
    }

    /**
     * @param string|null $endpoint URL receiving {"content": "..."} in POST (null = only the qr-scan event)
     * @param array<string, scalar|null> $options id, class, cooldown (ms), facing (environment or user), autostart, manual (true, false or only),
     *                                            csrf_token, csrf_header, label_* (texts of the element)
     */
    public function __invoke(?string $endpoint = null, array $options = []): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attributes = ['code-length' => (string) $this->qrCodes->getConfig()['code']['length']];

        if ($endpoint !== null && $endpoint !== '') {
            $attributes['endpoint'] = $endpoint;
        }

        foreach ($options as $name => $value) {
            if (!in_array($name, self::ATTRIBUTES, true) || $value === null) {
                continue;
            }

            $attributes[str_replace('_', '-', (string) $name)] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $html = '<neo-qr-reader';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . $escape($value) . '"';
        }

        $html .= '></neo-qr-reader>';

        if (!$this->scriptIncluded) {
            $this->scriptIncluded = true;
            $html .= '<script src="' . $escape($this->qrCodes->getReaderScriptUrl()) . '" defer></script>';
        }

        return $html;
    }
}