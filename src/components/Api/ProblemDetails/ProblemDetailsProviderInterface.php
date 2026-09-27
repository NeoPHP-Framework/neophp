<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\ProblemDetails;

interface ProblemDetailsProviderInterface
{
    public function toProblemDetails(ProblemDetails $problem): ProblemDetails;
}