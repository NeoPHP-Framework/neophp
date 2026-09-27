<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Encoder;

use DOMDocument;
use DOMElement;
use DOMNode;
use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\DecoderInterface;
use NeoPHP\Component\Serializer\Contract\EncoderInterface;
use NeoPHP\Component\Serializer\Exception\NotEncodableValueException;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\Exception\UnexpectedValueException;

class XmlEncoder implements EncoderInterface, DecoderInterface
{
    public const FORMAT = 'xml';

    public const ROOT_NODE_NAME = 'response';

    public const ITEM_NODE_NAME = 'item';

    public function encode(mixed $data, string $format, array $context = []): string
    {
        $this->assertDom();

        $document = new DOMDocument('1.0', (string) ($context[AbstractSerializer::XML_ENCODING] ?? 'UTF-8'));
        $document->formatOutput = (bool) ($context[AbstractSerializer::XML_FORMAT_OUTPUT] ?? false);
        $rootName = (string) ($context[AbstractSerializer::XML_ROOT_NODE_NAME] ?? self::ROOT_NODE_NAME);

        if (!$this->isValidName($rootName)) {
            throw new NotEncodableValueException('The XML root node name "{name}" is not valid.', 0, null, ['name' => $rootName]);
        }

        $root = $document->createElement($rootName);
        $document->appendChild($root);
        $this->build($document, $root, $data);

        return (string) $document->saveXML();
    }

    public function decode(string $data, string $format, array $context = []): mixed
    {
        $this->assertDom();

        if (trim($data) === '') {
            throw new UnexpectedValueException('Unable to decode XML: the input is empty.');
        }

        if (preg_match('/<!DOCTYPE/i', $data) === 1) {
            throw new UnexpectedValueException('Unable to decode XML: document types (DOCTYPE) are not allowed.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $loaded = $document->loadXML($data, LIBXML_NONET | LIBXML_NOBLANKS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            throw new UnexpectedValueException('Unable to decode XML: {error}', 0, null, ['error' => $errors !== [] ? trim($errors[0]->message) : 'invalid document']);
        }

        return $this->parse($document->documentElement);
    }

    public function supportsEncoding(string $format): bool
    {
        return $format === self::FORMAT;
    }

    public function supportsDecoding(string $format): bool
    {
        return $format === self::FORMAT;
    }

    protected function build(DOMDocument $document, DOMElement $parent, mixed $data): void
    {
        if (!is_array($data)) {
            $this->appendValue($document, $parent, $data);

            return;
        }

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '@') && strlen($key) > 1) {
                $parent->setAttribute(substr($key, 1), $this->scalar($value));
                continue;
            }

            if ($key === '#') {
                $this->appendValue($document, $parent, $value);
                continue;
            }

            if (is_array($value) && $value !== [] && array_is_list($value) && $this->isValidName($key)) {
                foreach ($value as $item) {
                    $child = $document->createElement($key);
                    $parent->appendChild($child);
                    $this->build($document, $child, $item);
                }

                continue;
            }

            if ($this->isValidName($key)) {
                $child = $document->createElement($key);
            } else {
                $child = $document->createElement(self::ITEM_NODE_NAME);
                $child->setAttribute('key', $key);
            }

            $parent->appendChild($child);
            $this->build($document, $child, $value);
        }
    }

    protected function appendValue(DOMDocument $document, DOMElement $parent, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_object($value)) {
            throw new NotEncodableValueException('Unable to encode a value of type "{type}" as XML: normalize it first.', 0, null, ['type' => get_debug_type($value)]);
        }

        $parent->appendChild($document->createTextNode($this->scalar($value)));
    }

    protected function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => '1',
            $value === false => '0',
            is_float($value) => (string) (floor($value) === $value && abs($value) < 1e15 ? number_format($value, 1, '.', '') : $value),
            is_scalar($value) => (string) $value,
            default => throw new NotEncodableValueException('Unable to encode a value of type "{type}" as an XML attribute.', 0, null, ['type' => get_debug_type($value)]),
        };
    }

    protected function parse(DOMElement $element): mixed
    {
        $result = [];

        foreach ($element->attributes ?? [] as $attribute) {
            if ($attribute->nodeName !== 'key' || $element->nodeName !== self::ITEM_NODE_NAME) {
                $result['@' . $attribute->nodeName] = $attribute->nodeValue;
            }
        }

        $children = [];
        $text = '';

        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            } elseif (in_array($node->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                $text .= (string) $node->nodeValue;
            }
        }

        if ($children === []) {
            if ($result === []) {
                return $text;
            }

            if ($text !== '') {
                $result['#'] = $text;
            }

            return $result;
        }

        $counts = [];

        foreach ($children as $child) {
            $name = $this->nodeKey($child);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        foreach ($children as $child) {
            $name = $this->nodeKey($child);
            $value = $this->parse($child);

            if ($counts[$name] > 1 || ($child->nodeName === self::ITEM_NODE_NAME && !$child->hasAttribute('key'))) {
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        if (count($result) === 1 && isset($result[self::ITEM_NODE_NAME]) && is_array($result[self::ITEM_NODE_NAME]) && array_is_list($result[self::ITEM_NODE_NAME])) {
            return $result[self::ITEM_NODE_NAME];
        }

        return $result;
    }

    protected function nodeKey(DOMNode $node): string
    {
        return $node instanceof DOMElement && $node->nodeName === self::ITEM_NODE_NAME && $node->hasAttribute('key') ? $node->getAttribute('key') : $node->nodeName;
    }

    protected function isValidName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z_][\w.-]*$/', $name) === 1 && !str_starts_with(strtolower($name), 'xml');
    }

    protected function assertDom(): void
    {
        if (!class_exists(DOMDocument::class)) {
            throw new SerializerException('The XML format of the Serializer requires the "dom" PHP extension (ext-dom).');
        }
    }
}