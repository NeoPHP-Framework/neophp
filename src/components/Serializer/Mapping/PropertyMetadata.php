<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Mapping;

use ReflectionType;

class PropertyMetadata
{
    public ?string $serializedName = null;

    public array $groups = [];

    public bool $ignored = false;

    public ?int $maxDepth = null;

    public ?string $type = null;

    public array $contexts = [];

    public bool $publicRead = false;

    public bool $publicWrite = false;

    public ?string $getter = null;

    public ?string $setter = null;

    public bool $constructorArgument = false;

    public ?ReflectionType $propertyType = null;

    public ?ReflectionType $setterType = null;

    public function __construct(public string $name)
    {
    }

    public function isReadable(): bool
    {
        return !$this->ignored && ($this->publicRead || $this->getter !== null);
    }

    public function isWritable(): bool
    {
        return !$this->ignored && ($this->publicWrite || $this->setter !== null || $this->constructorArgument);
    }

    public function getWriteType(): ?ReflectionType
    {
        return $this->setter !== null && !$this->publicWrite ? $this->setterType ?? $this->propertyType : $this->propertyType ?? $this->setterType;
    }

    public function addGroups(array $groups): void
    {
        $this->groups = array_values(array_unique([...$this->groups, ...array_map('strval', $groups)]));
    }

    public function getContext(bool $normalization, array $groups = []): array
    {
        $context = [];

        foreach ($this->contexts as $attribute) {
            if ($attribute['groups'] !== [] && array_intersect($attribute['groups'], $groups) === []) {
                continue;
            }

            $context = array_replace($context, $attribute['context'], $normalization ? $attribute['normalization'] : $attribute['denormalization']);
        }

        return $context;
    }

    public function describeType(): string
    {
        if ($this->type !== null) {
            return $this->type;
        }

        $type = $this->propertyType ?? $this->setterType;

        return $type === null ? 'mixed' : (string) $type;
    }
}