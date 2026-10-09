<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Mime;

use NeoPHP\Component\Mailer\Exception\MailerException;
use Stringable;

class Address implements Stringable
{
    protected string $address;

    protected string $name;

    public function __construct(string $address, string $name = '')
    {
        $address = trim($address);
        $name = trim($name);

        if (preg_match('/[\r\n\0]/', $address . $name) === 1) {
            throw new MailerException('The address "{address}" contains a line break.', 0, null, ['address' => addcslashes($address . ($name !== '' ? ' ' . $name : ''), "\r\n\0")]);
        }

        $at = strrpos($address, '@');

        if ($at !== false && preg_match('/[^\x20-\x7E]/', substr($address, $at + 1)) === 1 && function_exists('idn_to_ascii')) {
            $domain = idn_to_ascii(substr($address, $at + 1), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $address = is_string($domain) ? substr($address, 0, $at + 1) . $domain : $address;
        }

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailerException('The email address "{address}" is not valid.', 0, null, ['address' => $address]);
        }

        $this->address = $address;
        $this->name = $name;
    }

    public static function create(self|string $address): self
    {
        if ($address instanceof self) {
            return $address;
        }

        if (preg_match('/^\s*(.*?)\s*<([^<>]+)>\s*$/s', $address, $matches) === 1) {
            $name = trim($matches[1]);

            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $name, $quoted) === 1) {
                $name = (string) preg_replace('/\\\\(.)/s', '$1', $quoted[1]);
            }

            return new self($matches[2], $name);
        }

        return new self($address);
    }

    public static function createArray(array $addresses): array
    {
        return array_values(array_map(static fn (self|string $address): self => self::create($address), $addresses));
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toString(): string
    {
        return $this->name === '' ? $this->address : $this->name . ' <' . $this->address . '>';
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}