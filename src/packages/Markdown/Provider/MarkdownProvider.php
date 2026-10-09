<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\View\ViewManagerInterface;
use NeoPHP\Package\Markdown\MarkdownManager;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;

/**
 * @internal
 */
class MarkdownProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(MarkdownManagerInterface::class, static function (ContainerManagerInterface $container): MarkdownManagerInterface {
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $templatesPath = $container->has('kernel.templates_path') ? (string) $container->get('kernel.templates_path') : null;

            return new MarkdownManager($rootPath, $templatesPath, static fn (): ?ViewManagerInterface => $container->has(ViewManagerInterface::class) ? $container->get(ViewManagerInterface::class) : null);
        });

        $container->alias(MarkdownManager::class, MarkdownManagerInterface::class);
    }
}