<?php

declare(strict_types=1);

namespace NeoPHP\Package\Yaml;

use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\Yaml\Exception\ParseException;
use NeoPHP\Package\Yaml\Parser\Parser;
use NeoPHP\Package\Yaml\Provider\YamlProvider;

#[Package(provider: YamlProvider::class)]
final class YamlManager implements YamlManagerInterface
{
    public function parseFile(string $file): mixed
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new ParseException(sprintf('File "%s" does not exist or is not readable', $file));
        }

        try {
            return $this->parse((string) file_get_contents($file));
        } catch (ParseException $exception) {
            throw $exception->withFile($file);
        }
    }

    public function parse(string $input): mixed
    {
        return (new Parser())->parse($input);
    }
}