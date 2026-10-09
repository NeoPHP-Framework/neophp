<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Loader;

use NeoPHP\Package\Translation\Contract\LoaderInterface;
use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Yaml\YamlManager;
use NeoPHP\Package\Yaml\YamlManagerInterface;
use Throwable;

class YamlLoader implements LoaderInterface
{
    protected YamlManagerInterface $yaml;

    public function __construct(?YamlManagerInterface $yaml = null)
    {
        $this->yaml = $yaml ?? new YamlManager();
    }

    public function load(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new TranslationException('The translation file "{file}" does not exist or is not readable.', 0, null, ['file' => $file]);
        }

        try {
            $data = $this->yaml->parseFile($file);
        } catch (Throwable $exception) {
            throw new TranslationException('Unable to parse the translation file "{file}": {error}', 0, $exception, ['file' => $file, 'error' => $exception->getMessage()]);
        }

        if ($data === null || $data === '') {
            return [];
        }

        if (!is_array($data)) {
            throw new TranslationException('The translation file "{file}" must contain a map of messages.', 0, null, ['file' => $file]);
        }

        return self::flatten($data);
    }

    public function getExtensions(): array
    {
        return ['yaml', 'yml'];
    }

    public static function flatten(array $data, string $prefix = ''): array
    {
        $messages = [];

        foreach ($data as $key => $value) {
            $key = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $messages += self::flatten($value, $key);
                continue;
            }

            $messages[$key] = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return $messages;
    }
}