<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Loader;

use DOMDocument;
use DOMElement;
use NeoPHP\Package\Translation\Contract\LoaderInterface;
use NeoPHP\Package\Translation\Exception\TranslationException;

class XliffLoader implements LoaderInterface
{
    public function load(string $file): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new TranslationException('The "dom" PHP extension is required to load the XLIFF file "{file}".', 0, null, ['file' => $file]);
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new TranslationException('The translation file "{file}" does not exist or is not readable.', 0, null, ['file' => $file]);
        }

        $content = (string) file_get_contents($file);

        if (trim($content) === '') {
            return [];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($content, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $errors !== []) {
            $error = $errors[0] ?? null;

            throw new TranslationException('Unable to parse the XLIFF file "{file}": {error}', 0, null, [
                'file' => $file,
                'error' => $error !== null ? trim($error->message) . ' (line ' . $error->line . ')' : 'invalid XML',
            ]);
        }

        if ($document->documentElement === null || $document->documentElement->localName !== 'xliff') {
            throw new TranslationException('The file "{file}" is not an XLIFF document.', 0, null, ['file' => $file]);
        }

        $messages = [];

        foreach ($document->getElementsByTagNameNS('*', 'trans-unit') as $unit) {

            $source = self::child($unit, 'source');
            $key = $unit->getAttribute('resname') !== '' ? $unit->getAttribute('resname') : $source;

            if ($key === null || $key === '') {
                continue;
            }

            $messages[$key] = self::child($unit, 'target') ?? '';
        }

        foreach ($document->getElementsByTagNameNS('*', 'unit') as $unit) {

            $key = $unit->getAttribute('name') !== '' ? $unit->getAttribute('name') : ($unit->getAttribute('id') !== '' ? $unit->getAttribute('id') : null);
            $segment = self::child($unit, 'target');

            if ($key !== null) {
                $messages[$key] = $segment ?? '';
            }
        }

        return $messages;
    }

    public function getExtensions(): array
    {
        return ['xlf', 'xliff'];
    }

    protected static function child(DOMElement $element, string $name): ?string
    {
        foreach ($element->getElementsByTagNameNS('*', $name) as $child) {
            return $child->textContent;
        }

        return null;
    }
}