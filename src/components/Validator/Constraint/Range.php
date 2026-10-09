<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Exception\ValidatorException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Range extends AbstractConstraint
{
    public string $notInRangeMessage = 'This value should be between {{ min }} and {{ max }}.';

    public string $minMessage = 'This value should be {{ limit }} or more.';

    public string $maxMessage = 'This value should be {{ limit }} or less.';

    public string $invalidMessage = 'This value should be a valid number.';

    public function __construct(
        public int|float|string|null $min = null,
        public int|float|string|null $max = null,
        ?string $notInRangeMessage = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?string $invalidMessage = null,
        ?array $groups = null,
    ) {
        if ($min === null && $max === null) {
            throw new ValidatorException('The Range constraint needs "min" or "max".');
        }

        $this->notInRangeMessage = $notInRangeMessage ?? $this->notInRangeMessage;
        $this->minMessage = $minMessage ?? $this->minMessage;
        $this->maxMessage = $maxMessage ?? $this->maxMessage;
        $this->invalidMessage = $invalidMessage ?? $this->invalidMessage;
        $this->setGroups($groups);
    }
}