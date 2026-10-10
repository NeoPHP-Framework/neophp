<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form;

use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Exception\InvalidTypeException;
use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Renderer\FormRenderer;
use NeoPHP\Component\Form\Type\FormType;
use NeoPHP\Component\Form\Type\ResolvedType;
use NeoPHP\Component\Validator\ValidatorManagerInterface;

interface FormManagerInterface
{
    /**
     * Creates a form named after the block prefix of its type, with a CSRF field when the protection is enabled.
     *
     * @param string $type Class of the form type
     * @param mixed $data Initial data of the form: an object, an array or a scalar
     * @param array<string, mixed> $options Options of the type
     * @return FormInterface The form
     * @throws InvalidTypeException When the type, or one of its parents, does not exist or does not implement FormTypeInterface
     * @throws FormException When an option does not exist or the parents of the type are circular
     */
    public function create(string $type = FormType::class, mixed $data = null, array $options = []): FormInterface;

    /**
     * Creates a form with a given name, with a CSRF field when the protection is enabled.
     *
     * @param string $name Name of the form, prefix of its field names; an empty string for unprefixed fields
     * @param string $type Class of the form type
     * @param mixed $data Initial data of the form: an object, an array or a scalar
     * @param array<string, mixed> $options Options of the type
     * @return FormInterface The form
     * @throws InvalidTypeException When the type, or one of its parents, does not exist or does not implement FormTypeInterface
     * @throws FormException When an option does not exist or the parents of the type are circular
     */
    public function createNamed(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormInterface;

    /**
     * Creates the builder of a root form named after the block prefix of its type, with a CSRF field when the protection is enabled.
     *
     * @param string $type Class of the form type
     * @param mixed $data Initial data of the form: an object, an array or a scalar
     * @param array<string, mixed> $options Options of the type
     * @return FormBuilder The builder
     * @throws InvalidTypeException When the type, or one of its parents, does not exist or does not implement FormTypeInterface
     * @throws FormException When an option does not exist or the parents of the type are circular
     */
    public function createBuilder(string $type = FormType::class, mixed $data = null, array $options = []): FormBuilder;

    /**
     * Creates the builder of a named form or field, without CSRF field.
     *
     * @param string $name Name of the form or of the field
     * @param string $type Class of the form type
     * @param mixed $data Initial data, replaced by the data option when it is given
     * @param array<string, mixed> $options Options of the type
     * @return FormBuilder The builder
     * @throws InvalidTypeException When the type, or one of its parents, does not exist or does not implement FormTypeInterface
     * @throws FormException When an option does not exist or the parents of the type are circular
     */
    public function createNamedBuilder(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormBuilder;

    /**
     * Returns a form type resolved with its parent types, built by the container and cached.
     *
     * @param string $type Class of the form type
     * @return ResolvedType The resolved type
     * @throws InvalidTypeException When the type, or one of its parents, does not exist or does not implement FormTypeInterface
     * @throws FormException When the parents of the type are circular
     */
    public function getType(string $type): ResolvedType;

    /**
     * Returns the validator used by the forms.
     *
     * @return ValidatorManagerInterface|null The validator, or null without the Validator component
     */
    public function getValidator(): ?ValidatorManagerInterface;

    /**
     * Returns the CSRF manager used by the forms.
     *
     * @return CsrfManagerInterface|null The CSRF manager, or null without the Csrf component
     */
    public function getCsrf(): ?CsrfManagerInterface;

    /**
     * Returns the renderer of the forms, using the theme of the configuration.
     *
     * @return FormRenderer The renderer
     */
    public function getRenderer(): FormRenderer;

    /**
     * Returns the configuration of form.yaml with its defaults: theme, csrf_protection and csrf_field_name.
     *
     * @return array<string, mixed> The configuration
     */
    public function getConfig(): array;
}