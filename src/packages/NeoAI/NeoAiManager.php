<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\HttpClient\Contract\HttpClientInterface;
use NeoPHP\Component\Kernel\Contract\KernelInterface;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Package\NeoAI\Agent\Assistant;
use NeoPHP\Package\NeoAI\Agent\PromptBuilder;
use NeoPHP\Package\NeoAI\Contract\ProviderInterface;
use NeoPHP\Package\NeoAI\Exception\ConfigurationException;
use NeoPHP\Package\NeoAI\Exception\SecurityException;
use NeoPHP\Package\NeoAI\Llm\AnthropicProvider;
use NeoPHP\Package\NeoAI\Llm\GeminiProvider;
use NeoPHP\Package\NeoAI\Llm\OllamaProvider;
use NeoPHP\Package\NeoAI\Llm\OpenAiProvider;
use NeoPHP\Package\NeoAI\Patch\PatchApplier;
use NeoPHP\Package\NeoAI\Scan\Scanner;
use NeoPHP\Package\NeoAI\Security\Redactor;
use NeoPHP\Package\NeoAI\Security\RequestGuard;
use NeoPHP\Package\NeoAI\Security\Sandbox;
use NeoPHP\Package\NeoAI\Storage\ConversationStorage;
use NeoPHP\Package\NeoAI\Tool\ListFilesTool;
use NeoPHP\Package\NeoAI\Tool\ProfileTool;
use NeoPHP\Package\NeoAI\Tool\ProjectInfoTool;
use NeoPHP\Package\NeoAI\Tool\ProposePatchTool;
use NeoPHP\Package\NeoAI\Tool\ReadFileTool;
use NeoPHP\Package\NeoAI\Tool\SearchTool;
use NeoPHP\Package\NeoAI\Tool\ToolRunner;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use Throwable;

class NeoAiManager
{
    public const PROVIDERS = [
        'openai' => OpenAiProvider::class,
        'openai_compatible' => OpenAiProvider::class,
        'mistral' => OpenAiProvider::class,
        'groq' => OpenAiProvider::class,
        'openrouter' => OpenAiProvider::class,
        'lmstudio' => OpenAiProvider::class,
        'vllm' => OpenAiProvider::class,
        'anthropic' => AnthropicProvider::class,
        'ollama' => OllamaProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    public const DEFAULTS = [
        'enabled' => null,
        'debug' => false,
        'root' => '',
        'storage' => '',
        'allow_remote' => true,
        'default_connection' => 'default',
        'language' => 'en',
        'system_prompt' => '',
        'connections' => [],
        'providers' => [],
        'context' => [
            'max_files' => 40,
            'max_file_bytes' => 60000,
            'max_context_chars' => 120000,
            'max_tool_iterations' => 8,
            'max_tool_output' => 12000,
        ],
        'scan' => Scanner::DEFAULTS,
        'excluded_paths' => [],
        'redact_patterns' => [],
        'web' => [
            'enabled' => true,
            'path' => '/_neo_ai',
            'max_request_bytes' => 200000,
            'max_message_chars' => 8000,
        ],
    ];

    protected array $connections = [];

    protected ?Redactor $redactor = null;

    protected ?Sandbox $sandbox = null;

    public function __construct(protected array $config = [], protected ?ContainerInterface $container = null, protected ?HttpClientInterface $http = null)
    {
        $this->config = self::normalize($config);
    }

    public static function normalize(array $config): array
    {
        $normalized = array_replace(self::DEFAULTS, array_filter($config, static fn (mixed $value): bool => $value !== null));

        foreach (['context', 'scan', 'web'] as $section) {
            $normalized[$section] = array_replace(self::DEFAULTS[$section], array_filter((array) ($config[$section] ?? []), static fn (mixed $value): bool => $value !== null));
        }

        $debug = filter_var($normalized['debug'], FILTER_VALIDATE_BOOLEAN);
        $normalized['debug'] = $debug;
        $normalized['enabled'] = $config['enabled'] ?? null;
        $normalized['enabled'] = $normalized['enabled'] === null || $normalized['enabled'] === '' ? $debug : filter_var($normalized['enabled'], FILTER_VALIDATE_BOOLEAN);
        $normalized['allow_remote'] = filter_var($normalized['allow_remote'], FILTER_VALIDATE_BOOLEAN);
        $normalized['web']['enabled'] = filter_var($normalized['web']['enabled'], FILTER_VALIDATE_BOOLEAN);
        $normalized['excluded_paths'] = array_values(array_unique([...Sandbox::EXCLUDED, ...array_map('strval', (array) $normalized['excluded_paths'])]));
        $normalized['connections'] = array_filter((array) $normalized['connections'], 'is_array');
        $normalized['language'] = (string) ($normalized['language'] ?: 'en');

        return $normalized;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    public function isWebEnabled(): bool
    {
        return $this->isEnabled() && $this->config['debug'] && $this->config['web']['enabled'];
    }

    public function getWebPath(): string
    {
        return '/' . trim((string) $this->config['web']['path'], '/');
    }

    public function getRoot(): string
    {
        $root = (string) $this->config['root'];

        return $root !== '' ? $root : (string) getcwd();
    }

    public function getStoragePath(string $sub = ''): string
    {
        $storage = (string) $this->config['storage'];
        $storage = $storage !== '' ? $storage : $this->getRoot() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ai';

        return rtrim($storage, '/\\') . ($sub !== '' ? DIRECTORY_SEPARATOR . $sub : '');
    }

    public function getConnectionNames(): array
    {
        return array_map('strval', array_keys($this->config['connections']));
    }

    public function getDefaultConnection(): string
    {
        $default = (string) $this->config['default_connection'];

        if (isset($this->config['connections'][$default])) {
            return $default;
        }

        return (string) (array_key_first($this->config['connections']) ?? $default);
    }

    public function hasConnection(string $name): bool
    {
        return isset($this->config['connections'][$name]);
    }

    public function connection(?string $name = null): ProviderInterface
    {
        $name ??= $this->getDefaultConnection();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->config['connections'][$name] ?? null;

        if (!is_array($config)) {
            throw new ConfigurationException('The AI connection "{name}" is not configured. Available: {available}.', 0, null, [
                'name' => $name,
                'available' => implode(', ', $this->getConnectionNames()) ?: 'none (configure config/packages/neo_ai.yaml)',
            ]);
        }

        $type = strtolower(trim((string) ($config['provider'] ?? '')));
        $providers = [...self::PROVIDERS, ...array_change_key_case((array) $this->config['providers'], CASE_LOWER)];
        $class = $providers[$type] ?? null;

        if ($type === '' || !is_string($class) || !class_exists($class) || !is_subclass_of($class, ProviderInterface::class)) {
            throw new ConfigurationException('Unknown or invalid AI provider "{type}" for the connection "{name}". Built-in providers: {builtin}; custom ones must implement {interface} and be declared in "providers".', 0, null, [
                'type' => $type,
                'name' => $name,
                'builtin' => implode(', ', array_keys(self::PROVIDERS)),
                'interface' => ProviderInterface::class,
            ]);
        }

        $provider = new $class(['name' => $name, 'type' => $type, ...$config], $this->http);

        if (!$this->config['allow_remote'] && !$provider->isLocal()) {
            throw new SecurityException('The AI connection "{name}" ({type}) sends data to a remote server but "allow_remote" is false: use a local provider (ollama, lmstudio, localhost base_url).', 0, null, ['name' => $name, 'type' => $type]);
        }

        return $this->connections[$name] = $provider;
    }

    public function describe(?string $name = null): array
    {
        $name ??= $this->getDefaultConnection();
        $config = (array) ($this->config['connections'][$name] ?? []);

        try {
            $provider = $this->connection($name);

            return ['connection' => $name, 'provider' => $provider->getType(), 'model' => $provider->getModel(), 'remote' => !$provider->isLocal(), 'error' => null];
        } catch (Throwable $exception) {
            return ['connection' => $name, 'provider' => (string) ($config['provider'] ?? '?'), 'model' => (string) ($config['model'] ?? '?'), 'remote' => null, 'error' => $exception->getMessage()];
        }
    }

    public function redactor(): Redactor
    {
        if ($this->redactor !== null) {
            return $this->redactor;
        }

        $secrets = [];

        foreach ($this->config['connections'] as $connection) {
            $secrets[] = (string) ($connection['api_key'] ?? '');
        }

        return $this->redactor = new Redactor((array) $this->config['redact_patterns'], $secrets);
    }

    public function sandbox(): Sandbox
    {
        return $this->sandbox ??= new Sandbox($this->getRoot(), (array) $this->config['excluded_paths'], (int) $this->config['context']['max_file_bytes']);
    }

    public function prompts(): PromptBuilder
    {
        return new PromptBuilder((string) $this->config['language'], trim((string) $this->config['system_prompt']), basename($this->getRoot()));
    }

    public function tools(): ToolRunner
    {
        return new ToolRunner($this->sandbox(), $this->redactor(), [
            new ListFilesTool(),
            new ReadFileTool(),
            new SearchTool(),
            new ProjectInfoTool(fn (): array => $this->projectInfo()),
            new ProfileTool(fn (string $token): ?array => $this->loadProfile($token)),
            new ProposePatchTool(),
        ], (int) $this->config['context']['max_tool_output']);
    }

    public function assistant(?string $connection = null): Assistant
    {
        return new Assistant(
            $this->connection($connection),
            $this->tools(),
            $this->prompts(),
            $this->redactor(),
            max(1, (int) $this->config['context']['max_tool_iterations']),
            max(4000, (int) $this->config['context']['max_context_chars']),
        );
    }

    public function scanner(?string $connection = null, array $options = []): Scanner
    {
        return new Scanner($this->connection($connection), $this->sandbox(), $this->redactor(), $this->prompts(), [...(array) $this->config['scan'], ...array_filter($options, static fn (mixed $value): bool => $value !== null)]);
    }

    public function patches(): PatchApplier
    {
        return new PatchApplier($this->sandbox(), $this->getStoragePath('backups'));
    }

    public function conversations(): ConversationStorage
    {
        return new ConversationStorage($this->getStoragePath('conversations'));
    }

    public function guard(): RequestGuard
    {
        return new RequestGuard($this->getStoragePath('secret'));
    }

    public function projectInfo(): array
    {
        $info = [
            'project' => basename($this->getRoot()),
            'php' => PHP_VERSION,
            'framework' => 'NeoPHP',
            'framework_version' => 'unknown',
            'environment' => null,
            'debug' => $this->config['debug'],
            'features' => $this->features(),
            'top_directories' => [],
            'routes' => [],
            'config' => [],
        ];

        $container = $this->container;

        if ($container !== null && $container->has(KernelInterface::class)) {
            $kernel = $container->get(KernelInterface::class);
            $info['framework_version'] = $kernel->getVersion();
            $info['environment'] = $kernel->getEnvironment();
        }

        try {
            $info['top_directories'] = $this->sandbox()->listFiles('', '*', 60, false);
        } catch (Throwable) {
            $info['top_directories'] = [];
        }

        if ($container !== null && $container->has(RoutingInterface::class)) {
            foreach (array_slice($container->get(RoutingInterface::class)->getRoutes()->all(), 0, 150) as $route) {
                $controller = $route->getController();
                $info['routes'][] = [
                    'name' => $route->getName(),
                    'path' => $route->getPath(),
                    'methods' => $route->getMethods() ?: ['ANY'],
                    'controller' => is_string($controller) ? $controller : (is_array($controller) ? implode('::', array_map(static fn (mixed $part): string => is_object($part) ? $part::class : (string) $part, $controller)) : get_debug_type($controller)),
                ];
            }
        }

        if ($container !== null && $container->has(ConfigInterface::class)) {
            $info['config'] = $this->summarize((array) $container->get(ConfigInterface::class)->all(), 0);
        }

        return $info;
    }

    public function loadProfile(string $token): ?array
    {
        if ($this->container === null || !$this->container->bound(ProfileStorageInterface::class)) {
            return null;
        }

        try {
            $profile = $this->container->get(ProfileStorageInterface::class)->read($token);
        } catch (Throwable) {
            return null;
        }

        return $profile === null ? null : ['summary' => $profile->getSummary(), 'data' => $profile->getData()];
    }

    protected function features(): array
    {
        $features = [];
        $base = dirname(__DIR__, 2);

        foreach (['components', 'packages', 'process'] as $group) {
            foreach (glob($base . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
                $features[$group][] = basename($directory);
            }
        }

        return $features;
    }

    protected function summarize(array $config, int $depth): array
    {
        $summary = [];

        foreach ($config as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|secret|api_?key|token|credential|private|dsn|_url$|^url$/i', $key) === 1 && $value !== null && $value !== '' && !is_array($value)) {
                $summary[$key] = Redactor::MASK;
                continue;
            }

            if (is_array($value)) {
                $summary[$key] = $depth >= 3 ? sprintf('[%d item(s)]', count($value)) : $this->summarize($value, $depth + 1);
                continue;
            }

            $summary[$key] = is_string($value) && strlen($value) > 120 ? substr($value, 0, 117) . '...' : $value;
        }

        return $summary;
    }
}