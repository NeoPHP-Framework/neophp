<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller\Maker;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Controller\Exception\ControllerException;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

class ControllerMaker
{
    public const FORMAT_PHP = 'php';

    public const FORMAT_TWIG = 'twig';

    public const FORMAT_API = 'api';

    public const FORMAT_NONE = 'none';

    public const FORMATS = [self::FORMAT_PHP, self::FORMAT_TWIG, self::FORMAT_API, self::FORMAT_NONE];

    public function __construct(protected string $path, protected string $namespace, protected string $templatesPath)
    {
    }

    public function resolve(string $name): array
    {
        $name = trim(str_replace('/', '\\', $name), '\\');
        $namespace = trim($this->namespace, '\\');

        if (str_starts_with($name, $namespace . '\\')) {
            $name = substr($name, strlen($namespace) + 1);
        }

        if (str_ends_with($name, 'Controller') && $name !== 'Controller') {
            $name = substr($name, 0, -10);
        }

        if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new ControllerException('The name "{name}" is not a valid class name: use StudlyCase (Post, BlogPost, Admin\Post).', 0, null, ['name' => $name]);
        }

        $segments = explode('\\', $name);
        $snake = array_map(static fn (string $segment): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $segment)), $segments);

        return [
            'class' => $namespace . '\\' . $name . 'Controller',
            'file' => rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $name) . 'Controller.php',
            'route_path' => '/' . implode('/', array_map(static fn (string $part): string => str_replace('_', '-', $part), $snake)),
            'route_name' => implode('_', $snake) . '_index',
            'template' => implode('/', $snake) . '/index',
            'title' => end($segments),
        ];
    }

    public function make(string $name, string $format = self::FORMAT_PHP, bool $force = false): array
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new ControllerException('Unknown format "{format}": {formats}.', 0, null, ['format' => $format, 'formats' => implode(', ', self::FORMATS)]);
        }

        $target = $this->resolve($name);
        $template = match ($format) {
            self::FORMAT_PHP => $target['template'] . '.php',
            self::FORMAT_TWIG => $target['template'] . '.html.twig',
            default => null,
        };
        $templateFile = $template === null ? null : rtrim($this->templatesPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template);

        if (!$force) {
            foreach (array_filter([$target['file'], $templateFile]) as $existing) {
                if (is_file($existing)) {
                    throw new ControllerException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $existing]);
                }
            }
        }

        $this->write($target['file'], $this->controller($target, $format));

        if ($templateFile !== null) {
            $this->write($templateFile, $format === self::FORMAT_TWIG ? $this->twigTemplate($target) : $this->phpTemplate($target));
        }

        return [
            'class' => $target['class'],
            'file' => $target['file'],
            'template' => $templateFile,
            'route_path' => $target['route_path'],
            'route_name' => $target['route_name'],
        ];
    }

    protected function controller(array $target, string $format): string
    {
        $class = $target['class'];
        $position = (int) strrpos($class, '\\');
        $uses = [AbstractController::class, Response::class, Route::class];
        sort($uses);

        $body = match ($format) {
            self::FORMAT_API => "        return \$this->json([\n"
                . "            'message' => 'Welcome to " . $target['title'] . "Controller.',\n"
                . "            'path' => '" . $target['route_path'] . "',\n"
                . "        ]);\n",
            self::FORMAT_NONE => "        return new Response('" . $target['title'] . "Controller');\n",
            default => "        return \$this->render('" . ($format === self::FORMAT_TWIG ? $target['template'] . '.html.twig' : $target['template']) . "', [\n"
                . "            'controller_name' => '" . substr($class, $position + 1) . "',\n"
                . "        ]);\n",
        };

        return '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . substr($class, 0, $position) . ';' . "\n\n"
            . implode("\n", array_map(static fn (string $use): string => 'use ' . $use . ';', $uses)) . "\n\n"
            . 'class ' . substr($class, $position + 1) . " extends AbstractController\n"
            . "{\n"
            . "    #[Route('" . $target['route_path'] . "', name: '" . $target['route_name'] . "', methods: ['GET'])]\n"
            . "    public function index(): Response\n"
            . "    {\n"
            . $body
            . "    }\n"
            . "}\n";
    }

    protected function phpTemplate(array $target): string
    {
        $base = is_file(rtrim($this->templatesPath, '/\\') . DIRECTORY_SEPARATOR . 'base.php');
        $content = "<h1>Hello <?= \$this->e(\$controller_name) ?>!</h1>\n"
            . "<p>This page is rendered by <code>templates/" . $target['template'] . ".php</code>.</p>\n";

        if (!$base) {
            return $content;
        }

        return "<?php \$this->extend('base') ?>\n\n"
            . "<?php \$this->start('content') ?>\n"
            . $content
            . "<?php \$this->stop() ?>\n";
    }

    protected function twigTemplate(array $target): string
    {
        $base = is_file(rtrim($this->templatesPath, '/\\') . DIRECTORY_SEPARATOR . 'base.html.twig');
        $content = "<h1>Hello {{ controller_name }}!</h1>\n"
            . "<p>This page is rendered by <code>templates/" . $target['template'] . ".html.twig</code>.</p>\n";

        if (!$base) {
            return $content;
        }

        return "{% extends 'base.html.twig' %}\n\n"
            . "{% block title %}" . $target['title'] . "{% endblock %}\n\n"
            . "{% block body %}\n"
            . $content
            . "{% endblock %}\n";
    }

    protected function write(string $file, string $content): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ControllerException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $content) === false) {
            throw new ControllerException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }
}