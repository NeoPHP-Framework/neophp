<?php

declare(strict_types=1);

namespace NeoPHP\Package\Dotenv;

use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\Dotenv\Exception\DotenvException;
use NeoPHP\Package\Dotenv\Parser\Parser;
use NeoPHP\Package\Dotenv\Provider\DotenvProvider;

#[Package(provider: DotenvProvider::class)]
final class DotenvManager implements DotenvManagerInterface
{
    public const LOADED_VARS = 'NEOPHP_DOTENV_VARS';

    public function load(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new DotenvException(sprintf('Unable to read the "%s" environment file.', $path));
            }

            $this->populate($this->parse((string) file_get_contents($path), $path));
        }
    }

    public function loadEnv(string $directory, string $envKey = 'APP_ENV', string $defaultEnv = 'dev'): void
    {
        $directory = rtrim($directory, '/\\');
        $base = $directory . DIRECTORY_SEPARATOR . '.env';

        if (is_file($base)) {
            $this->load($base);
        }

        $env = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? null;

        if (!is_string($env) || $env === '') {
            $env = $defaultEnv;
            $this->populate([$envKey => $env]);
        }

        $candidates = [];

        if ($env !== 'test') {
            $candidates[] = $base . '.local';
        }

        $candidates[] = $base . '.' . $env;
        $candidates[] = $base . '.' . $env . '.local';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $this->load($candidate);
            }
        }
    }

    public function populate(array $values, bool $overrideExisting = false): void
    {
        $loaded = array_flip(array_filter(explode(',', (string) ($_SERVER[self::LOADED_VARS] ?? ''))));

        foreach ($values as $name => $value) {
            $isReal = !isset($loaded[$name]) && (isset($_SERVER[$name]) || isset($_ENV[$name]) || getenv($name) !== false);

            if ($isReal && !$overrideExisting) {
                continue;
            }

            $_ENV[$name] = $value;

            if (!str_starts_with($name, 'HTTP_')) {
                $_SERVER[$name] = $value;
            }

            $loaded[$name] = true;
        }

        unset($loaded[self::LOADED_VARS]);
        $_SERVER[self::LOADED_VARS] = $_ENV[self::LOADED_VARS] = implode(',', array_keys($loaded));
    }

    public function parse(string $content, ?string $path = null): array
    {
        $known = [];

        foreach ($_SERVER + $_ENV as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $known[$name] = (string) $value;
            }
        }

        return (new Parser())->parse($content, $path, $known);
    }
}