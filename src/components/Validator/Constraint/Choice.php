<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Choice extends AbstractConstraint
{
    public string $message = 'The value you selected is not a valid choice.';

    public string $multipleMessage = 'One or more of the given values is invalid.';

    public string $minMessage = 'You must select at least {{ limit }} choice(s).';

    public string $maxMessage = 'You must select at most {{ limit }} choice(s).';

    public function __construct(
        public array $choices = [],
        public string|array|null $callback = null,
        public bool $multiple = false,
        public ?int $min = null,
        public ?int $max = null,
        public bool $strict = true,
        ?string $message = null,
        ?string $multipleMessage = null,
        ?array $groups = null,
    ) {
        $this->message = $message ?? $this->message;
        $this->multipleMessage = $multipleMessage ?? $this->multipleMessage;
        $this->setGroups($groups);
    }
}