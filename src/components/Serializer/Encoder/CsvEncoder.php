<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Encoder;

use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\DecoderInterface;
use NeoPHP\Component\Serializer\Contract\EncoderInterface;
use NeoPHP\Component\Serializer\Exception\NotEncodableValueException;
use NeoPHP\Component\Serializer\Exception\UnexpectedValueException;

class CsvEncoder implements EncoderInterface, DecoderInterface
{
    public const FORMAT = 'csv';

    public function encode(mixed $data, string $format, array $context = []): string
    {
        if (!is_array($data)) {
            $data = [['value' => $data]];
        } elseif ($data === [] || !array_is_list($data)) {
            $data = $data === [] ? [] : [$data];
        }

        [$delimiter, $enclosure, $separator] = $this->options($context);
        $rows = [];
        $headers = array_map('strval', (array) ($context[AbstractSerializer::CSV_HEADERS] ?? []));

        foreach ($data as $row) {
            $flat = [];
            $this->flatten(is_array($row) ? $row : ['value' => $row], $flat, $separator);
            $rows[] = $flat;

            foreach (array_keys($flat) as $key) {
                if (!in_array((string) $key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }

        if ($headers === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new NotEncodableValueException('Unable to open a temporary stream to write the CSV.');
        }

        fputcsv($handle, $headers, $delimiter, $enclosure, '');

        foreach ($rows as $row) {
            $line = [];

            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }

            fputcsv($handle, $line, $delimiter, $enclosure, '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function decode(string $data, string $format, array $context = []): mixed
    {
        [$delimiter, $enclosure, $separator] = $this->options($context);
        $data = str_starts_with($data, "\xEF\xBB\xBF") ? substr($data, 3) : $data;

        if (trim($data) === '') {
            return [];
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new UnexpectedValueException('Unable to open a temporary stream to read the CSV.');
        }

        fwrite($handle, $data);
        rewind($handle);
        $headers = null;
        $rows = [];
        $line = 0;

        while (($fields = fgetcsv($handle, null, $delimiter, $enclosure, '')) !== false) {
            $line++;

            if ($fields === [null]) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(static fn (?string $header): string => trim((string) $header), $fields);
                continue;
            }

            if (count($fields) !== count($headers)) {
                fclose($handle);

                throw new UnexpectedValueException('Unable to decode CSV: line {line} has {count} fields, {expected} expected.', 0, null, ['line' => $line, 'count' => count($fields), 'expected' => count($headers)]);
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $this->unflatten($row, $header, (string) $fields[$index], $separator);
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function supportsEncoding(string $format): bool
    {
        return $format === self::FORMAT;
    }

    public function supportsDecoding(string $format): bool
    {
        return $format === self::FORMAT;
    }

    protected function options(array $context): array
    {
        $delimiter = (string) ($context[AbstractSerializer::CSV_DELIMITER] ?? ',');
        $enclosure = (string) ($context[AbstractSerializer::CSV_ENCLOSURE] ?? '"');
        $separator = (string) ($context[AbstractSerializer::CSV_KEY_SEPARATOR] ?? '.');

        if (strlen($delimiter) !== 1 || strlen($enclosure) !== 1 || $separator === '') {
            throw new NotEncodableValueException('The CSV delimiter and enclosure must be one character, and the key separator must not be empty.');
        }

        return [$delimiter, $enclosure, $separator];
    }

    protected function flatten(array $data, array &$result, string $separator, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $name = $prefix . $key;

            if (is_array($value)) {
                if ($value === []) {
                    $result[$name] = '';
                    continue;
                }

                $this->flatten($value, $result, $separator, $name . $separator);
                continue;
            }

            $result[$name] = match (true) {
                $value === null => '',
                $value === true => '1',
                $value === false => '0',
                is_scalar($value) => (string) $value,
                default => throw new NotEncodableValueException('Unable to encode a value of type "{type}" as CSV: normalize it first.', 0, null, ['type' => get_debug_type($value)]),
            };
        }
    }

    protected function unflatten(array &$row, string $header, string $value, string $separator): void
    {
        $keys = explode($separator, $header);
        $current = &$row;

        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $current[$key] = $value;
                break;
            }

            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }

        unset($current);
    }
}