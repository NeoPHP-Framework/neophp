<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\FormView;

class IntegerType extends NumberType
{
    public function getParent(): ?string
    {
        return NumberType::class;
    }

    public function configureOptions(): array
    {
        return ['html5' => true, 'scale' => 0];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = $options['html5'] ? 'number' : 'text';
        $view->vars['attr'] += $options['html5'] ? ['step' => '1'] : ['inputmode' => 'numeric'];
    }

    public function transform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return '';
        }

        if (!is_numeric($data)) {
            throw new TransformationFailedException('Expected an integer.');
        }

        return (string) (int) $data;
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return null;
        }

        if (!is_scalar($data) || preg_match('/^[+-]?\d+$/', trim((string) $data)) !== 1) {
            throw new TransformationFailedException('Expected an integer.');
        }

        return $options['input'] === 'string' ? (string) (int) $data : (int) $data;
    }
}