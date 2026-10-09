<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\FormView;

class DateTimeType extends AbstractType
{
    public const INPUT = 'datetime-local';

    public const FORMAT = 'Y-m-d\TH:i';

    public const FORMAT_WITH_SECONDS = 'Y-m-d\TH:i:s';

    public const PARSE_FORMATS = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'input' => 'datetime_immutable', 'with_seconds' => false, 'widget' => 'single_text', 'html5' => true, 'input_format' => 'Y-m-d H:i:s'];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = $options['html5'] ? static::INPUT : 'text';

        if ($options['with_seconds'] && $options['html5']) {
            $view->vars['attr'] += ['step' => '1'];
        }
    }

    public function transform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return '';
        }

        $date = match (true) {
            $data instanceof DateTimeInterface => $data,
            is_int($data) => (new DateTimeImmutable())->setTimestamp($data),
            is_string($data) => $this->parse($data, [(string) $options['input_format'], ...static::PARSE_FORMATS, 'Y-m-d']),
            default => null,
        };

        if ($date === null) {
            throw new TransformationFailedException('Expected a date.');
        }

        return $date->format($options['with_seconds'] ? static::FORMAT_WITH_SECONDS : static::FORMAT);
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        if ($data === null || $data === '') {
            return null;
        }

        if (!is_string($data)) {
            throw new TransformationFailedException('Expected a string.');
        }

        $date = $this->parse($data, static::PARSE_FORMATS);

        if ($date === null) {
            throw new TransformationFailedException('Invalid date "{value}".', null, null, ['value' => $data]);
        }

        return match ($options['input']) {
            'datetime' => DateTime::createFromImmutable($date),
            'string' => $date->format((string) $options['input_format']),
            'timestamp' => $date->getTimestamp(),
            default => $date,
        };
    }

    protected function parse(string $value, array $formats): ?DateTimeImmutable
    {
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }
}