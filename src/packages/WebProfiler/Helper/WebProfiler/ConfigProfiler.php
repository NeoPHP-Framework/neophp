<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use NeoPHP\Package\WebProfiler\WebProfilerManagerInterface;
use Throwable;

/**
 * @internal
 */
class ConfigProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = -100;

    public const INI = ['memory_limit', 'max_execution_time', 'display_errors', 'error_reporting', 'opcache.enable', 'opcache.jit', 'date.timezone', 'upload_max_filesize', 'post_max_size'];

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_FLAG_CASE | SORT_STRING);
        $ini = [];

        foreach (self::INI as $key) {
            $value = ini_get($key);
            $ini[$key] = $value === false ? null : $value;
        }

        $elements = [];

        if ($this->container->bound(WebProfilerManagerInterface::class)) {
            foreach ($this->container->get(WebProfilerManagerInterface::class)->getElements() as $name => $element) {
                $elements[] = [$name, $element::class, $element->getPriority()];
            }
        }

        return [
            'framework' => [
                'version' => $this->parameter('kernel.version', 'dev'),
                'environment' => $this->parameter('kernel.environment', 'dev'),
                'debug' => (bool) $this->parameter('kernel.debug', false),
                'root_path' => $this->parameter('kernel.root_path'),
                'cache_path' => $this->parameter('kernel.cache_path'),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'architecture' => PHP_INT_SIZE * 8 . ' bits',
                'opcache' => function_exists('opcache_get_status') && (bool) ini_get('opcache.enable'),
                'xdebug' => extension_loaded('xdebug'),
            ],
            'ini' => $ini,
            'extensions' => array_map(static fn (string $name): array => [$name, (string) (phpversion($name) ?: '')], $extensions),
            'elements' => $elements,
        ];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        $framework = (array) ($data['framework'] ?? []);
        $php = (array) ($data['php'] ?? []);

        return new ToolbarItem('NeoPHP', (string) ($framework['version'] ?? 'dev'), 'logo', Status::DEFAULT, [
            'NeoPHP' => $framework['version'] ?? 'dev',
            'Environment' => $framework['environment'] ?? 'dev',
            'Debug' => ($framework['debug'] ?? false) ? 'enabled' : 'disabled',
            'PHP' => $php['version'] ?? PHP_VERSION,
            'SAPI' => $php['sapi'] ?? PHP_SAPI,
            'OPcache' => ($php['opcache'] ?? false) ? 'enabled' : 'disabled',
            'Xdebug' => ($php['xdebug'] ?? false) ? 'enabled' : 'disabled',
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        return new Panel('Configuration', 'config', [
            new KeyValueBlock((array) ($data['framework'] ?? []), 'NeoPHP'),
            new TabsBlock([
                'PHP' => [new KeyValueBlock((array) ($data['php'] ?? [])), new KeyValueBlock((array) ($data['ini'] ?? []), 'php.ini')],
                'Extensions' => [new TableBlock(['Extension', 'Version'], (array) ($data['extensions'] ?? []))],
                'Profiler elements' => [new TableBlock(['Name', 'Class', 'Priority'], (array) ($data['elements'] ?? []))],
            ]),
        ]);
    }

    protected function parameter(string $name, mixed $default = null): mixed
    {
        return $this->container->has($name) ? $this->container->get($name) : $default;
    }
}