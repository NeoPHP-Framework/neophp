<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Form\FormManager;
use NeoPHP\Component\Form\FormManagerInterface;
use NeoPHP\Component\Form\Renderer\FormRenderer;
use NeoPHP\Component\Validator\ValidatorManagerInterface;

/**
 * @internal
 */
class FormProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.form';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(FormManagerInterface::class, static function (ContainerManagerInterface $container): FormManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];

            return new FormManager(
                $container,
                $container->has(ValidatorManagerInterface::class) ? $container->get(ValidatorManagerInterface::class) : null,
                $container->has(CsrfManagerInterface::class) ? $container->get(CsrfManagerInterface::class) : null,
                $config,
            );
        });

        $container->singleton(FormRenderer::class, static fn (ContainerManagerInterface $container): FormRenderer => $container->get(FormManagerInterface::class)->getRenderer());
        $container->alias(FormManager::class, FormManagerInterface::class);
        $container->alias('form', FormManagerInterface::class);
    }
}