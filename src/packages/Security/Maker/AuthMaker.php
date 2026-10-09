<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Maker;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\Package\Security\Exception\SecurityException;

class AuthMaker extends AbstractMaker
{
    public function __construct(string $path, string $namespace, protected string $templatesPath)
    {
        parent::__construct($path, $namespace);
    }

    public function make(string $name = 'SecurityController', bool $twig = false, bool $force = false): array
    {
        [$class, $file] = $this->resolve($name, 'Controller');
        $template = 'security/login.' . ($twig ? 'html.twig' : 'php');
        $templateFile = rtrim($this->templatesPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template);
        $layoutFile = rtrim($this->templatesPath, '/\\') . DIRECTORY_SEPARATOR . ($twig ? 'base.html.twig' : 'base.php');

        if (!$force) {
            foreach ([$file, $templateFile] as $existing) {
                if (is_file($existing)) {
                    throw new SecurityException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $existing]);
                }
            }
        }

        $controller = self::header(self::namespaceOf($class), [AbstractController::class, Response::class, Route::class, SecurityException::class])
            . 'class ' . self::shortName($class) . " extends AbstractController\n"
            . "{\n"
            . "    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]\n"
            . "    public function login(): Response\n"
            . "    {\n"
            . "        if (\$this->getUser() !== null) {\n"
            . "            return \$this->redirect('/');\n"
            . "        }\n\n"
            . "        return \$this->render('" . $template . "', [\n"
            . "            'last_username' => \$this->getLastUsername(),\n"
            . "            'error' => \$this->getLastAuthenticationError(),\n"
            . "        ]);\n"
            . "    }\n\n"
            . "    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]\n"
            . "    public function logout(): Response\n"
            . "    {\n"
            . "        throw new SecurityException('This route is handled by the \"logout\" option of the firewall in config/packages/security.yaml.');\n"
            . "    }\n"
            . "}\n";

        $this->write($file, $controller, $force);
        $this->write($templateFile, $twig ? self::twigTemplate() : self::phpTemplate(), $force);
        $layout = null;

        if (!is_file($layoutFile)) {
            $this->write($layoutFile, $twig ? self::twigLayout() : self::phpLayout(), false);
            $layout = $layoutFile;
        }

        return [$class, $file, $templateFile, $layout];
    }

    protected static function phpLayout(): string
    {
        return <<<'PHP'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title ?? $this->config('framework.app.name', 'NeoPHP')) ?></title>
    <link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
</head>
<body>
    <main>
        <?= $this->section('content') ?>
    </main>
</body>
</html>

PHP;
    }

    protected static function twigLayout(): string
    {
        return <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}{{ config('framework.app.name', 'NeoPHP') }}{% endblock %}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main>
        {% block body %}{% endblock %}
    </main>
</body>
</html>

TWIG;
    }

    protected static function phpTemplate(): string
    {
        return <<<'PHP'
<?php $this->extend('base') ?>

<?php $this->start('content') ?>
<h1>Sign in</h1>

<?php if ($error): ?>
    <p class="error"><?= $this->e($error) ?></p>
<?php endif ?>

<form method="post" action="/login">
    <label for="username">Email</label>
    <input type="text" id="username" name="_username" value="<?= $this->e($last_username) ?>" autocomplete="username" required autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="_password" autocomplete="current-password" required>

    <label><input type="checkbox" name="_remember_me" value="1"> Remember me</label>

    <input type="hidden" name="_csrf_token" value="<?= $this->e($this->csrf_token('authenticate')) ?>">

    <button type="submit">Sign in</button>
</form>
<?php $this->stop() ?>

PHP;
    }

    protected static function twigTemplate(): string
    {
        return <<<'TWIG'
{% extends 'base.html.twig' %}

{% block body %}
<h1>Sign in</h1>

{% if error %}
    <p class="error">{{ error }}</p>
{% endif %}

<form method="post" action="/login">
    <label for="username">Email</label>
    <input type="text" id="username" name="_username" value="{{ last_username }}" autocomplete="username" required autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="_password" autocomplete="current-password" required>

    <label><input type="checkbox" name="_remember_me" value="1"> Remember me</label>

    <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">

    <button type="submit">Sign in</button>
</form>
{% endblock %}

TWIG;
    }
}