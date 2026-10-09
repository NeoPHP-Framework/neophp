<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Renderer;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Contract\ThemeInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Theme\Bootstrap5Theme;
use NeoPHP\Component\Form\Theme\DefaultTheme;
use NeoPHP\Component\Validator\ValidatorManager;
use NeoPHP\Package\Translation\TranslationManagerInterface;

class FormRenderer
{
    public const THEMES = [
        'default' => DefaultTheme::class,
        'bootstrap5' => Bootstrap5Theme::class,
        'bootstrap' => Bootstrap5Theme::class,
    ];

    protected array $themes = [];

    public function __construct(protected string $defaultTheme = 'default', protected ?ContainerManagerInterface $container = null)
    {
    }

    public function form(FormView|FormInterface $view, array $vars = []): string
    {
        $view = $this->view($view);

        return $this->start($view, $vars) . $this->widget($view) . $this->end($view, $vars);
    }

    public function start(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'start', $vars);
    }

    public function end(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'end', $vars);
    }

    public function row(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'row', $vars);
    }

    public function widget(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'widget', $vars);
    }

    public function label(FormView|FormInterface $view, string|false|null $label = null, array $vars = []): string
    {
        if ($label !== null) {
            $vars['label'] = $label;
        }

        return $this->renderBlock($this->view($view), 'label', $vars);
    }

    public function errors(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'errors', $vars);
    }

    public function help(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'help', $vars);
    }

    public function rest(FormView|FormInterface $view, array $vars = []): string
    {
        return $this->renderBlock($this->view($view), 'rest', $vars);
    }

    public function renderBlock(FormView $view, string $block, array $vars = []): string
    {
        $theme = $this->getTheme($view);
        $variables = array_replace($view->vars, $vars);

        if (isset($vars['attr']) && is_array($vars['attr'])) {
            $variables['attr'] = array_replace((array) ($view->vars['attr'] ?? []), $vars['attr']);
        }

        $variables = $this->translateVariables($variables);

        foreach (array_reverse((array) ($view->vars['block_prefixes'] ?? ['form'])) as $prefix) {
            $method = self::camelize($prefix . '_' . $block);

            if (method_exists($theme, $method)) {
                $html = $theme->$method($view, $variables, $this);

                if (in_array($block, ['widget', 'row'], true)) {
                    $view->setRendered();
                }

                return $html;
            }
        }

        throw new FormException('The theme "{theme}" cannot render the block "{block}" of the field "{field}".', 0, null, [
            'theme' => $theme::class,
            'block' => $block,
            'field' => $view->vars['full_name'] ?? '',
        ]);
    }

    public function translateVariables(array $variables): array
    {
        if ($this->container === null || !$this->container->has(TranslationManagerInterface::class)) {
            return $variables;
        }

        $translator = $this->container->get(TranslationManagerInterface::class);
        $domain = $variables['translation_domain'] ?? null;

        if ($domain === false) {
            return $variables;
        }

        $domain = is_string($domain) && $domain !== '' ? $domain : null;

        foreach (['label', 'help', 'placeholder'] as $name) {
            if (isset($variables[$name]) && is_string($variables[$name]) && $variables[$name] !== '') {
                $variables[$name] = $translator->translate($variables[$name], [], $domain);
            }
        }

        if (isset($variables['choices']) && is_array($variables['choices'])) {
            foreach ($variables['choices'] as $index => $choice) {
                if (is_array($choice) && isset($choice['label']) && is_string($choice['label']) && $choice['label'] !== '') {
                    $variables['choices'][$index]['label'] = $translator->translate($choice['label'], [], $domain);
                }
            }
        }

        if (isset($variables['errors']) && is_array($variables['errors'])) {
            $variables['errors'] = array_map(static fn (mixed $error): mixed => is_string($error) ? $translator->translate($error, [], ValidatorManager::TRANSLATION_DOMAIN) : $error, $variables['errors']);
        }

        return $variables;
    }

    public function getTheme(FormView $view): ThemeInterface
    {
        $name = $view->getRoot()->vars['theme'] ?? null;
        $name = is_string($name) && $name !== '' ? $name : $this->defaultTheme;

        return $this->themes[$name] ??= $this->createTheme($name);
    }

    protected function createTheme(string $name): ThemeInterface
    {
        $class = self::THEMES[strtolower($name)] ?? $name;

        if (!class_exists($class) || !is_subclass_of($class, ThemeInterface::class)) {
            throw new FormException('The form theme "{theme}" does not exist: use "default", "bootstrap5" or a class implementing {interface}.', 0, null, [
                'theme' => $name,
                'interface' => ThemeInterface::class,
            ]);
        }

        $theme = $this->container !== null ? $this->container->instantiate($class) : new $class();

        if (!$theme instanceof ThemeInterface) {
            throw new FormException('The form theme "{theme}" must implement {interface}.', 0, null, ['theme' => $class, 'interface' => ThemeInterface::class]);
        }

        return $theme;
    }

    protected function view(FormView|FormInterface $view): FormView
    {
        return $view instanceof FormInterface ? $view->getView() : $view;
    }

    public static function camelize(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name))));
    }
}