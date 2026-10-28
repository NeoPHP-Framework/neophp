<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\Doctor;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;

/**
 * @internal
 */
class PhpCheck implements CheckInterface
{
    public const MINIMUM_VERSION = '8.2.0';

    public const REQUIRED_EXTENSIONS = ['ctype', 'json', 'mbstring', 'tokenizer'];

    public const MINIMUM_MEMORY = 128 * 1024 * 1024;

    public function __construct(#[Autowire(param: 'kernel.environment')] protected string $environment = 'dev')
    {
    }

    public function getName(): string
    {
        return 'PHP';
    }

    public function run(): iterable
    {
        yield version_compare(PHP_VERSION, self::MINIMUM_VERSION, '>=')
            ? CheckResult::ok('Version', PHP_VERSION)
            : CheckResult::error('Version', PHP_VERSION, sprintf('NeoPHP needs PHP %s or later.', self::MINIMUM_VERSION));

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                yield CheckResult::error('Extension ' . $extension, 'missing', sprintf('Install and enable the PHP extension %s.', $extension));
            }
        }

        $memory = self::bytes((string) ini_get('memory_limit'));

        if ($memory !== -1 && $memory < self::MINIMUM_MEMORY) {
            yield CheckResult::warning('memory_limit', (string) ini_get('memory_limit'), 'Set memory_limit to 128M or more in php.ini.');
        }

        if ($this->environment === 'prod') {
            yield extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
                ? CheckResult::ok('OPcache', 'enabled')
                : CheckResult::warning('OPcache', 'disabled', 'Enable OPcache in production (opcache.enable=1): PHP compiles every file at each request without it.');
        }

        yield CheckResult::info('php.ini', php_ini_loaded_file() ?: 'none', 'The web server can use another php.ini than the command line: check it too (phpinfo()).');
    }

    /**
     * Converts a php.ini size (128M, 1G, -1) into bytes.
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}