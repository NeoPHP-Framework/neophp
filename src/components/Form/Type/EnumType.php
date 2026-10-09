<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Exception\FormException;
use UnitEnum;

class EnumType extends ChoiceType
{
    public function getParent(): ?string
    {
        return ChoiceType::class;
    }

    public function configureOptions(): array
    {
        return ['class' => null];
    }

    protected function loadChoices(array $options): array
    {
        $class = $options['class'] ?? null;

        if (!is_string($class) || !enum_exists($class)) {
            throw new FormException('The EnumType needs the "class" option with an enum class, "{class}" given.', 0, null, ['class' => get_debug_type($class)]);
        }

        if ($options['choices'] !== []) {
            return array_map(static fn (UnitEnum $case): array => [$case, null], array_values((array) $options['choices']));
        }

        $choices = [];

        foreach ($class::cases() as $case) {
            $label = null;

            foreach (['label', 'getLabel'] as $method) {
                if (method_exists($case, $method)) {
                    $label = (string) $case->$method();
                    break;
                }
            }

            $choices[] = [$case, $label];
        }

        return $choices;
    }
}