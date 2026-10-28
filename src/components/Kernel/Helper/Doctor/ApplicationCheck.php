<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\Doctor;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class ApplicationCheck implements CheckInterface
{
    public const MINIMUM_SECRET_LENGTH = 32;

    public function __construct(
        protected ?ConfigManagerInterface $config = null,
        #[Autowire(param: 'kernel.environment')]
        protected string $environment = 'dev',
        #[Autowire(param: 'kernel.debug')]
        protected bool $debug = true,
        #[Autowire(param: 'kernel.root_path')]
        protected string $rootPath = '',
        #[Autowire(param: 'kernel.cache_path')]
        protected string $cachePath = '',
    ) {
    }

    public function getName(): string
    {
        return 'Application';
    }

    public function run(): iterable
    {
        $production = $this->environment === 'prod';

        if ($production && $this->debug) {
            yield CheckResult::error('Debug', 'enabled in production', 'Set APP_DEBUG=0 in .env.local: the debug mode shows the errors, the code and the configuration to the visitors.');
        } else {
            yield CheckResult::ok('Environment', $this->environment . ', debug ' . ($this->debug ? 'on' : 'off'));
        }

        $secret = (string) $this->setting('framework.app.secret', '');

        yield match (true) {
            $secret === '' => CheckResult::error('APP_SECRET', 'empty', 'Set APP_SECRET in .env.local to a random value: php -r "echo bin2hex(random_bytes(32));"'),
            strlen($secret) < self::MINIMUM_SECRET_LENGTH => CheckResult::warning('APP_SECRET', sprintf('%d characters', strlen($secret)), sprintf('Use at least %d random characters: php -r "echo bin2hex(random_bytes(32));"', self::MINIMUM_SECRET_LENGTH)),
            default => CheckResult::ok('APP_SECRET', 'set'),
        };

        $url = (string) $this->setting('framework.app.url', '');

        if ($url === '') {
            yield CheckResult::warning('APP_URL', 'empty', 'Set APP_URL (e.g. https://example.com): it builds the absolute URLs of the console and of the emails.');
        } elseif ($production && str_starts_with($url, 'http://') && !preg_match('#^http://(localhost|127\.0\.0\.1)([:/]|$)#', $url)) {
            yield CheckResult::warning('APP_URL', $url, 'Use HTTPS in production.');
        } else {
            yield CheckResult::ok('APP_URL', $url);
        }

        if ($production) {
            $hosts = $this->setting('framework.app.trusted_hosts', '');

            yield $hosts === null || $hosts === '' || $hosts === []
                ? CheckResult::warning('Trusted hosts', 'any host accepted', 'Set TRUSTED_HOSTS (e.g. example.com,www.example.com): a forged Host header can poison the generated links.')
                : CheckResult::ok('Trusted hosts', is_array($hosts) ? implode(', ', array_map('strval', $hosts)) : (string) $hosts);
        }

        $directories = [
            'var/cache' => $this->cachePath !== '' ? $this->cachePath : $this->rootPath . '/var/cache',
            'var/log' => $this->rootPath . '/var/log',
            'Sessions' => (string) $this->setting('framework.app.session.save_path', $this->rootPath . '/var/sessions'),
        ];

        foreach ($directories as $label => $directory) {
            if ($directory !== '') {
                yield self::writable($label, $directory);
            }
        }
    }

    /**
     * Tells whether a directory is writable, or can be created.
     */
    public static function writable(string $label, string $directory): CheckResult
    {
        if (is_dir($directory)) {
            return is_writable($directory)
                ? CheckResult::ok($label, 'writable')
                : CheckResult::error($label, $directory . ' is not writable', sprintf('Give the write permission to the user of PHP and of the web server: chmod -R u+w %s (or chown).', $directory));
        }

        $parent = dirname($directory);

        while (!is_dir($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }

        return is_writable($parent)
            ? CheckResult::ok($label, 'created on first use')
            : CheckResult::error($label, $directory . ' does not exist and cannot be created', sprintf('Create it and make it writable: mkdir -p %s', $directory));
    }

    protected function setting(string $key, mixed $default): mixed
    {
        try {
            return $this->config?->get($key, $default) ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }
}