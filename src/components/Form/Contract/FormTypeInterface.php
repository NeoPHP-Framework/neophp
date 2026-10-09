<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Contract;

use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Model\FormView;

interface FormTypeInterface
{
    public function getParent(): ?string;

    public function getBlockPrefix(): string;

    public function configureOptions(): array;

    public function buildForm(FormBuilder $builder, array $options): void;

    public function buildView(FormView $view, FormInterface $form, array $options): void;

    public function transform(mixed $data, array $options): mixed;

    public function reverseTransform(mixed $data, array $options): mixed;

    public function mapDataToForms(mixed $data, array $forms, array $options, FormInterface $form): void;

    public function mapFormsToData(array $forms, mixed $data, array $options, FormInterface $form): mixed;

    public function preSubmit(FormInterface $form, mixed $data, array $options): mixed;
}