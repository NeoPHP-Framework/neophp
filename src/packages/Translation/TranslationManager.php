<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation;

use FilesystemIterator;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\Formatter\MessageFormatter;
use NeoPHP\Package\Translation\Loader\XliffLoader;
use NeoPHP\Package\Translation\Loader\YamlLoader;
use NeoPHP\Package\Translation\Provider\TranslationProvider;
use NeoPHP\Package\Translation\Trace\TranslationTrace;
use NeoPHP\Package\Yaml\YamlManagerInterface;

#[Package(provider: TranslationProvider::class)]
final class TranslationManager implements TranslationManagerInterface
{
    public const DETECTION_SOURCES = ['route', 'query', 'session', 'cookie', 'header'];

    public const FORMATS = ['yaml' => 'yaml', 'yml' => 'yaml', 'xliff' => 'xliff', 'xlf' => 'xliff'];

    public const FILE_PATTERN = '/^(?<domain>[A-Za-z0-9_\-+]+)\.(?<locale>[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*)\.(?<extension>yaml|yml|xlf|xliff)$/';

    public const CACHE_DIRECTORY = 'translation';

    public const DEFAULT_CONFIG = [
        'default_locale' => 'en',
        'locales' => [],
        'fallbacks' => null,
        'path' => 'translations',
        'default_domain' => self::DEFAULT_DOMAIN,
        'format' => 'yaml',
        'detection' => [
            'order' => self::DETECTION_SOURCES,
            'query_parameter' => 'lang',
            'session_key' => '_locale',
            'cookie_name' => 'locale',
            'cookie_lifetime' => 31536000,
        ],
        'cache' => true,
        'extract' => [
            'paths' => ['templates', 'src'],
        ],
    ];

    protected array $config = [];

    protected string $locale;

    protected string $defaultLocale;

    protected array $locales = [];

    protected array $fallbacks = [];

    protected string $defaultDomain = self::DEFAULT_DOMAIN;

    protected string $path;

    protected ?string $cachePath = null;

    protected bool $debug = false;

    protected bool $cache = true;

    protected array $loaders = [];

    protected MessageFormatter $formatter;

    protected array $resources = [];

    protected array $messages = [];

    protected array $catalogues = [];

    protected ?array $scanned = null;

    protected ?TranslationTrace $trace = null;

    public function __construct(array $config = [], ?string $rootPath = null, ?string $cachePath = null, bool $debug = false, ?YamlManagerInterface $yaml = null)
    {
        $rootPath = rtrim(str_replace('\\', '/', $rootPath ?? (string) getcwd()), '/');
        $this->config = self::normalizeConfig($config, $rootPath);
        $this->defaultLocale = $this->config['default_locale'];
        $this->locales = $this->config['locales'];
        $this->fallbacks = $this->config['fallbacks'];
        $this->defaultDomain = $this->config['default_domain'];
        $this->path = $this->config['path'];
        $this->cache = $this->config['cache'];
        $this->locale = $this->defaultLocale;
        $this->cachePath = $cachePath !== null ? rtrim(str_replace('\\', '/', $cachePath), '/') : null;
        $this->debug = $debug;
        $this->formatter = new MessageFormatter();
        $this->loaders = ['yaml' => new YamlLoader($yaml), 'xliff' => new XliffLoader()];
    }

    public static function normalizeConfig(array $config, string $rootPath): array
    {
        $config = array_replace(self::DEFAULT_CONFIG, array_filter($config, static fn (mixed $value): bool => $value !== null));
        $config['detection'] = array_replace(self::DEFAULT_CONFIG['detection'], (array) ($config['detection'] ?? []));
        $config['extract'] = array_replace(self::DEFAULT_CONFIG['extract'], (array) ($config['extract'] ?? []));

        $default = self::normalizeLocale((string) $config['default_locale']);

        if ($default === null) {
            throw new TranslationException('The default locale "{locale}" is invalid (packages.translation.default_locale).', 0, null, ['locale' => (string) $config['default_locale']]);
        }

        $locales = [];

        foreach ([$default, ...(array) $config['locales']] as $locale) {
            $normalized = self::normalizeLocale((string) $locale);

            if ($normalized === null) {
                throw new TranslationException('The locale "{locale}" is invalid (packages.translation.locales).', 0, null, ['locale' => (string) $locale]);
            }

            $locales[$normalized] = true;
        }

        $fallbacks = [];

        foreach ((array) ($config['fallbacks'] ?? [$default]) as $locale) {
            $normalized = self::normalizeLocale((string) $locale);

            if ($normalized === null) {
                throw new TranslationException('The fallback locale "{locale}" is invalid (packages.translation.fallbacks).', 0, null, ['locale' => (string) $locale]);
            }

            $fallbacks[$normalized] = true;
        }

        $format = strtolower((string) $config['format']);

        if (!isset(self::FORMATS[$format])) {
            throw new TranslationException('The translation format "{format}" is invalid: use "yaml" or "xliff" (packages.translation.format).', 0, null, ['format' => $format]);
        }

        $order = array_values(array_map(static fn (mixed $source): string => strtolower((string) $source), (array) $config['detection']['order']));
        $unknown = array_diff($order, self::DETECTION_SOURCES);

        if ($unknown !== []) {
            throw new TranslationException('Unknown locale detection source(s) "{sources}": use {allowed} (packages.translation.detection.order).', 0, null, [
                'sources' => implode('", "', $unknown),
                'allowed' => implode(', ', self::DETECTION_SOURCES),
            ]);
        }

        $path = str_replace('\\', '/', (string) $config['path']);

        return [
            'default_locale' => $default,
            'locales' => array_keys($locales),
            'fallbacks' => array_keys($fallbacks),
            'path' => rtrim(self::isAbsolute($path) ? $path : $rootPath . '/' . $path, '/'),
            'default_domain' => (string) $config['default_domain'] !== '' ? (string) $config['default_domain'] : self::DEFAULT_DOMAIN,
            'format' => self::FORMATS[$format],
            'detection' => [
                'order' => array_values(array_unique($order)),
                'query_parameter' => (string) $config['detection']['query_parameter'],
                'session_key' => (string) $config['detection']['session_key'],
                'cookie_name' => (string) $config['detection']['cookie_name'],
                'cookie_lifetime' => (int) $config['detection']['cookie_lifetime'],
            ],
            'cache' => (bool) $config['cache'],
            'extract' => [
                'paths' => array_values(array_map(static function (mixed $directory) use ($rootPath): string {
                    $directory = str_replace('\\', '/', (string) $directory);

                    return rtrim(self::isAbsolute($directory) ? $directory : $rootPath . '/' . $directory, '/');
                }, (array) $config['extract']['paths'])),
            ],
        ];
    }

    public function translate(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $domain = $domain === null || $domain === '' ? $this->defaultDomain : $domain;
        $locale = $locale === null || $locale === '' ? $this->locale : (static::normalizeLocale($locale) ?? $this->locale);
        $message = null;
        $formatLocale = $locale;
        $resolved = null;

        foreach ([$locale, ...$this->getFallbackLocales($locale)] as $candidate) {
            $value = $this->loadCatalogue($candidate)[$domain][$key] ?? null;

            if (is_string($value) && $value !== '') {
                $message = $value;
                $formatLocale = $candidate;
                $resolved = $candidate;
                break;
            }
        }

        $result = $this->formatter->format($message ?? $key, $parameters, $formatLocale);
        $this->trace?->add($key, $domain, $locale, $resolved, $result, $parameters);

        return $result;
    }

    public function has(string $key, ?string $domain = null, ?string $locale = null): bool
    {
        $domain = $domain === null || $domain === '' ? $this->defaultDomain : $domain;
        $locale = $locale === null || $locale === '' ? $this->locale : (static::normalizeLocale($locale) ?? $this->locale);

        foreach ([$locale, ...$this->getFallbackLocales($locale)] as $candidate) {
            $value = $this->loadCatalogue($candidate)[$domain][$key] ?? null;

            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $matched = $this->matchLocale($locale);

        if ($matched === null) {
            throw new TranslationException('The locale "{locale}" is not enabled: use one of "{locales}" (packages.translation.locales).', 0, null, [
                'locale' => $locale,
                'locales' => implode('", "', $this->locales),
            ]);
        }

        $this->locale = $matched;

        return $this;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function getLocales(): array
    {
        return $this->locales;
    }

    public function getFallbackLocales(string $locale): array
    {
        $locale = static::normalizeLocale($locale) ?? $locale;
        $chain = [];
        $parts = explode('_', $locale);

        while (count($parts) > 1) {
            array_pop($parts);
            $chain[] = implode('_', $parts);
        }

        foreach ($this->fallbacks as $fallback) {
            $chain[] = $fallback;
        }

        return array_values(array_diff(array_unique($chain), [$locale]));
    }

    public function matchLocale(string $locale): ?string
    {
        $locale = static::normalizeLocale($locale);

        if ($locale === null) {
            return null;
        }

        if (in_array($locale, $this->locales, true)) {
            return $locale;
        }

        $language = explode('_', $locale)[0];

        if (in_array($language, $this->locales, true)) {
            return str_contains($locale, '_') && $this->hasLocaleResources($locale) ? $locale : $language;
        }

        foreach ($this->locales as $enabled) {
            if (explode('_', $enabled)[0] === $language) {
                return $enabled;
            }
        }

        return null;
    }

    public function getDefaultDomain(): string
    {
        return $this->defaultDomain;
    }

    public function getCatalogue(string $locale, ?string $domain = null): array
    {
        $locale = static::normalizeLocale($locale) ?? $locale;

        return $this->loadCatalogue($locale)[$domain === null || $domain === '' ? $this->defaultDomain : $domain] ?? [];
    }

    public function getDomains(?string $locale = null): array
    {
        $domains = [];
        $locales = $locale === null ? array_unique([...$this->locales, ...array_column($this->scan(), 'locale')]) : [static::normalizeLocale($locale) ?? $locale];

        foreach ($locales as $candidate) {
            foreach (array_keys($this->loadCatalogue((string) $candidate)) as $domain) {
                $domains[(string) $domain] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains);

        return $domains;
    }

    public function addResource(string $file, ?string $locale = null, ?string $domain = null): static
    {
        $file = str_replace('\\', '/', $file);

        if (!is_file($file)) {
            throw new TranslationException('The translation file "{file}" does not exist.', 0, null, ['file' => $file]);
        }

        $resource = static::describe($file, 1);

        if ($resource === null && ($locale === null || $domain === null)) {
            throw new TranslationException('Unable to guess the domain and the locale of "{file}": name it {domain}.{locale}.{yaml|xlf} or pass them.', 0, null, ['file' => $file]);
        }

        $resource ??= ['file' => $file, 'domain' => (string) $domain, 'locale' => '', 'format' => self::FORMATS[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'yaml', 'priority' => 1];
        $resource['locale'] = $locale !== null ? (static::normalizeLocale($locale) ?? $locale) : $resource['locale'];
        $resource['domain'] = $domain ?? $resource['domain'];

        $this->resources[] = $resource;
        $this->scanned = null;
        unset($this->catalogues[$resource['locale']]);

        return $this;
    }

    public function addMessages(array $messages, string $locale, ?string $domain = null): static
    {
        $locale = static::normalizeLocale($locale) ?? $locale;
        $domain = $domain === null || $domain === '' ? $this->defaultDomain : $domain;
        $this->messages[$locale][$domain] = array_replace($this->messages[$locale][$domain] ?? [], static::flatten($messages));
        unset($this->catalogues[$locale]);

        return $this;
    }

    public function getResources(?string $locale = null): array
    {
        $resources = [];
        $locale = $locale !== null ? (static::normalizeLocale($locale) ?? $locale) : null;

        foreach ($this->scan() as $resource) {
            if ($locale === null || $resource['locale'] === $locale) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    public function loadFile(string $file): array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $format = self::FORMATS[$extension] ?? null;

        if ($format === null || !isset($this->loaders[$format])) {
            throw new TranslationException('No translation loader supports the file "{file}" (supported: .yaml, .yml, .xlf, .xliff).', 0, null, ['file' => $file]);
        }

        return $this->loaders[$format]->load($file);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getFormatter(): MessageFormatter
    {
        return $this->formatter;
    }

    public function setTrace(?TranslationTrace $trace): static
    {
        $this->trace = $trace;

        return $this;
    }

    public function getTrace(): ?TranslationTrace
    {
        return $this->trace;
    }

    public function getLoadedCatalogues(): array
    {
        $loaded = [];

        foreach ($this->catalogues as $locale => $catalogue) {
            foreach ($catalogue as $domain => $messages) {
                $loaded[(string) $locale][(string) $domain] = count($messages);
            }
        }

        return $loaded;
    }

    public function clearCache(): void
    {
        $this->catalogues = [];
        $this->scanned = null;

        if ($this->cachePath === null || !is_dir($this->cachePath . '/' . self::CACHE_DIRECTORY)) {
            return;
        }

        foreach (new FilesystemIterator($this->cachePath . '/' . self::CACHE_DIRECTORY) as $file) {
            if ($file->isFile()) {
                @unlink($file->getPathname());
            }
        }
    }

    public static function describe(string $file, int $priority = 2): ?array
    {
        if (preg_match(self::FILE_PATTERN, basename($file), $match) !== 1) {
            return null;
        }

        $locale = static::normalizeLocale($match['locale']);

        if ($locale === null) {
            return null;
        }

        return [
            'file' => str_replace('\\', '/', $file),
            'domain' => $match['domain'],
            'locale' => $locale,
            'format' => self::FORMATS[strtolower($match['extension'])],
            'priority' => $priority,
        ];
    }

    public static function normalizeLocale(string $locale): ?string
    {
        $parts = preg_split('/[_\-]/', trim($locale)) ?: [];

        if ($parts === [] || preg_match('/^[A-Za-z]{2,3}$/', $parts[0]) !== 1) {
            return null;
        }

        $normalized = [strtolower(array_shift($parts))];

        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z]{4}$/', $part) === 1) {
                $normalized[] = ucfirst(strtolower($part));
            } elseif (preg_match('/^([A-Za-z]{2}|\d{3})$/', $part) === 1) {
                $normalized[] = strtoupper($part);
            } else {
                return null;
            }
        }

        return implode('_', $normalized);
    }

    public static function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $key = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += static::flatten($value, $key);
                continue;
            }

            $flat[$key] = (string) $value;
        }

        return $flat;
    }

    protected static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    protected function loadCatalogue(string $locale): array
    {
        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }

        $resources = array_values(array_filter($this->scan(), static fn (array $resource): bool => $resource['locale'] === $locale));
        $catalogue = [];

        if ($resources !== []) {
            $builder = fn (): array => [$this->build($resources), $this->freshness($resources)];

            if ($this->cache && $this->cachePath !== null) {
                $signature = hash('xxh128', serialize(array_column($resources, 'file')));
                $cache = new ResourceCache($this->cachePath . '/' . self::CACHE_DIRECTORY . '/' . $locale . '.php', $this->debug);
                $data = $cache->load(fn (): array => [['signature' => $signature, 'catalogue' => $this->build($resources)], $this->freshness($resources)]);

                if (($data['signature'] ?? null) !== $signature) {
                    $cache->clear();
                    $data = $cache->load(fn (): array => [['signature' => $signature, 'catalogue' => $this->build($resources)], $this->freshness($resources)]);
                }

                $catalogue = (array) ($data['catalogue'] ?? []);
            } else {
                $catalogue = $builder()[0];
            }
        }

        foreach ($this->messages[$locale] ?? [] as $domain => $messages) {
            $catalogue[$domain] = array_replace($catalogue[$domain] ?? [], $messages);
        }

        return $this->catalogues[$locale] = $catalogue;
    }

    protected function build(array $resources): array
    {
        $catalogue = [];

        foreach ($resources as $resource) {
            $catalogue[$resource['domain']] = array_replace($catalogue[$resource['domain']] ?? [], $this->loadFile($resource['file']));
        }

        return $catalogue;
    }

    protected function freshness(array $resources): array
    {
        $files = [];

        foreach ($resources as $resource) {
            $files[$resource['file']] = (int) filemtime($resource['file']);
        }

        if (is_dir($this->path)) {
            $files[$this->path] = (int) filemtime($this->path);
        }

        return $files;
    }

    protected function scan(): array
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }

        $resources = [...$this->resources, ...$this->scanDirectory($this->path, 2)];

        usort($resources, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $this->scanned = $resources;
    }

    protected function scanDirectory(string $directory, int $priority): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $resources = [];
        $files = scandir($directory) ?: [];
        sort($files);

        foreach ($files as $name) {
            $resource = static::describe($directory . '/' . $name, $priority);

            if ($resource !== null && is_file($resource['file'])) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    protected function hasLocaleResources(string $locale): bool
    {
        return in_array($locale, array_column($this->scan(), 'locale'), true) || isset($this->messages[$locale]);
    }
}