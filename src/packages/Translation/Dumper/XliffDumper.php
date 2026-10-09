<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Dumper;

use NeoPHP\Package\Translation\Contract\DumperInterface;

class XliffDumper implements DumperInterface
{
    public function dump(array $messages, string $locale, string $domain, string $sourceLocale): string
    {
        $output = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">' . "\n"
            . '  <file source-language="' . self::escape(str_replace('_', '-', $sourceLocale)) . '" target-language="' . self::escape(str_replace('_', '-', $locale)) . '" datatype="plaintext" original="' . self::escape($domain) . '">' . "\n"
            . "    <body>\n";

        foreach ($messages as $key => $message) {
            $key = (string) $key;
            $output .= '      <trans-unit id="' . substr(hash('sha256', $key), 0, 16) . '" resname="' . self::escape($key) . '">' . "\n"
                . '        <source>' . self::escape($key) . "</source>\n"
                . '        <target>' . self::escape((string) $message) . "</target>\n"
                . "      </trans-unit>\n";
        }

        return $output . "    </body>\n  </file>\n</xliff>\n";
    }

    public function getExtension(): string
    {
        return 'xlf';
    }

    protected static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}