<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class ImageValidator extends FileValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Image::class);

        parent::validate($value, $constraint, $context);
    }

    protected function validateFile(string $path, mixed $value, File $constraint, ExecutionContext $context): void
    {
        if (!$constraint instanceof Image || ($constraint->minWidth === null && $constraint->maxWidth === null && $constraint->minHeight === null && $constraint->maxHeight === null)) {
            return;
        }

        $size = @getimagesize($path);

        if ($size === false) {
            $context->addViolation($constraint->sizeNotDetectedMessage);

            return;
        }

        [$width, $height] = $size;

        if ($constraint->minWidth !== null && $width < $constraint->minWidth) {
            $context->addViolation($constraint->minWidthMessage, ['width' => $width, 'limit' => $constraint->minWidth]);
        } elseif ($constraint->maxWidth !== null && $width > $constraint->maxWidth) {
            $context->addViolation($constraint->maxWidthMessage, ['width' => $width, 'limit' => $constraint->maxWidth]);
        } elseif ($constraint->minHeight !== null && $height < $constraint->minHeight) {
            $context->addViolation($constraint->minHeightMessage, ['height' => $height, 'limit' => $constraint->minHeight]);
        } elseif ($constraint->maxHeight !== null && $height > $constraint->maxHeight) {
            $context->addViolation($constraint->maxHeightMessage, ['height' => $height, 'limit' => $constraint->maxHeight]);
        }
    }
}