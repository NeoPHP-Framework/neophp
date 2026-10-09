<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Formatter;

class PluralRules
{
    public const CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    protected const OTHER_ONLY = ['ja', 'zh', 'ko', 'th', 'vi', 'id', 'ms', 'lo', 'my', 'km', 'bo', 'dz', 'ig', 'jv', 'kea', 'ses', 'sg', 'to', 'wo', 'yo'];

    protected const ZERO_ONE = ['fr', 'pt', 'hi', 'bn', 'fa', 'gu', 'kn', 'mr', 'zu', 'am', 'as', 'ff', 'hy', 'kab', 'ln', 'ti', 'wa'];

    protected const SLAVIC_EAST = ['ru', 'uk', 'be', 'sr', 'hr', 'bs', 'sh'];

    protected const WEST_SLAVIC = ['cs', 'sk'];

    public static function category(string $locale, int|float $number): string
    {
        $language = strtolower((string) preg_replace('/[_-].*$/', '', $locale));
        $absolute = abs($number);
        $integer = (int) floor($absolute);
        $isInteger = (float) $integer === (float) $absolute;

        if (in_array($language, self::OTHER_ONLY, true)) {
            return 'other';
        }

        if (in_array($language, self::ZERO_ONE, true)) {
            if ($language === 'pt' && str_contains(strtolower($locale), 'pt_pt')) {
                return $absolute == 1 ? 'one' : 'other';
            }

            return $integer === 0 || $integer === 1 ? 'one' : 'other';
        }

        if (in_array($language, self::SLAVIC_EAST, true)) {
            if (!$isInteger) {
                return 'other';
            }

            $mod10 = $integer % 10;
            $mod100 = $integer % 100;

            if ($mod10 === 1 && $mod100 !== 11) {
                return 'one';
            }

            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
                return 'few';
            }

            return in_array($language, ['ru', 'uk', 'be'], true) ? 'many' : 'other';
        }

        if ($language === 'pl') {
            if (!$isInteger) {
                return 'other';
            }

            if ($integer === 1) {
                return 'one';
            }

            $mod10 = $integer % 10;
            $mod100 = $integer % 100;

            return $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) ? 'few' : 'many';
        }

        if (in_array($language, self::WEST_SLAVIC, true)) {
            if (!$isInteger) {
                return 'many';
            }

            return $integer === 1 ? 'one' : ($integer >= 2 && $integer <= 4 ? 'few' : 'other');
        }

        if ($language === 'ar') {
            if (!$isInteger) {
                return 'other';
            }

            $mod100 = $integer % 100;

            return match (true) {
                $integer === 0 => 'zero',
                $integer === 1 => 'one',
                $integer === 2 => 'two',
                $mod100 >= 3 && $mod100 <= 10 => 'few',
                $mod100 >= 11 => 'many',
                default => 'other',
            };
        }

        if ($language === 'he' || $language === 'iw') {
            return $isInteger && $integer === 1 ? 'one' : ($isInteger && $integer === 2 ? 'two' : 'other');
        }

        if ($language === 'lt') {
            $mod10 = $integer % 10;
            $mod100 = $integer % 100;

            return match (true) {
                !$isInteger => 'many',
                $mod10 === 1 && ($mod100 < 11 || $mod100 > 19) => 'one',
                $mod10 >= 2 && ($mod100 < 11 || $mod100 > 19) => 'few',
                default => 'other',
            };
        }

        if ($language === 'ro') {
            $mod100 = $integer % 100;

            return match (true) {
                $isInteger && $integer === 1 => 'one',
                !$isInteger || $integer === 0 || ($mod100 >= 2 && $mod100 <= 19) => 'few',
                default => 'other',
            };
        }

        if ($language === 'ga') {
            return match (true) {
                $absolute == 1 => 'one',
                $absolute == 2 => 'two',
                $isInteger && $integer >= 3 && $integer <= 6 => 'few',
                $isInteger && $integer >= 7 && $integer <= 10 => 'many',
                default => 'other',
            };
        }

        return $absolute == 1 && $isInteger ? 'one' : 'other';
    }
}