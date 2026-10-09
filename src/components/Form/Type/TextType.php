<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use BackedEnum;
use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\FormView;
use Stringable;

class TextType extends AbstractType
{
    public const INPUT = 'text';

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = static::INPUT;
    }

    public function transform(mixed $data, array $options): mixed
    {
        if ($data === null) {
            return '';
        }

        if ($data instanceof BackedEnum) {
            return (string) $data->value;
        }

        if (is_scalar($data) || $data instanceof Stringable) {
            return (string) $data;
        }

        throw new TransformationFailedException('Expected a scalar value.');
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return null;
        }

        if (!is_scalar($data)) {
            throw new TransformationFailedException('Expected a string.');
        }

        return (string) $data;
    }
}