<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Contract;

use Composer\InstalledVersions;
use ErrorException;
use NeoPHP\Component\Config\Provider\ConfigProvider;
use NeoPHP\Component\Container\ContainerManager;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\ProviderInterface;
use NeoPHP\Component\Controller\ControllerManagerInterface;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\Event\ControllerEvent;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Component\Kernel\Event\TerminateEvent;
use NeoPHP\Component\Kernel\Exception\KernelException;
use NeoPHP\Component\Kernel\KernelManager;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Component\Kernel\Module\ModuleDiscovery;
use NeoPHP\Component\Kernel\Module\ModuleResolver;
use NeoPHP\Component\Logger\LoggerManagerInterface;
use NeoPHP\Component\Middleware\MiddlewareManagerInterface;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Package\Dotenv\DotenvManager;
use ReflectionObject;
use Throwable;

abstract class AbstractKernel implements KernelManagerInterface
{
    public const VERSION = 'dev';

    public const PACKAGE = 'neophp/framework';

    public const MODULES_FILE = 'config.php';

    protected string $rootPath;

    protected string $environment;

    protected bool $debug;

    protected ?ContainerManagerInterface $container = null;

    protected bool $booted = false;

    protected ?array $modules = null;

    protected array $disabledNamespaces = [];

    public function __construct(?string $environment = null, ?bool $debug = null, ?string $rootPath = null)
    {
        $this->rootPath = rtrim($rootPath ?? $this->detectRootPath(), '/\\');

        if ($environment !== null) {
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
        }

        $this->loadEnvironment();

        $this->environment = $environment ?? (string) ($this->env('APP_ENV') ?? 'dev');

        $envDebug = $this->env('APP_DEBUG');
        $this->debug = $debug ?? ($envDebug !== null ? filter_var($envDebug, FILTER_VALIDATE_BOOLEAN) : $this->environment !== 'prod');
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerErrorHandler();

        $container = $this->createContainer();
        $container->instance(KernelManagerInterface::class, $this);
        $container->instance(static::class, $this);
        $container->instance(ConfigProvider::PARAMETERS_ID, $this->getParameters());

        foreach ($this->getParameters() as $name => $value) {
            $container->instance($name, $value);
        }

        $providers = [];

        foreach ([...array_column($this->getModules(), 'provider'), ...$this->providers()] as $provider) {
            $provider = is_string($provider) ? new $provider() : $provider;

            if (!$provider instanceof ProviderInterface) {
                throw new KernelException('"{provider}" must implement {interface}.', 0, null, [
                    'provider' => get_debug_type($provider),
                    'interface' => ProviderInterface::class,
                ]);
            }

            $provider->register($container);
            $providers[] = $provider;
        }

        $this->container = $container;

        foreach ($providers as $provider) {
            $provider->boot($container);
        }

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        try {
            $this->boot();
            $this->getContainer()->instance(Request::class, $request);

            $event = $this->events()->dispatch(new RequestEvent($this, $request));

            if ($event->hasResponse()) {
                $response = $event->getResponse();
            } else {
                $middlewares = $this->getContainer()->get(MiddlewareManagerInterface::class);
                $response = $middlewares->handle($request, $middlewares->getGlobal(), fn (Request $request): Response => $this->dispatch($request));
            }
        } catch (Throwable $exception) {
            $response = $this->handleException($exception, $request);
        }

        try {
            $response = $this->filterResponse($request, $response);
        } catch (Throwable $exception) {
            $response = $this->handleException($exception, $request, false);
        }

        return $response->prepare($request);
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request)->send();

        $this->terminate($request, $response);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $this->events()->dispatch(new TerminateEvent($this, $request, $response));
        } catch (Throwable $exception) {
            $this->logException($exception, $request, 500);
        }
    }

    public function getContainer(): ContainerManagerInterface
    {
        if ($this->container === null) {
            throw new KernelException('The kernel is not booted: call boot() first.');
        }

        return $this->container;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    public function getConfigPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'config';
    }

    public function getPublicPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'public';
    }

    public function getTemplatesPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'templates';
    }

    public function getCachePath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getVersion(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(static::PACKAGE)) {
            return (string) InstalledVersions::getPrettyVersion(static::PACKAGE);
        }

        return static::VERSION;
    }

    public function getParameters(): array
    {
        return [
            'kernel.root_path' => $this->rootPath,
            'kernel.config_path' => $this->getConfigPath(),
            'kernel.public_path' => $this->getPublicPath(),
            'kernel.templates_path' => $this->getTemplatesPath(),
            'kernel.cache_path' => $this->getCachePath(),
            'kernel.environment' => $this->environment,
            'kernel.debug' => $this->debug,
            'kernel.version' => $this->getVersion(),
        ];
    }

    public function getModules(): array
    {
        $this->loadModules();

        return (array) $this->modules;
    }

    public function isEnabled(string $class): bool
    {
        $class = ltrim($class, '\\');

        foreach ($this->getModules() as $module) {
            if ($module['namespace'] !== '' && str_starts_with($class, $module['namespace'] . '\\')) {
                return true;
            }
        }

        foreach ($this->disabledNamespaces as $namespace) {
            if ($namespace !== '' && str_starts_with($class, $namespace . '\\')) {
                return false;
            }
        }

        return preg_match('/^NeoPHP\\\\(Component|Package|Process)\\\\/', $class) !== 1;
    }

    protected function dispatch(Request $request): Response
    {
        $container = $this->getContainer();
        $container->instance(Request::class, $request);

        $match = $container->get(RoutingManagerInterface::class)->match($request->getMethod(), $request->getPath());

        $request->attributes->add($match->parameters);
        $request->attributes->set('_route', $match->getName());
        $request->attributes->set('_controller', $match->getController());

        $middlewares = $container->get(MiddlewareManagerInterface::class);
        $routeMiddlewares = $middlewares->forController($match->getController(), (array) $match->route->getOption('middlewares', []));

        return $middlewares->handle($request, $routeMiddlewares, function (Request $request) use ($container, $match): Response {
            $container->instance(Request::class, $request);
            $event = $this->events()->dispatch(new ControllerEvent($this, $request, $match->getController(), $match->parameters));

            return $container->get(ControllerManagerInterface::class)->dispatch($event->getController(), $request, $event->getParameters());
        });
    }

    protected function filterResponse(Request $request, Response $response): Response
    {
        if ($this->container === null) {
            return $response;
        }

        return $this->events()->dispatch(new ResponseEvent($this, $request, $response))->getResponse();
    }

    protected function events(): EventManagerInterface
    {
        return $this->getContainer()->get(EventManagerInterface::class);
    }

    protected function handleException(Throwable $exception, Request $request, bool $dispatch = true): Response
    {
        if ($dispatch && $this->container !== null) {
            try {
                $event = $this->events()->dispatch(new ExceptionEvent($this, $request, $exception));
                $exception = $event->getThrowable();

                if ($event->hasResponse()) {
                    $response = $event->getResponse();
                    $this->logException($exception, $request, $response->getStatusCode());

                    return $response;
                }
            } catch (Throwable) {
            }
        }

        $manager = $this->container !== null && $this->container->has(ExceptionManager::class)
            ? $this->container->get(ExceptionManager::class)
            : new ExceptionManager($this->debug);

        $status = $manager->getStatusCode($exception);
        $headers = $manager->getHeaders($exception);

        $this->logException($exception, $request, $status);

        if ($request->wantsJson() || $request->isJson()) {
            return new JsonResponse($manager->renderJson($exception), $status, $headers);
        }

        return new Response($manager->render($exception), $status, $headers);
    }

    protected function logException(Throwable $exception, Request $request, int $status): void
    {
        if ($status < 500 || $this->container === null || !$this->container->has(LoggerManagerInterface::class)) {
            return;
        }

        try {
            $logger = $this->container->get(LoggerManagerInterface::class);

            if ($logger->hasChannel('framework')) {
                $logger->channel('framework')->critical('Uncaught {class}: {message} ({method} {path})', [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'method' => $request->getMethod(),
                    'path' => $request->getPath(),
                    'exception' => $exception,
                ]);
            }
        } catch (Throwable) {
        }
    }

    protected function providers(): iterable
    {
        return [];
    }

    protected function loadModules(): void
    {
        if ($this->modules !== null) {
            return;
        }

        $file = $this->getConfigPath() . DIRECTORY_SEPARATOR . static::MODULES_FILE;

        $builder = function () use ($file): array {
            $discovery = new ModuleDiscovery(dirname(__DIR__, 3), $this->rootPath);
            $modules = $discovery->discover();
            $config = is_file($file) ? $this->readModules($file) : [];
            $data = (new ModuleResolver($this->environment))->resolve($modules, $config, $file, [KernelManager::class]);
            $resources = $discovery->getResources();

            foreach ([$this->getConfigPath(), $file] as $path) {
                $resources[$path] = file_exists($path) ? (int) filemtime($path) : ResourceCache::MISSING;
            }

            return [$data, $resources];
        };

        $cache = $this->getCachePath() . DIRECTORY_SEPARATOR . 'kernel' . DIRECTORY_SEPARATOR . 'modules.' . $this->environment . '.php';
        $data = (new ResourceCache($cache, $this->debug))->load($builder);

        $this->modules = (array) ($data['modules'] ?? []);
        $this->disabledNamespaces = (array) ($data['disabled'] ?? []);
    }

    protected function readModules(string $file): array
    {
        $modules = (static fn (string $__file): mixed => require $__file)($file);

        if (!is_array($modules)) {
            throw new KernelException('"{file}" must return an array of module class => enabled.', 0, null, ['file' => $file]);
        }

        return $modules;
    }

    protected function createContainer(): ContainerManagerInterface
    {
        return new ContainerManager();
    }

    protected function registerErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity) || in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function loadEnvironment(): void
    {
        (new DotenvManager())->loadEnv($this->rootPath);
    }

    protected function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return $value === false ? null : (string) $value;
    }

    protected function detectRootPath(): string
    {
        $file = (new ReflectionObject($this))->getFileName();
        $directory = $file !== false ? dirname($file) : (string) getcwd();

        while (true) {
            $composer = $directory . DIRECTORY_SEPARATOR . 'composer.json';

            if (is_file($composer)) {
                $data = json_decode((string) file_get_contents($composer), true);

                if (!is_array($data) || ($data['name'] ?? null) !== 'neophp/framework') {
                    return $directory;
                }
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return (string) getcwd();
            }

            $directory = $parent;
        }
    }
}