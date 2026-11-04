<?php

namespace NeoPHP\Package\Orm\Translatable;

use Closure;

final class TranslatableLocale
{
    private static ?Closure $locale = null;
    private static ?Closure $fallbacks = null;


    /**
     * @param Closure|null $locale
     * @param Closure|null $fallbacks
     */
    public static function setResolver(?Closure $locale, ?Closure $fallbacks = null): void
    {
        self::$locale = $locale;
        self::$fallbacks = $fallbacks;
    }

    public static function getLocale(): ?string
    {
        return self::$locale !== null ? (self::$locale)() : null;
    }

    public static function getFallbackLocales(string $locale): array
    {
        return self::$fallbacks !== null
            ? array_values(array_diff((self::$fallbacks)($locale), [$locale]))
            : [];
    }
}