<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Helper\Controller;

use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\FormManagerInterface;
use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Type\FormType;

trait FormController
{
    abstract protected function get(string $id): mixed;

    protected function createForm(string $type, mixed $data = null, array $options = []): FormInterface
    {
        return $this->get(FormManagerInterface::class)->create($type, $data, $options);
    }

    protected function createFormBuilder(mixed $data = null, array $options = []): FormBuilder
    {
        return $this->get(FormManagerInterface::class)->createBuilder(FormType::class, $data, $options);
    }
}