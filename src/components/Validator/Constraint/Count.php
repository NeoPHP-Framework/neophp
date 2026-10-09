<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Exception\ValidatorException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Count extends AbstractConstraint
{
    public string $minMessage = 'This collection should contain {{ limit }} element(s) or more.';

    public string $maxMessage = 'This collection should contain {{ limit }} element(s) or less.';

    public string $exactMessage = 'This collection should contain exactly {{ limit }} element(s).';

    public string $typeMessage = 'This value should be a collection.';

    public function __construct(
        public ?int $exactly = null,
        public ?int $min = null,
        public ?int $max = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?string $exactMessage = null,
        ?array $groups = null,
    ) {
        if ($exactly !== null) {
            $this->min = $this->max = $exactly;
        }

        if ($this->min === null && $this->max === null) {
            throw new ValidatorException('The Count constraint needs "exactly", "min" or "max".');
        }

        $this->minMessage = $minMessage ?? $this->minMessage;
        $this->maxMessage = $maxMessage ?? $this->maxMessage;
        $this->exactMessage = $exactMessage ?? $this->exactMessage;
        $this->setGroups($groups);
    }
}