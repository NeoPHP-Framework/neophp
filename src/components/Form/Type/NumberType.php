<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\FormView;

class NumberType extends AbstractType
{
    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'scale' => null, 'input' => 'number', 'html5' => false];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = $options['html5'] ? 'number' : 'text';

        if ($options['html5']) {
            $view->vars['attr'] += ['step' => $options['scale'] === null ? 'any' : ($options['scale'] === 0 ? '1' : '0.' . str_repeat('0', $options['scale'] - 1) . '1')];
        } else {
            $view->vars['attr'] += ['inputmode' => 'decimal'];
        }
    }

    public function transform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return '';
        }

        if (!is_numeric($data)) {
            throw new TransformationFailedException('Expected a number.');
        }

        return $options['scale'] === null ? (string) $data : number_format((float) $data, (int) $options['scale'], '.', '');
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return null;
        }

        if (!is_scalar($data)) {
            throw new TransformationFailedException('Expected a number.');
        }

        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $data);

        if (!is_numeric($value)) {
            throw new TransformationFailedException('Expected a number.');
        }

        if ($options['scale'] !== null) {
            $value = number_format(round((float) $value, (int) $options['scale']), (int) $options['scale'], '.', '');
        }

        return $options['input'] === 'string' ? $value : (float) $value;
    }
}