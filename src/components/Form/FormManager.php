<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Contract\FormTypeInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Provider\FormProvider;
use NeoPHP\Component\Form\Renderer\FormRenderer;
use NeoPHP\Component\Form\Type\FormType;
use NeoPHP\Component\Form\Type\HiddenType;
use NeoPHP\Component\Form\Type\ResolvedType;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Validator\ValidatorManagerInterface;

#[Component(provider: FormProvider::class)]
final class FormManager implements FormManagerInterface
{
    public const DEFAULT_CONFIG = [
        'theme' => 'default',
        'csrf_protection' => true,
        'csrf_field_name' => '_token',
    ];

    protected ?ContainerManagerInterface $container = null;

    protected ?ValidatorManagerInterface $validator = null;

    protected ?CsrfManagerInterface $csrf = null;

    protected ?FormRenderer $renderer = null;

    protected array $config = self::DEFAULT_CONFIG;

    protected array $types = [];

    public function __construct(?ContainerManagerInterface $container = null, ?ValidatorManagerInterface $validator = null, ?CsrfManagerInterface $csrf = null, array $config = [])
    {
        $this->container = $container;
        $this->validator = $validator;
        $this->csrf = $csrf;
        $this->config = array_replace(static::DEFAULT_CONFIG, $config);
    }

    public function create(string $type = FormType::class, mixed $data = null, array $options = []): FormInterface
    {
        return $this->createBuilder($type, $data, $options)->getForm();
    }

    public function createNamed(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormInterface
    {
        return $this->createRootBuilder($name, $type, $data, $options)->getForm();
    }

    public function createBuilder(string $type = FormType::class, mixed $data = null, array $options = []): FormBuilder
    {
        return $this->createRootBuilder($this->getType($type)->getInnerType()->getBlockPrefix(), $type, $data, $options);
    }

    public function createNamedBuilder(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormBuilder
    {
        $resolved = $this->getType($type);
        $explicit = array_key_exists('data', $options);
        $resolvedOptions = $resolved->resolveOptions($options);
        $builder = new FormBuilder($name, $resolved, $resolvedOptions, $this, $explicit ? $resolvedOptions['data'] : $data, $explicit);
        $resolved->buildForm($builder, $resolvedOptions);

        return $builder;
    }

    public function getType(string $type): ResolvedType
    {
        if (isset($this->types[$type])) {
            return $this->types[$type];
        }

        $chain = [];
        $class = $type;

        while ($class !== null) {
            if (!class_exists($class) || !is_subclass_of($class, FormTypeInterface::class)) {
                throw new FormException('The form type "{type}" does not exist or does not implement {interface}.', 0, null, [
                    'type' => $class,
                    'interface' => FormTypeInterface::class,
                ]);
            }

            if (isset($chain[$class])) {
                throw new FormException('Circular parent detected for the form type "{type}".', 0, null, ['type' => $class]);
            }

            $instance = $this->instantiate($class);
            $chain[$class] = $instance;
            $class = $instance->getParent();
        }

        return $this->types[$type] = new ResolvedType(array_values(array_reverse($chain)));
    }

    public function getValidator(): ?ValidatorManagerInterface
    {
        return $this->validator;
    }

    public function getCsrf(): ?CsrfManagerInterface
    {
        return $this->csrf;
    }

    public function getRenderer(): FormRenderer
    {
        return $this->renderer ??= new FormRenderer($this->config['theme'] ?? 'default', $this->container);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    protected function createRootBuilder(string $name, string $type, mixed $data, array $options): FormBuilder
    {
        $builder = $this->createNamedBuilder($name, $type, $data, $options);
        $resolved = $builder->getOptions();

        if ($this->csrf !== null && ($this->config['csrf_protection'] ?? true) && ($resolved['csrf_protection'] ?? false) && ($resolved['compound'] ?? false)) {
            $field = (string) ($resolved['csrf_field_name'] ?? $this->config['csrf_field_name'] ?? '_token');
            $id = is_string($resolved['csrf_token_id'] ?? null) && $resolved['csrf_token_id'] !== '' ? $resolved['csrf_token_id'] : ($name !== '' ? $name : 'form');
            $builder->add($field, HiddenType::class, ['mapped' => false, 'data' => $this->csrf->getToken($id), 'label' => false]);
        }

        return $builder;
    }

    protected function instantiate(string $class): FormTypeInterface
    {
        if ($this->container !== null) {
            $instance = $this->container->has($class) ? $this->container->get($class) : $this->container->instantiate($class);
        } else {
            $instance = new $class();
        }

        if (!$instance instanceof FormTypeInterface) {
            throw new FormException('The form type "{type}" must implement {interface}.', 0, null, ['type' => $class, 'interface' => FormTypeInterface::class]);
        }

        return $instance;
    }
}