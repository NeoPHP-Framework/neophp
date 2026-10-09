<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Model;

use ArrayIterator;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\FormManagerInterface;
use NeoPHP\Component\Form\Type\HiddenType;
use NeoPHP\Component\Form\Type\ResolvedType;
use NeoPHP\Component\Form\Type\TextType;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use Traversable;

class Form implements FormInterface
{
    public const CSRF_ERROR = 'The CSRF token is invalid. Please try to resubmit the form.';

    public const EXTRA_FIELDS_ERROR = 'This form should not contain extra fields.';

    protected ?FormInterface $parent = null;

    protected array $children = [];

    protected mixed $modelData = null;

    protected mixed $viewData = null;

    protected array $extraData = [];

    protected array $errors = [];

    protected bool $submitted = false;

    protected bool $synchronized = true;

    protected bool $clicked = false;

    protected ?FormView $view = null;

    public function __construct(
        protected string $name,
        protected ResolvedType $type,
        protected array $options,
        protected FormManagerInterface $manager,
        protected bool $explicitData = false,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParent(): ?FormInterface
    {
        return $this->parent;
    }

    public function setParent(?FormInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getRoot(): FormInterface
    {
        return $this->parent === null ? $this : $this->parent->getRoot();
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    public function getType(): ResolvedType
    {
        return $this->type;
    }

    public function getManager(): FormManagerInterface
    {
        return $this->manager;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    public function add(string $name, ?string $type = null, array $options = []): static
    {
        $child = $this->manager->createNamedBuilder($name, $type ?? TextType::class, null, $options)->getForm();
        $this->addChild($child);

        if (!$child->hasExplicitData() && $this->isCompound() && $child->isMapped() && !$child->isButton()) {
            $this->type->mapDataToForms($this->modelData, [$name => $child], $this->options, $this);
        }

        return $this;
    }

    public function addChild(FormInterface $child): static
    {
        if ($this->submitted) {
            throw new FormException('A field cannot be added to the submitted form "{form}".', 0, null, ['form' => $this->name]);
        }

        $child->setParent($this);
        $this->children[$child->getName()] = $child;
        $this->view = null;

        return $this;
    }

    public function get(string $name): FormInterface
    {
        return $this->children[$name] ?? throw new FormException('The field "{name}" does not exist in the form "{form}".', 0, null, ['name' => $name, 'form' => $this->name]);
    }

    public function has(string $name): bool
    {
        return isset($this->children[$name]);
    }

    public function remove(string $name): static
    {
        if (isset($this->children[$name])) {
            $this->children[$name]->setParent(null);
            unset($this->children[$name]);
            $this->view = null;
        }

        return $this;
    }

    public function all(): array
    {
        return $this->children;
    }

    public function getData(): mixed
    {
        return $this->modelData;
    }

    public function setData(mixed $data): static
    {
        if ($this->submitted) {
            throw new FormException('The data of the submitted form "{form}" cannot be changed.', 0, null, ['form' => $this->name]);
        }

        $this->modelData = $data;
        $this->view = null;

        if ($this->isCompound()) {
            $this->viewData = $data;
            $this->type->mapDataToForms($data, $this->mappedChildren(false), $this->options, $this);

            return $this;
        }

        try {
            $this->viewData = $this->type->transform($data, $this->options);
        } catch (TransformationFailedException) {
            $this->viewData = null;
        }

        return $this;
    }

    public function getViewData(): mixed
    {
        return $this->viewData;
    }

    public function getExtraData(): array
    {
        return $this->extraData;
    }

    public function hasExplicitData(): bool
    {
        return $this->explicitData;
    }

    public function handleRequest(Request $request): static
    {
        $method = strtoupper((string) $this->getOption('method', 'POST'));

        if ($request->getMethod() !== $method) {
            return $this;
        }

        if (in_array($method, ['GET', 'HEAD'], true)) {
            $parameters = $request->query->all();
        } else {
            $parameters = array_replace_recursive($request->request->all(), $request->files->all());
        }

        if ($this->name === '') {
            if ($parameters === [] && $this->children !== []) {
                return $this;
            }

            return $this->submit($parameters, $method !== 'PATCH');
        }

        if (!array_key_exists($this->name, $parameters)) {
            return $this;
        }

        return $this->submit($parameters[$this->name], $method !== 'PATCH');
    }

    public function submit(mixed $data, bool $clearMissing = true): static
    {
        if ($this->submitted) {
            throw new FormException('The form "{form}" is already submitted.', 0, null, ['form' => $this->name]);
        }

        if (!$this->isButton() && !$this->isDisabled()) {
            $data = $this->type->preSubmit($this, $data, $this->options);
        }

        $this->submitted = true;
        $this->view = null;

        if ($this->isButton()) {
            $this->clicked = $data !== null;

            return $this;
        }

        if ($this->isDisabled()) {
            return $this;
        }

        if ($this->isCompound()) {
            $this->submitCompound(is_array($data) ? $data : [], $clearMissing);
        } else {
            $this->submitSimple($data);
        }

        if ($this->isRoot()) {
            $this->validate();
        }

        return $this;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted;
    }

    public function isValid(): bool
    {
        return $this->submitted && !$this->hasErrorsDeep();
    }

    public function isSynchronized(): bool
    {
        return $this->synchronized;
    }

    public function isCompound(): bool
    {
        return (bool) $this->getOption('compound', false);
    }

    public function isRequired(): bool
    {
        return (bool) $this->getOption('required', false) && ($this->parent === null || $this->parent->isRequired());
    }

    public function isDisabled(): bool
    {
        return (bool) $this->getOption('disabled', false) || ($this->parent !== null && $this->parent->isDisabled());
    }

    public function isMapped(): bool
    {
        return (bool) $this->getOption('mapped', true);
    }

    public function isButton(): bool
    {
        return (bool) $this->getOption('button', false);
    }

    public function isClicked(): bool
    {
        return $this->clicked;
    }

    public function getClickedButton(): ?FormInterface
    {
        foreach ($this->children as $child) {
            if ($child->isButton() && $child->isClicked()) {
                return $child;
            }

            if ($child->isCompound() && ($clicked = $child->getClickedButton()) !== null) {
                return $clicked;
            }
        }

        return null;
    }

    public function getPropertyPath(): string
    {
        $path = $this->getOption('property_path');

        return is_string($path) && $path !== '' ? $path : $this->name;
    }

    public function getErrors(bool $deep = false): array
    {
        $errors = $this->errors;

        if ($deep) {
            foreach ($this->children as $child) {
                array_push($errors, ...$child->getErrors(true));
            }
        }

        return $errors;
    }

    public function addError(FormError|string $error): static
    {
        $error = is_string($error) ? new FormError($error, $this) : $error;

        if ($error->getOrigin() === null) {
            $error->setOrigin($this);
        }

        if ($this->parent !== null && $this->getOption('error_bubbling', false)) {
            $this->parent->addError($error);

            return $this;
        }

        foreach ($this->errors as $existing) {
            if ($existing->getMessage() === $error->getMessage()) {
                return $this;
            }
        }

        $this->errors[] = $error;
        $this->view = null;

        return $this;
    }

    public function clearErrors(bool $deep = false): static
    {
        $this->errors = [];

        if ($deep) {
            foreach ($this->children as $child) {
                $child->clearErrors(true);
            }
        }

        return $this;
    }

    public function createView(?FormView $parent = null): FormView
    {
        $view = new FormView($parent);
        $parentName = $parent?->vars['full_name'] ?? '';
        $parentId = $parent?->vars['id'] ?? '';
        $label = $this->getOption('label');
        $prefixes = $this->type->getBlockPrefixes();
        $blockPrefix = $this->getOption('block_prefix');

        if (is_string($blockPrefix) && $blockPrefix !== '') {
            $prefixes[] = $blockPrefix;
        }

        $view->vars = [
            'name' => $this->name,
            'full_name' => $parentName === '' ? $this->name : $parentName . '[' . $this->name . ']',
            'id' => trim((string) preg_replace('/[^A-Za-z0-9_\-:.]+/', '_', $parentId === '' ? $this->name : $parentId . '_' . $this->name), '_'),
            'value' => $this->isCompound() ? null : $this->viewData,
            'data' => $this->modelData,
            'label' => $label === false ? false : ($label ?? self::humanize($this->name)),
            'label_attr' => (array) $this->getOption('label_attr', []),
            'attr' => (array) $this->getOption('attr', []),
            'row_attr' => (array) $this->getOption('row_attr', []),
            'help' => $this->getOption('help'),
            'help_attr' => (array) $this->getOption('help_attr', []),
            'translation_domain' => $this->getOption('translation_domain') ?? $parent?->vars['translation_domain'] ?? null,
            'required' => $this->isRequired(),
            'disabled' => $this->isDisabled(),
            'errors' => array_map(static fn (FormError $error): string => $error->getMessage(), $this->errors),
            'valid' => !$this->submitted || $this->errors === [],
            'submitted' => $this->submitted,
            'compound' => $this->isCompound(),
            'block_prefixes' => $prefixes,
            'multipart' => false,
            'method' => strtoupper((string) $this->getOption('method', 'POST')),
            'action' => (string) $this->getOption('action', ''),
            'theme' => $this->getOption('theme'),
        ];

        $this->type->buildView($view, $this, $this->options);

        foreach ($this->children as $name => $child) {
            $view->children[$name] = $child->createView($view);

            if ($view->children[$name]->vars['multipart'] ?? false) {
                $view->vars['multipart'] = true;
            }
        }

        return $view;
    }

    public function getView(): FormView
    {
        if ($this->parent !== null) {
            return $this->parent->getView()->children[$this->name];
        }

        return $this->view ??= $this->createView();
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): FormInterface
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof FormInterface) {
            throw new FormException('Only forms can be added to a form.');
        }

        $this->addChild($value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove((string) $offset);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->children);
    }

    public function count(): int
    {
        return count($this->children);
    }

    public static function humanize(string $name): string
    {
        $text = strtolower(trim((string) preg_replace(['/([a-z\d])([A-Z])/', '/[_\s]+/'], ['$1 $2', ' '], $name)));

        return ucfirst($text);
    }

    public function markTransformationFailed(string $message): static
    {
        $this->synchronized = false;

        return $this->addError($message);
    }

    protected function submitCompound(array $data, bool $clearMissing): void
    {
        foreach ($this->children as $name => $child) {
            if (!array_key_exists($name, $data) && !$clearMissing && !$child->isButton()) {
                continue;
            }

            $child->submit($data[$name] ?? null, $clearMissing);
        }

        $this->extraData = array_diff_key($data, $this->children);

        if ($this->extraData !== [] && !$this->getOption('allow_extra_fields', false)) {
            $this->addError(self::EXTRA_FIELDS_ERROR);
        }

        try {
            $this->modelData = $this->type->mapFormsToData($this->mappedChildren(true), $this->modelData, $this->options, $this);
            $this->viewData = $this->modelData;
        } catch (TransformationFailedException $exception) {
            $target = $exception->getChild() !== null && $this->has($exception->getChild()) ? $this->get($exception->getChild()) : $this;
            $this->synchronized = false;
            $target->addError($exception->getInvalidMessage() ?? (string) $this->getOption('invalid_message'));
        }
    }

    protected function submitSimple(mixed $data): void
    {
        if (is_string($data) && $this->getOption('trim', true)) {
            $data = trim($data);
        }

        if (($data === null || $data === '' || $data === []) && $this->getOption('empty_data') !== null) {
            $empty = $this->getOption('empty_data');
            $data = is_callable($empty) && !is_string($empty) ? $empty($this) : $empty;
        }

        $this->viewData = $data;

        try {
            $this->modelData = $this->type->reverseTransform($data, $this->options);
        } catch (TransformationFailedException $exception) {
            $this->synchronized = false;
            $this->addError($exception->getInvalidMessage() ?? (string) $this->getOption('invalid_message'));
        }
    }

    public function mappedChildren(bool $submitted): array
    {
        $children = [];

        foreach ($this->children as $name => $child) {
            if (!$child->isMapped() || $child->isButton()) {
                continue;
            }

            if ($submitted && (!$child->isSubmitted() || !$child->isSynchronized() || $child->isDisabled())) {
                continue;
            }

            if (!$submitted && $child->hasExplicitData()) {
                continue;
            }

            $children[$name] = $child;
        }

        return $children;
    }

    protected function validate(): void
    {
        $this->validateCsrf();
        $validator = $this->manager->getValidator();

        if ($validator === null) {
            return;
        }

        $groups = (array) ($this->getOption('validation_groups') ?? [AbstractConstraint::DEFAULT_GROUP]);

        if ($groups === []) {
            return;
        }

        $this->validateConstraints($this, $validator, $groups);

        if (is_object($this->modelData) && $this->isCompound()) {
            foreach ($validator->validate($this->modelData, null, $groups) as $violation) {
                $target = $this->findTarget($violation->getPropertyPath());

                if ($target->isSynchronized()) {
                    $target->addError(new FormError($violation->getMessage(), null, $violation->getParameters(), $violation));
                }
            }
        }
    }

    protected function validateConstraints(FormInterface $form, object $validator, array $groups): void
    {
        $constraints = $form->getOption('constraints', []);

        if ($constraints !== [] && $constraints !== null && $form->isSubmitted() && $form->isSynchronized() && !$form->isButton()) {
            foreach ($validator->validate($form->getData(), is_array($constraints) ? $constraints : [$constraints], $groups) as $violation) {
                $target = $violation->getPropertyPath() !== '' && $form instanceof self ? $form->findTarget($violation->getPropertyPath()) : $form;
                $target->addError(new FormError($violation->getMessage(), null, $violation->getParameters(), $violation));
            }
        }

        foreach ($form->all() as $child) {
            $this->validateConstraints($child, $validator, $groups);
        }
    }

    public function findTarget(string $path): FormInterface
    {
        $segments = array_values(array_filter(explode('.', str_replace(['[', ']'], ['.', ''], $path)), static fn (string $segment): bool => $segment !== ''));
        $target = $this;

        foreach ($segments as $segment) {
            $found = null;

            foreach ($target->all() as $child) {
                if ($child->isMapped() && !$child->isButton() && ($child->getPropertyPath() === $segment || $child->getName() === $segment)) {
                    $found = $child;
                    break;
                }
            }

            if ($found === null) {
                break;
            }

            $target = $found;
        }

        return $target;
    }

    protected function validateCsrf(): void
    {
        $field = (string) $this->getOption('csrf_field_name', '_token');
        $csrf = $this->manager->getCsrf();

        if ($csrf === null || !$this->has($field) || !$this->get($field)->getType()->isA(HiddenType::class) || $this->get($field)->isMapped()) {
            return;
        }

        $token = $this->get($field)->getViewData();

        if (!$csrf->isTokenValid($this->getCsrfTokenId(), is_string($token) ? $token : null)) {
            $this->addError(self::CSRF_ERROR);
        }
    }

    public function getCsrfTokenId(): string
    {
        $id = $this->getOption('csrf_token_id');

        return is_string($id) && $id !== '' ? $id : ($this->name !== '' ? $this->name : 'form');
    }

    protected function hasErrorsDeep(): bool
    {
        if ($this->errors !== []) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->getErrors(true) !== []) {
                return true;
            }
        }

        return false;
    }
}