<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Contract;

interface DumperInterface
{
    public function dump(array $messages, string $locale, string $domain, string $sourceLocale): string;

    public function getExtension(): string;
}