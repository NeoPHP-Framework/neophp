<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Http\Request\UploadedFile;

class FileType extends AbstractType
{
    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'multiple' => false, 'trim' => false];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'file';
        $view->vars['value'] = '';
        $view->vars['multipart'] = true;
        $view->vars['multiple'] = (bool) $options['multiple'];

        if ($options['multiple']) {
            $view->vars['attr'] += ['multiple' => true];
        }
    }

    public function transform(mixed $data, array $options): mixed
    {
        return null;
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        if ($options['multiple']) {
            if ($data === null || $data === '') {
                return [];
            }

            if (!is_array($data)) {
                throw new TransformationFailedException('Expected a list of files.');
            }

            return array_values(array_filter($data, static fn (mixed $file): bool => $file instanceof UploadedFile));
        }

        if ($data === null || $data === '') {
            return null;
        }

        if (!$data instanceof UploadedFile) {
            throw new TransformationFailedException('Expected an uploaded file.');
        }

        return $data;
    }
}