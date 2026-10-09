<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug;

use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\Debug\Cloner\VarCloner;
use NeoPHP\Package\Debug\Dumper\CliDumper;
use NeoPHP\Package\Debug\Dumper\HtmlDumper;
use NeoPHP\Package\Debug\Provider\DebugProvider;

#[Package(provider: DebugProvider::class, requires: [ExceptionManager::class])]
final class DebugManager implements DebugManagerInterface
{
    public const DEFAULT_OPTIONS = [
        'enabled' => true,
        'max_depth' => 10,
        'max_items' => 250,
        'max_string' => 1000,
        'expand_depth' => 1,
        'root_path' => null,
    ];

    protected static ?DebugManagerInterface $instance = null;

    protected array $options = self::DEFAULT_OPTIONS;

    protected VarCloner $cloner;

    protected HtmlDumper $htmlDumper;

    protected array $pending = [];

    protected bool $shutdownRegistered = false;

    protected mixed $stream = null;

    public function __construct(array $options = [])
    {
        $this->options = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($options, static::DEFAULT_OPTIONS));
        $this->cloner = new VarCloner((int) $this->options['max_depth'], (int) $this->options['max_items'], (int) $this->options['max_string']);
        $this->htmlDumper = new HtmlDumper((int) $this->options['expand_depth']);
    }

    public static function getInstance(): DebugManagerInterface
    {
        return static::$instance ??= new static(['enabled' => static::environmentEnabled()]);
    }

    public static function setInstance(?DebugManagerInterface $instance): void
    {
        static::$instance = $instance;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->options['enabled'];
    }

    public function setEnabled(bool $enabled): static
    {
        $this->options['enabled'] = $enabled;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function isCli(): bool
    {
        return in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    public function dump(mixed ...$values): void
    {
        $this->dumpFrom(null, $values);
    }

    public function dumpFrom(?string $location, array $values): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $label = $this->label($location);

        foreach ($values as $value) {
            $node = $this->cloner->cloneVar($value);

            if ($this->isCli()) {
                $this->write($this->cliDumper()->dump($node, $label));
            } else {
                $this->pending[] = [$node, $label];
                $this->registerShutdown();
            }

            $label = null;
        }
    }

    public function dd(mixed ...$values): never
    {
        $this->ddFrom(null, $values);
    }

    public function ddFrom(?string $location, array $values): never
    {
        if ($this->isEnabled()) {
            if ($this->isCli()) {
                $this->dumpFrom($location, $values);
            } else {
                $label = $this->label($location);

                foreach ($values as $value) {
                    $this->pending[] = [$this->cloner->cloneVar($value), $label];
                    $label = null;
                }

                if (!headers_sent()) {
                    header('Content-Type: text/html; charset=UTF-8');
                }

                $this->write($this->flush());
            }
        }

        if (!$this->isCli() && !headers_sent()) {
            http_response_code(500);
        }

        $this->terminate(1);
    }

    public function toHtml(mixed $value, ?string $label = null, ?int $maxDepth = null): string
    {
        $cloner = $maxDepth === null
            ? $this->cloner
            : new VarCloner(min($maxDepth, $this->cloner->getMaxDepth()), $this->cloner->getMaxItems(), $this->cloner->getMaxString());

        return $this->htmlDumper->dump($cloner->cloneVar($value), $label);
    }

    public function toText(mixed $value, ?string $label = null, bool $colors = false): string
    {
        return (new CliDumper($colors))->dump($this->cloner->cloneVar($value), $label);
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    public function flush(bool $html = true): string
    {
        $output = '';
        $dumper = $html ? $this->htmlDumper : new CliDumper(false);

        foreach ($this->pending as [$node, $label]) {
            $output .= $dumper->dump($node, $label);
        }

        $this->pending = [];

        return $output;
    }

    public function injectInto(Response $response): Response
    {
        if ($this->pending === []) {
            return $response;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));

        if ($type !== '' && !str_contains($type, 'html')) {
            return $response->setContent($this->flush(false) . $response->getContent());
        }

        $dumps = $this->flush();
        $content = $response->getContent();
        $injected = preg_replace('/<body\b[^>]*>/i', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $dumps), $content, 1, $count);

        return $response->setContent($count > 0 && is_string($injected) ? $injected : $dumps . $content);
    }

    public function getCloner(): VarCloner
    {
        return $this->cloner;
    }

    public function setStream(mixed $stream): static
    {
        $this->stream = $stream;

        return $this;
    }

    protected function label(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $root = $this->options['root_path'];

        if (is_string($root) && $root !== '' && str_starts_with($location, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
            return substr($location, strlen(rtrim($root, '/\\')) + 1);
        }

        return $location;
    }

    protected function cliDumper(): CliDumper
    {
        $stream = $this->stream ?? (defined('STDOUT') ? STDOUT : null);
        $colors = getenv('NO_COLOR') === false && is_resource($stream) && function_exists('stream_isatty') && @stream_isatty($stream);

        return new CliDumper($colors);
    }

    protected function write(string $output): void
    {
        $stream = $this->stream ?? ($this->isCli() && defined('STDOUT') ? STDOUT : null);

        if (is_resource($stream)) {
            fwrite($stream, $output);

            return;
        }

        echo $output;
    }

    protected function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            if ($this->pending !== []) {
                $this->write($this->flush());
            }
        });
    }

    protected function terminate(int $code): never
    {
        exit($code);
    }

    protected static function environmentEnabled(): bool
    {
        $debug = $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');

        if ($debug !== false && $debug !== '') {
            return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
        }

        $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return $environment !== 'prod';
    }
}