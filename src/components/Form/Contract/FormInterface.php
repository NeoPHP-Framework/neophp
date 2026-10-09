<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Contract;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use NeoPHP\Component\Form\Model\FormError;
use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Type\ResolvedType;
use NeoPHP\Component\Http\Request\Request;

interface FormInterface extends ArrayAccess, IteratorAggregate, Countable
{
    public function getName(): string;

    public function getParent(): ?FormInterface;

    public function setParent(?FormInterface $parent): static;

    public function getRoot(): FormInterface;

    public function isRoot(): bool;

    public function getType(): ResolvedType;

    public function getOptions(): array;

    public function getOption(string $name, mixed $default = null): mixed;

    public function add(string $name, ?string $type = null, array $options = []): static;

    public function addChild(FormInterface $child): static;

    public function get(string $name): FormInterface;

    public function has(string $name): bool;

    public function remove(string $name): static;

    public function all(): array;

    public function getData(): mixed;

    public function setData(mixed $data): static;

    public function getViewData(): mixed;

    public function getExtraData(): array;

    public function hasExplicitData(): bool;

    public function handleRequest(Request $request): static;

    public function submit(mixed $data, bool $clearMissing = true): static;

    public function isSubmitted(): bool;

    public function isValid(): bool;

    public function isSynchronized(): bool;

    public function isCompound(): bool;

    public function isRequired(): bool;

    public function isDisabled(): bool;

    public function isMapped(): bool;

    public function isButton(): bool;

    public function isClicked(): bool;

    public function getClickedButton(): ?FormInterface;

    public function getPropertyPath(): string;

    public function getErrors(bool $deep = false): array;

    public function addError(FormError|string $error): static;

    public function clearErrors(bool $deep = false): static;

    public function createView(?FormView $parent = null): FormView;

    public function getView(): FormView;
}