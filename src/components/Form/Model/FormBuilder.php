<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Model;

use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\FormManagerInterface;
use NeoPHP\Component\Form\Type\ResolvedType;
use NeoPHP\Component\Form\Type\TextType;

class FormBuilder
{
    protected array $children = [];

    public function __construct(
        protected string $name,
        protected ResolvedType $type,
        protected array $options,
        protected FormManagerInterface $manager,
        protected mixed $data = null,
        protected bool $explicitData = false,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): ResolvedType
    {
        return $this->type;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    public function setOption(string $name, mixed $value): static
    {
        $this->options[$name] = $value;

        return $this;
    }

    public function getManager(): FormManagerInterface
    {
        return $this->manager;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;
        $this->explicitData = true;

        return $this;
    }

    public function hasExplicitData(): bool
    {
        return $this->explicitData;
    }

    public function add(string $name, ?string $type = null, array $options = []): static
    {
        if ($name === '' || preg_match('/[\[\]\s]/', $name) === 1) {
            throw new FormException('The field name "{name}" is not valid.', 0, null, ['name' => $name]);
        }

        $this->children[$name] = [$type ?? TextType::class, $options];

        return $this;
    }

    public function create(string $name, ?string $type = null, array $options = []): FormBuilder
    {
        return $this->manager->createNamedBuilder($name, $type ?? TextType::class, null, $options);
    }

    public function has(string $name): bool
    {
        return isset($this->children[$name]);
    }

    public function get(string $name): array
    {
        return $this->children[$name] ?? throw new FormException('The field "{name}" does not exist in the form "{form}".', 0, null, ['name' => $name, 'form' => $this->name]);
    }

    public function remove(string $name): static
    {
        unset($this->children[$name]);

        return $this;
    }

    public function all(): array
    {
        return $this->children;
    }

    public function getForm(): FormInterface
    {
        $form = new Form($this->name, $this->type, $this->options, $this->manager, $this->explicitData);

        foreach ($this->children as $name => $child) {
            $builder = $child instanceof FormBuilder ? $child : $this->manager->createNamedBuilder((string) $name, $child[0], null, $child[1]);
            $form->addChild($builder->getForm());
        }

        if ($this->explicitData || $form->isRoot()) {
            $form->setData($this->data);
        }

        return $form;
    }
}