<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Image extends File
{
    public string $sizeNotDetectedMessage = 'The size of the image could not be detected.';

    public string $minWidthMessage = 'The image width is too small ({{ width }}px). Minimum width expected is {{ limit }}px.';

    public string $maxWidthMessage = 'The image width is too big ({{ width }}px). Allowed maximum width is {{ limit }}px.';

    public string $minHeightMessage = 'The image height is too small ({{ height }}px). Minimum height expected is {{ limit }}px.';

    public string $maxHeightMessage = 'The image height is too big ({{ height }}px). Allowed maximum height is {{ limit }}px.';

    public function __construct(
        int|string|null $maxSize = null,
        array|string $mimeTypes = ['image/*'],
        array|string $extensions = [],
        public ?int $minWidth = null,
        public ?int $maxWidth = null,
        public ?int $minHeight = null,
        public ?int $maxHeight = null,
        ?string $maxSizeMessage = null,
        ?string $mimeTypesMessage = null,
        ?array $groups = null,
    ) {
        parent::__construct($maxSize, $mimeTypes, $extensions, $maxSizeMessage, $mimeTypesMessage ?? 'This file is not a valid image ({{ type }}).', null, null, $groups);
    }

    public function validatedBy(): string
    {
        return ImageValidator::class;
    }
}