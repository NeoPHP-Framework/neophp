<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Http\Request\UploadedFile;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use SplFileInfo;

class FileValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, File::class);

        if ($this->isEmpty($value)) {
            return;
        }

        if ($value instanceof UploadedFile && $value->getError() !== UPLOAD_ERR_OK) {
            $context->addViolation($constraint->uploadErrorMessage, ['error' => $value->getErrorMessage()]);

            return;
        }

        [$path, $name] = $this->describe($value);

        if ($path === null || !is_file($path)) {
            $context->addViolation($constraint->notFoundMessage);

            return;
        }

        $size = (int) filesize($path);

        if ($constraint->maxSize !== null && $size > $constraint->maxSize) {
            $context->addViolation($constraint->maxSizeMessage, ['size' => self::formatSize($size), 'limit' => self::formatSize($constraint->maxSize)]);

            return;
        }

        if ($constraint->extensions !== []) {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($extension, $constraint->extensions, true)) {
                $context->addViolation($constraint->extensionsMessage, ['extension' => $extension, 'extensions' => implode(', ', $constraint->extensions)]);

                return;
            }
        }

        if ($constraint->mimeTypes !== []) {
            $type = self::guessMimeType($path, $value instanceof UploadedFile ? $value->getClientMimeType() : null);

            if (!self::matchesMimeType($type, $constraint->mimeTypes)) {
                $context->addViolation($constraint->mimeTypesMessage, ['type' => $type ?? 'unknown', 'types' => implode(', ', $constraint->mimeTypes)]);

                return;
            }
        }

        $this->validateFile($path, $value, $constraint, $context);
    }

    public static function guessMimeType(string $path, ?string $fallback = null): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = $finfo !== false ? finfo_file($finfo, $path) : false;

            if (is_string($type) && $type !== '') {
                return strtolower($type);
            }
        }

        if (function_exists('mime_content_type')) {
            $type = @mime_content_type($path);

            if (is_string($type) && $type !== '') {
                return strtolower($type);
            }
        }

        return $fallback === null ? null : strtolower($fallback);
    }

    public static function matchesMimeType(?string $type, array $allowed): bool
    {
        if ($type === null) {
            return false;
        }

        foreach ($allowed as $pattern) {
            if ($pattern === $type || (str_ends_with($pattern, '/*') && str_starts_with($type, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public static function formatSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1000000000 => round($bytes / 1000000000, 2) . ' GB',
            $bytes >= 1000000 => round($bytes / 1000000, 2) . ' MB',
            $bytes >= 1000 => round($bytes / 1000, 2) . ' kB',
            default => $bytes . ' bytes',
        };
    }

    protected function validateFile(string $path, mixed $value, File $constraint, ExecutionContext $context): void
    {
    }

    protected function describe(mixed $value): array
    {
        return match (true) {
            $value instanceof UploadedFile => [$value->getPath(), $value->getClientOriginalName()],
            $value instanceof SplFileInfo => [$value->getPathname(), $value->getFilename()],
            is_string($value) => [$value, basename($value)],
            default => [null, ''],
        };
    }
}