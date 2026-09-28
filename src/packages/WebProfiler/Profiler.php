<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfilerElementInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarAssetInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Exception\InvalidElementException;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch;
use NeoPHP\Package\WebProfiler\Util\ValueExporter;
use Throwable;

class Profiler
{
    public const DEFAULTS = [
        'enabled' => null,
        'toolbar' => true,
        'path' => '/_profiler',
        'toolbar_path' => '/_wdt',
        'storage' => null,
        'max_profiles' => 200,
        'lifetime' => 86400,
        'excluded_paths' => ['^/(favicon\.ico|robots\.txt|build|builds|assets)(/|$)'],
        'panels' => [],
        'block_renderers' => [],
        'ajax_limit' => 50,
        'allowed_ips' => Request::LOCAL_NETWORKS,
    ];

    public const DATA_DEPTH = 12;

    protected ?array $elements = null;

    protected array $extra = [];

    protected ?Throwable $exception = null;

    protected string $basePath = '';

    public function __construct(
        protected ContainerInterface $container,
        protected ProfileStorageInterface $storage,
        protected Stopwatch $stopwatch,
        protected array $config = [],
        protected array $discovered = [],
    ) {
        $this->config = [...self::DEFAULTS, ...$config];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    public function enable(): static
    {
        $this->config['enabled'] = true;

        return $this;
    }

    public function disable(): static
    {
        $this->config['enabled'] = false;

        return $this;
    }

    public function isToolbarEnabled(): bool
    {
        return $this->isEnabled() && (bool) $this->config['toolbar'];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getStorage(): ProfileStorageInterface
    {
        return $this->storage;
    }

    public function getStopwatch(): Stopwatch
    {
        return $this->stopwatch;
    }

    public function getPath(): string
    {
        return '/' . trim((string) $this->config['path'], '/');
    }

    public function getToolbarPath(): string
    {
        return '/' . trim((string) $this->config['toolbar_path'], '/');
    }

    public function setBasePath(string $basePath): static
    {
        $this->basePath = rtrim($basePath, '/');

        return $this;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getPublicPath(): string
    {
        return $this->basePath . $this->getPath();
    }

    public function getProfileUrl(string $token, ?string $panel = null): string
    {
        return $this->getPublicPath() . '/' . rawurlencode($token) . ($panel !== null ? '?panel=' . rawurlencode($panel) : '');
    }

    public function getToolbarUrl(string $token): string
    {
        return $this->basePath . $this->getToolbarPath() . '/' . rawurlencode($token);
    }

    public function isAllowed(Request $request): bool
    {
        return $request->isClientIpIn(array_values((array) $this->config['allowed_ips']));
    }

    public function isExcluded(Request $request): bool
    {
        $path = $request->getPath();

        foreach ([$this->getPath(), $this->getToolbarPath()] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        foreach ((array) $this->config['excluded_paths'] as $pattern) {
            if (@preg_match('#' . str_replace('#', '\#', (string) $pattern) . '#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    public function addElement(ProfilerElementInterface $element, ?int $priority = null): static
    {
        $this->extra[] = [$element, $priority];
        $this->elements = null;

        return $this;
    }

    public function getElements(): array
    {
        if ($this->elements !== null) {
            return $this->elements;
        }

        $entries = [];

        foreach ([...$this->discovered, ...array_fill_keys((array) $this->config['panels'], null)] as $class => $priority) {
            $element = $this->container->get((string) $class);

            if (!$element instanceof ProfilerElementInterface) {
                throw new InvalidElementException('The profiler element "{class}" must implement {interface}.', 0, null, [
                    'class' => $class,
                    'interface' => ProfilerElementInterface::class,
                ]);
            }

            $entries[] = [$element, $priority];
        }

        $elements = [];
        $priorities = [];

        foreach ([...$entries, ...$this->extra] as [$element, $priority]) {
            $name = $element->getName();

            if (isset($elements[$name]) && $elements[$name]::class !== $element::class) {
                throw new InvalidElementException('Two profiler elements use the name "{name}": {first} and {second}.', 0, null, [
                    'name' => $name,
                    'first' => $elements[$name]::class,
                    'second' => $element::class,
                ]);
            }

            $elements[$name] = $element;
            $priorities[$name] = $priority ?? $element->getPriority();
        }

        uksort($elements, static fn (string $a, string $b): int => [$priorities[$b], $a] <=> [$priorities[$a], $b]);

        return $this->elements = $elements;
    }

    public function getElement(string $name): ?ProfilerElementInterface
    {
        return $this->getElements()[$name] ?? null;
    }

    public function setException(?Throwable $exception): static
    {
        $this->exception = $exception;

        return $this;
    }

    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): Profile
    {
        $exception ??= $this->exception;
        $route = $request->attributes->get('_route');
        $profile = new Profile(
            $this->generateToken(),
            (string) ($request->getClientIp() ?? ''),
            $request->getMethod(),
            $request->getUri(),
            $response->getStatusCode(),
            time(),
            round($this->stopwatch->getElapsed(), 2),
            memory_get_peak_usage(true),
            is_scalar($route) ? (string) $route : null,
            $response->headers->get('Content-Type'),
        );

        foreach ($this->getElements() as $name => $element) {
            try {
                $data = ValueExporter::export($element->collect($request, $response, $exception), self::DATA_DEPTH);
                $profile->setData($name, is_array($data) ? $data : ['value' => $data]);
            } catch (Throwable $error) {
                $profile->setData($name, ['_error' => sprintf('%s: %s', $error::class, $error->getMessage())]);
            }
        }

        $profile->setDuration(round($this->stopwatch->getElapsed(), 2));
        $this->exception = null;

        return $profile;
    }

    public function save(Profile $profile): void
    {
        $this->storage->write($profile);
    }

    public function load(string $token): ?Profile
    {
        return $this->storage->read($token);
    }

    public function find(int $limit = 50, array $filters = []): array
    {
        return $this->storage->find($limit, $filters);
    }

    public function getToolbarItems(Profile $profile): array
    {
        $items = [];

        foreach ($this->getElements() as $name => $element) {
            if (!$element instanceof ToolbarInterface || isset($profile->getData($name)['_error'])) {
                continue;
            }

            try {
                $item = $element->getToolbarItem($profile, $profile->getData($name));
            } catch (Throwable) {
                continue;
            }

            if ($item === null) {
                continue;
            }

            if ($item->getPanel() === null && $item->isLinked() && $element instanceof ProfilerInterface) {
                $item->setPanel($name);
            }

            $items[$name] = $item;
        }

        return $items;
    }

    public function getToolbarAssets(Profile $profile): array
    {
        $assets = ['css' => [], 'js' => []];

        foreach ($this->getElements() as $name => $element) {
            if (!$element instanceof ToolbarAssetInterface || isset($profile->getData($name)['_error'])) {
                continue;
            }

            try {
                $elementAssets = $element->getToolbarAssets($profile, $profile->getData($name));
            } catch (Throwable) {
                continue;
            }

            foreach (['css', 'js'] as $type) {
                $content = trim((string) ($elementAssets[$type] ?? ''));

                if ($content !== '') {
                    $assets[$type][$name] = $content;
                }
            }
        }

        return $assets;
    }

    public function getPanels(Profile $profile): array
    {
        $panels = [];

        foreach ($this->getElements() as $name => $element) {
            if (!$element instanceof ProfilerInterface) {
                continue;
            }

            $data = $profile->getData($name);

            try {
                $panel = isset($data['_error']) ? null : $element->getPanel($profile, $data);
            } catch (Throwable $error) {
                $data['_error'] = sprintf('%s: %s', $error::class, $error->getMessage());
                $panel = null;
            }

            if (isset($data['_error'])) {
                $panels[$name] = Panel::error($name, (string) $data['_error']);
                continue;
            }

            if ($panel !== null) {
                $panels[$name] = $panel;
            }
        }

        return $panels;
    }

    protected function generateToken(): string
    {
        return bin2hex(random_bytes(6));
    }
}