<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class DateType extends DateTimeType
{
    public const INPUT = 'date';

    public const FORMAT = 'Y-m-d';

    public const FORMAT_WITH_SECONDS = 'Y-m-d';

    public const PARSE_FORMATS = ['Y-m-d'];

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'input' => 'datetime_immutable', 'with_seconds' => false, 'widget' => 'single_text', 'html5' => true, 'input_format' => 'Y-m-d'];
    }
}