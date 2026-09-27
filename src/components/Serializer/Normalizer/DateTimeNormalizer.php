<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\DenormalizerInterface;
use NeoPHP\Component\Serializer\Contract\NormalizerInterface;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;

class DateTimeNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed
    {
        $timezone = $this->timezone($context);

        if ($timezone !== null && ($data instanceof DateTimeImmutable || $data instanceof DateTime)) {
            $data = DateTimeImmutable::createFromInterface($data)->setTimezone($timezone);
        }

        return $data->format((string) ($context[AbstractSerializer::DATETIME_FORMAT] ?? DateTimeInterface::RFC3339));
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof DateTimeInterface;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $class = $type === DateTimeInterface::class ? DateTimeImmutable::class : $type;

        if ($data instanceof DateTimeInterface) {
            return $class === DateTime::class || is_subclass_of($class, DateTime::class) ? $class::createFromInterface($data) : DateTimeImmutable::createFromInterface($data);
        }

        if (is_int($data)) {
            $data = '@' . $data;
        }

        if (!is_string($data) || trim($data) === '') {
            throw new NotNormalizableValueException('The value{at} must be a date string, {actual} given.', 0, null, [
                'actual' => is_string($data) ? 'an empty string' : get_debug_type($data),
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        $timezone = $this->timezone($context);
        $dateFormat = $context[AbstractSerializer::DATETIME_FORMAT] ?? null;

        if (is_string($dateFormat) && $dateFormat !== '') {
            $pattern = str_contains($dateFormat, '!') || str_contains($dateFormat, '|') ? $dateFormat : '!' . $dateFormat;
            $date = $class::createFromFormat($pattern, $data, $timezone);

            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new $class($data, $timezone);
        } catch (Exception $exception) {
            throw new NotNormalizableValueException('The value "{value}"{at} is not a valid date (expected format: {format}).', 0, $exception, [
                'value' => $data,
                'format' => is_string($dateFormat) ? $dateFormat : DateTimeInterface::RFC3339,
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === DateTimeInterface::class || $type === DateTimeImmutable::class || $type === DateTime::class || is_subclass_of($type, DateTimeInterface::class);
    }

    protected function timezone(array $context): ?DateTimeZone
    {
        $timezone = $context[AbstractSerializer::DATETIME_TIMEZONE] ?? null;

        return match (true) {
            $timezone instanceof DateTimeZone => $timezone,
            is_string($timezone) && $timezone !== '' => new DateTimeZone($timezone),
            default => null,
        };
    }
}