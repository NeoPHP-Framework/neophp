<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Exception;

use NeoPHP\Component\Validator\Violation\ViolationList;

class ValidationFailedException extends ValidatorException
{
    protected int $statusCode = 422;

    protected ViolationList $violations;

    public static function create(ViolationList $violations): static
    {
        $exception = new static('The data is not valid: {count} violation(s).', 0, null, [
            'count' => count($violations),
            'violations' => $violations->toArray(),
        ]);
        $exception->violations = $violations;

        return $exception;
    }

    public function getViolations(): ViolationList
    {
        return $this->violations ?? new ViolationList();
    }
}