<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use BackedEnum;
use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\DenormalizerInterface;
use NeoPHP\Component\Serializer\Contract\NormalizerInterface;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use ReflectionEnum;
use UnitEnum;

class BackedEnumNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed
    {
        return $data instanceof BackedEnum ? $data->value : $data->name;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof UnitEnum;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if ($data instanceof $type) {
            return $data;
        }

        $reflection = new ReflectionEnum($type);

        if ($reflection->isBacked()) {
            $backing = (string) $reflection->getBackingType();

            if ($backing === 'int' && is_string($data) && preg_match('/^[-+]?\d+$/', $data) === 1 && AbstractSerializer::isLenient($format, $context)) {
                $data = (int) $data;
            }

            if (($backing === 'int' && is_int($data)) || ($backing === 'string' && is_string($data))) {
                $case = $type::tryFrom($data);

                if ($case !== null) {
                    return $case;
                }
            }

            $allowed = array_map(static fn (BackedEnum $case): string => (string) $case->value, $type::cases());
        } else {
            if (is_string($data) && $reflection->hasCase($data)) {
                return $reflection->getCase($data)->getValue();
            }

            $allowed = array_map(static fn (UnitEnum $case): string => $case->name, $type::cases());
        }

        throw new NotNormalizableValueException('The value "{value}"{at} is not a valid choice for {enum} (allowed: {allowed}).', 0, null, [
            'value' => is_scalar($data) ? $data : get_debug_type($data),
            'enum' => $type,
            'allowed' => implode(', ', $allowed),
            'at' => AbstractSerializer::describePath($context),
            'path' => AbstractSerializer::path($context),
        ]);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return enum_exists($type);
    }
}