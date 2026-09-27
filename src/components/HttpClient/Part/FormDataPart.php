<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Part;

use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;
use Stringable;

class FormDataPart
{
    protected string $boundary;

    public function __construct(protected array $fields = [], ?string $boundary = null)
    {
        $this->boundary = $boundary ?? '----NeoPHP' . bin2hex(random_bytes(12));
    }

    public function getBoundary(): string
    {
        return $this->boundary;
    }

    public function getContentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getBody(): string
    {
        $body = '';

        foreach ($this->flatten($this->fields) as [$name, $value]) {
            $body .= '--' . $this->boundary . "\r\n";

            if ($value instanceof FilePart) {
                $body .= sprintf("Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n", $this->escape($name), $this->escape($value->getFilename()));
                $body .= 'Content-Type: ' . $value->getContentType() . "\r\n\r\n" . $value->getContent() . "\r\n";
                continue;
            }

            $body .= sprintf("Content-Disposition: form-data; name=\"%s\"\r\n\r\n", $this->escape($name)) . $value . "\r\n";
        }

        return $body . '--' . $this->boundary . "--\r\n";
    }

    protected function flatten(array $fields, ?string $prefix = null): array
    {
        $flat = [];

        foreach ($fields as $key => $value) {
            $name = $prefix === null ? (string) $key : $prefix . '[' . (is_int($key) && array_is_list($fields) ? '' : $key) . ']';

            if (is_array($value)) {
                array_push($flat, ...$this->flatten($value, $name));
                continue;
            }

            if ($value instanceof FilePart) {
                $flat[] = [$name, $value];
                continue;
            }

            if ($value === null || is_scalar($value) || $value instanceof Stringable) {
                $flat[] = [$name, is_bool($value) ? ($value ? '1' : '0') : (string) $value];
                continue;
            }

            throw new InvalidOptionException('The multipart field "{name}" has an unsupported value of type {type}.', 0, null, ['name' => $name, 'type' => get_debug_type($value)]);
        }

        return $flat;
    }

    protected function escape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}