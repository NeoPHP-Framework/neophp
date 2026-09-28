<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Exception\ValidatorException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class File extends AbstractConstraint
{
    public const UNITS = ['k' => 1000, 'ki' => 1024, 'm' => 1000000, 'mi' => 1048576, 'g' => 1000000000, 'gi' => 1073741824];

    public string $notFoundMessage = 'The file could not be found.';

    public string $uploadErrorMessage = 'The file could not be uploaded: {{ error }}';

    public string $maxSizeMessage = 'The file is too large ({{ size }}). Allowed maximum size is {{ limit }}.';

    public string $mimeTypesMessage = 'The mime type of the file is invalid ({{ type }}). Allowed mime types are {{ types }}.';

    public string $extensionsMessage = 'The extension of the file is invalid ({{ extension }}). Allowed extensions are {{ extensions }}.';

    public ?int $maxSize = null;

    public array $mimeTypes = [];

    public array $extensions = [];

    public function __construct(
        int|string|null $maxSize = null,
        array|string $mimeTypes = [],
        array|string $extensions = [],
        ?string $maxSizeMessage = null,
        ?string $mimeTypesMessage = null,
        ?string $extensionsMessage = null,
        ?string $uploadErrorMessage = null,
        ?array $groups = null,
    ) {
        $this->maxSize = $maxSize === null ? null : self::parseSize($maxSize);
        $this->mimeTypes = array_values(array_map('strtolower', (array) $mimeTypes));
        $this->extensions = array_values(array_map(static fn (string $extension): string => strtolower(ltrim($extension, '.')), (array) $extensions));
        $this->maxSizeMessage = $maxSizeMessage ?? $this->maxSizeMessage;
        $this->mimeTypesMessage = $mimeTypesMessage ?? $this->mimeTypesMessage;
        $this->extensionsMessage = $extensionsMessage ?? $this->extensionsMessage;
        $this->uploadErrorMessage = $uploadErrorMessage ?? $this->uploadErrorMessage;
        $this->setGroups($groups);
    }

    public static function parseSize(int|string $size): int
    {
        if (is_int($size)) {
            return $size;
        }

        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmg]i?)?b?\s*$/i', $size, $m) !== 1) {
            throw new ValidatorException('The size "{size}" is not valid: use bytes, "500k", "2M", "1Gi"...', 0, null, ['size' => $size]);
        }

        return (int) round((float) $m[1] * (isset($m[2]) ? self::UNITS[strtolower($m[2])] : 1));
    }
}