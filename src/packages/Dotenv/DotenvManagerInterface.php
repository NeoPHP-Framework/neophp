<?php

declare(strict_types=1);

namespace NeoPHP\Package\Dotenv;

interface DotenvManagerInterface
{
    public function parse(string $content, ?string $path = null): array;

    public function load(string ...$paths): void;

    public function loadEnv(string $directory, string $envKey = 'APP_ENV', string $defaultEnv = 'dev'): void;

    public function populate(array $values, bool $overrideExisting = false): void;
}