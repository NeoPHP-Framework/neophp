<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class TimeType extends DateTimeType
{
    public const INPUT = 'time';

    public const FORMAT = 'H:i';

    public const FORMAT_WITH_SECONDS = 'H:i:s';

    public const PARSE_FORMATS = ['H:i:s', 'H:i'];

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'input' => 'datetime_immutable', 'with_seconds' => false, 'widget' => 'single_text', 'html5' => true, 'input_format' => 'H:i:s'];
    }
}