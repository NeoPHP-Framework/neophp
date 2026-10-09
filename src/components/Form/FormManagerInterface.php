<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form;

use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Renderer\FormRenderer;
use NeoPHP\Component\Form\Type\FormType;
use NeoPHP\Component\Form\Type\ResolvedType;
use NeoPHP\Component\Validator\ValidatorManagerInterface;

interface FormManagerInterface
{
    public function create(string $type = FormType::class, mixed $data = null, array $options = []): FormInterface;

    public function createNamed(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormInterface;

    public function createBuilder(string $type = FormType::class, mixed $data = null, array $options = []): FormBuilder;

    public function createNamedBuilder(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormBuilder;

    public function getType(string $type): ResolvedType;

    public function getValidator(): ?ValidatorManagerInterface;

    public function getCsrf(): ?CsrfManagerInterface;

    public function getRenderer(): FormRenderer;

    public function getConfig(): array;
}