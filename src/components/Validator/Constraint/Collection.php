<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Collection extends AbstractConstraint
{
    public string $missingFieldsMessage = 'This field is missing.';

    public string $extraFieldsMessage = 'This field was not expected.';

    public string $typeMessage = 'This value should be a collection.';

    public function __construct(
        public array $fields,
        public bool $allowExtraFields = false,
        public bool $allowMissingFields = false,
        ?string $missingFieldsMessage = null,
        ?string $extraFieldsMessage = null,
        ?array $groups = null,
    ) {
        $this->missingFieldsMessage = $missingFieldsMessage ?? $this->missingFieldsMessage;
        $this->extraFieldsMessage = $extraFieldsMessage ?? $this->extraFieldsMessage;
        $this->setGroups($groups);
    }
}