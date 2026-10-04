<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Contract;

use Composer\InstalledVersions;
use ErrorException;
use NeoPHP\Component\Api\Provider\ApiProvider;
use NeoPHP\Component\Asset\Provider\AssetProvider;
use NeoPHP\Component\Cache\Provider\CacheProvider;
use NeoPHP\Component\Config\Provider\ConfigProvider;
use NeoPHP\Component\Container\ContainerManager;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Container\Contract\ProviderInterface;
use NeoPHP\Component\Container\Provider\ContainerProvider;
use NeoPHP\Component\Controller\Contract\ControllerResolverInterface;
use NeoPHP\Component\Controller\Provider\ControllerProvider;
use NeoPHP\Component\Cookie\Provider\CookieProvider;
use NeoPHP\Component\Csrf\Provider\CsrfProvider;
use NeoPHP\Component\Database\Provider\DatabaseProvider;
use NeoPHP\Component\Event\Contract\EventDispatcherInterface;
use NeoPHP\Component\Event\Provider\EventProvider;
use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Component\Exception\Provider\ExceptionProvider;
use NeoPHP\Component\Flash\Provider\FlashProvider;
use NeoPHP\Component\Form\Provider\FormProvider;
use NeoPHP\Component\Http\Provider\HttpProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Event\ControllerEvent;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Component\Kernel\Event\TerminateEvent;
use NeoPHP\Component\Kernel\Exception\KernelException;
use NeoPHP\Component\Kernel\Provider\KernelProvider;
use NeoPHP\Component\Logger\Contract\LoggerManagerInterface;
use NeoPHP\Component\Logger\Provider\LoggerProvider;
use NeoPHP\Component\Mailer\Provider\MailerProvider;
use NeoPHP\Component\Middleware\Contract\MiddlewareManagerInterface;
use NeoPHP\Component\Middleware\Provider\MiddlewareProvider;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Component\Routing\Provider\RoutingProvider;
use NeoPHP\Component\Serializer\Provider\SerializerProvider;
use NeoPHP\Component\Service\Provider\ServiceProvider;
use NeoPHP\Component\Session\Provider\SessionProvider;
use NeoPHP\Component\Upload\Provider\UploadProvider;
use NeoPHP\Component\Validator\Provider\ValidatorProvider;
use NeoPHP\Component\View\Provider\ViewProvider;
use NeoPHP\Component\HttpClient\Provider\HttpClientProvider;
use NeoPHP\Package\Debug\Provider\DebugProvider;
use NeoPHP\Package\Dotenv\DotenvManager;
use NeoPHP\Package\Dotenv\Provider\DotenvProvider;
use NeoPHP\Package\Markdown\Provider\MarkdownProvider;
use NeoPHP\Package\NeoAI\Provider\NeoAiProvider;
use NeoPHP\Package\Orm\Provider\OrmProvider;
use NeoPHP\Package\Queue\Provider\QueueProvider;
use NeoPHP\Package\Scheduler\Provider\SchedulerProvider;
use NeoPHP\Package\Security\Provider\SecurityProvider;
use NeoPHP\Package\Tailwind\Provider\TailwindProvider;
use NeoPHP\Package\Translation\Provider\TranslationProvider;
use NeoPHP\Package\WebProfiler\Provider\WebProfilerProvider;
use NeoPHP\Package\Yaml\Provider\YamlProvider;
use NeoPHP\Process\Console\Provider\ConsoleProvider;
use NeoPHP\Process\Installer\Provider\InstallerProvider;
use ReflectionObject;
use Throwable;

abstract class AbstractKernel implements KernelInterface
{
    public const VERSION = 'dev';

    public const PACKAGE = 'neophp/framework';

    protected string $rootPath;

    protected string $environment;

    protected bool $debug;

    protected ?ContainerInterface $container = null;

    protected bool $booted = false;

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
        $container->instance(KernelInterface::class, $this);
        $container->instance(static::class, $this);
        $container->instance(ConfigProvider::PARAMETERS_ID, $this->getParameters());

        foreach ($this->getParameters() as $name => $value) {
            $container->instance($name, $value);
        }

        $providers = [];

        foreach ([...$this->coreProviders(), ...$this->providers()] as $provider) {
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

    public function getContainer(): ContainerInterface
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

    protected function dispatch(Request $request): Response
    {
        $container = $this->getContainer();
        $container->instance(Request::class, $request);

        $match = $container->get(RoutingInterface::class)->match($request->getMethod(), $request->getPath());

        $request->attributes->add($match->parameters);
        $request->attributes->set('_route', $match->getName());
        $request->attributes->set('_controller', $match->getController());

        $middlewares = $container->get(MiddlewareManagerInterface::class);
        $routeMiddlewares = $middlewares->forController($match->getController(), (array) $match->route->getOption('middlewares', []));

        return $middlewares->handle($request, $routeMiddlewares, function (Request $request) use ($container, $match): Response {
            $container->instance(Request::class, $request);
            $event = $this->events()->dispatch(new ControllerEvent($this, $request, $match->getController(), $match->parameters));

            return $container->get(ControllerResolverInterface::class)->dispatch($event->getController(), $request, $event->getParameters());
        });
    }

    protected function filterResponse(Request $request, Response $response): Response
    {
        if ($this->container === null) {
            return $response;
        }

        return $this->events()->dispatch(new ResponseEvent($this, $request, $response))->getResponse();
    }

    protected function events(): EventDispatcherInterface
    {
        return $this->getContainer()->get(EventDispatcherInterface::class);
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

    protected function coreProviders(): array
    {
        return [
            ContainerProvider::class,
            KernelProvider::class,
            ExceptionProvider::class,
            YamlProvider::class,
            DotenvProvider::class,
            ConfigProvider::class,
            LoggerProvider::class,
            HttpProvider::class,
            EventProvider::class,
            MiddlewareProvider::class,
            RoutingProvider::class,
            CookieProvider::class,
            SessionProvider::class,
            FlashProvider::class,
            TranslationProvider::class,
            ValidatorProvider::class,
            DatabaseProvider::class,
            SerializerProvider::class,
            ApiProvider::class,
            OrmProvider::class,
            CacheProvider::class,
            CsrfProvider::class,
            FormProvider::class,
            MailerProvider::class,
            HttpClientProvider::class,
            QueueProvider::class,
            SchedulerProvider::class,
            SecurityProvider::class,
            DebugProvider::class,
            WebProfilerProvider::class,
            AssetProvider::class,
            UploadProvider::class,
            TailwindProvider::class,
            MarkdownProvider::class,
            NeoAiProvider::class,
            ViewProvider::class,
            ControllerProvider::class,
            InstallerProvider::class,
            ConsoleProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function createContainer(): ContainerInterface
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