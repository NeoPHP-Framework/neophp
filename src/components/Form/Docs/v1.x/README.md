# Form

The Form component builds, submits, validates and renders HTML forms mapped to an entity, an object or an array.
It converts the submitted values, reports the violations on their fields and renders them with themes and view helpers.

## Summary

- [Form classes](#form-classes)
- [In a controller](#in-a-controller)
- [Field types](#field-types)
- [Custom types](#custom-types)
- [Rendering](#rendering)
- [Themes](#themes)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Form classes

A form is a class of `src/Form/` extending `NeoPHP\Component\Form\Contract\AbstractForm`. It works with an entity (or any object) or with an array.

```bash
php bin/neo make:form Post Post
php bin/neo make:form Contact
```

`make:form <name> [entity]` creates `src/Form/<Name>Form.php`. `make:form Post Post` reads the mapping of the entity `App\Entity\Post` and adds a field for each column and owning relation (text, textarea, number, checkbox, date, enum, entity...). Without entity, the form works with an array.

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Tag;
use App\Enum\PostStatus;
use NeoPHP\Component\Form\Contract\AbstractForm;
use NeoPHP\Component\Form\FormBuilder;
use NeoPHP\Component\Form\Type\EnumType;
use NeoPHP\Component\Form\Type\TextareaType;
use NeoPHP\Component\Form\Type\TextType;
use NeoPHP\Package\Orm\Helper\Form\EntityType;

class PostForm extends AbstractForm
{
    protected ?string $entityClass = Post::class;

    public function buildForm(FormBuilder $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('content', TextareaType::class, ['required' => false, 'help' => 'Markdown is allowed.'])
            ->add('status', EnumType::class, ['class' => PostStatus::class])
            ->add('category', EntityType::class, ['class' => Category::class, 'choice_label' => 'name'])
            ->add('tags', EntityType::class, ['class' => Tag::class, 'multiple' => true, 'required' => false]);
    }
}
```

`$entityClass` binds the form to an entity (it is the `data_class` option; `configureOptions()` can return `['data_class' => Post::class]` too). Without `$entityClass`, the form works with an array. `configureOptions()` returns the default options of the form (`method`, `csrf_protection`, `validation_groups`...).

## In a controller

```php
#[Route('/posts/new', name: 'post_new', methods: ['GET', 'POST'])]
#[Route('/posts/{id}/edit', name: 'post_edit', methods: ['GET', 'POST'])]
public function edit(Request $request, PostRepository $posts, ?int $id = null): Response
{
    $post = $id === null ? new Post() : ($posts->find($id) ?? throw $this->createNotFoundException());
    $form = $this->createForm(PostForm::class, $post);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $posts->save($post, true);
        $this->addFlash('success', 'Post saved.');

        return $this->redirectToRoute('post_edit', ['id' => $post->getId()]);
    }

    return $this->render('post/edit.html.twig', ['form' => $form]);
}
```

- `createForm(PostForm::class, $post, $options)` builds the form; `handleRequest($request)` submits it when the request has the method of the form (`POST` by default) and a value named like the form (`post`).
- The submitted values are converted (strings to `int`, `DateTimeImmutable`, enums, entities...) and written into the entity with its setters (`setTitle()`), its `add...()` / `remove...()` methods for collections, or its properties.
- `isValid()` checks the CSRF token, the `constraints` of the fields and the constraints of the entity (`#[Assert\NotBlank]`...). Each violation is attached to the field of its property.
- A value that cannot be converted (`abc` in an integer field, an unknown choice) gives the error `invalid_message` on the field. An empty field written into a setter that does not accept `null` gives `This value should not be blank.`.
- Without entity, `getData()` returns an array (`['name' => 'Bob', 'email' => ...]`); `createForm(ContactForm::class, ['name' => 'Bob'])` sets the initial values.
- `createFormBuilder($data)->add(...)->getForm()` builds a form without class.
- `getClickedButton()` returns the submit button used; `getErrors(true)` returns all the errors.

### Form API

`createForm()` returns a `NeoPHP\Component\Form\Contract\FormInterface` (class `Form`); each field is a form too (`$form->get('title')`, `$form['title']` in views):

| Method | Description |
|---|---|
| `getName()`, `getParent()`, `getRoot()`, `isRoot()`, `getType()`, `getOptions()`, `getOption($name)` | structure |
| `add($name, $type, $options)`, `get($name)`, `has($name)`, `remove($name)`, `all()` | fields |
| `getData()`, `setData($data)`, `getViewData()`, `getExtraData()` | data (model, view, extra submitted values) |
| `handleRequest($request)`, `submit($data, $clearMissing = true)` | submission |
| `isSubmitted()`, `isValid()`, `isSynchronized()` | state |
| `isRequired()`, `isDisabled()`, `isMapped()`, `isCompound()`, `isButton()`, `isClicked()`, `getClickedButton()` | options and buttons |
| `getErrors($deep = false)`, `addError($error)`, `clearErrors($deep = false)` | `FormError` list (`getMessage()`, `getParameters()`, `getOrigin()`, `getCause()`) |
| `createView()`, `getView()` | `FormView` for the templates (`vars`, `children`, `isRendered()`) |

`FormManagerInterface` (implemented by `FormManager`) creates forms in a service: `create($type, $data, $options)`, `createNamed($name, $type, $data, $options)`, `createBuilder()`, `createNamedBuilder()`, `getType()`, `getRenderer()`, `getValidator()`, `getCsrf()`, `getConfig()`.

`FormBuilder`: `add()`, `create()`, `get()`, `has()`, `remove()`, `all()`, `getData()`, `setData()`, `getOption()`, `setOption()`, `getForm()`.

## Field types

| Type | Model value | Specific options |
|---|---|---|
| `TextType`, `TextareaType`, `EmailType`, `UrlType`, `TelType`, `SearchType`, `ColorType` | `string` or `null` | |
| `PasswordType` | `string` | `always_empty` (true: never rendered back) |
| `HiddenType` | `string` | |
| `IntegerType` | `int` | `input` (`number` or `string`) |
| `NumberType` | `float` | `scale`, `input` (`number` or `string` for decimals), `html5` |
| `CheckboxType` | `bool` | `value` |
| `ChoiceType` | the chosen value(s) | `choices` (`['Label' => value]`), `multiple`, `expanded` (radios / checkboxes), `placeholder`, `choice_label`, `choice_value`, `choice_attr` |
| `EnumType` | enum case(s) | `class`; the label is `label()` / `getLabel()` of the enum when it exists, the case name otherwise |
| `EntityType` (`NeoPHP\Package\Orm\Helper\Form\`) | entity or entities | `class`, `choice_label` (property or callable), `query_builder` (`fn (PostRepository $repository) => $repository->createQueryBuilder('p')->orderBy('p.title')`), `choices`, `multiple`, `expanded` |
| `DateType`, `DateTimeType`, `TimeType` | `DateTimeImmutable` | `input` (`datetime_immutable`, `datetime`, `string`, `timestamp`), `with_seconds`, `html5` |
| `FileType` | `UploadedFile` (or a list with `multiple`) | `multiple` |
| `CollectionType` | array | `entry_type`, `entry_options`, `allow_add`, `allow_delete`, `delete_empty`, `prototype`, `prototype_name` |
| `RepeatedType` | the value of both fields | `type`, `options`, `first_options`, `second_options`, `first_name`, `second_name`, `invalid_message` |
| `SubmitType`, `ButtonType` | none (not mapped) | `isClicked()` |

Options shared by every field: `label` (`false` hides it), `label_attr`, `attr`, `row_attr`, `help`, `help_attr`, `required` (HTML attribute, not a constraint), `disabled`, `mapped` (`false`: not read nor written in the data), `property_path`, `data` (forced initial value), `empty_data`, `constraints`, `invalid_message`, `trim`, `translation_domain` (domain of the label, help, placeholder and choice labels, inherited from the parent; `false` disables their translation). Options of the root form: `data_class`, `method`, `action`, `csrf_protection`, `csrf_field_name`, `csrf_token_id`, `validation_groups`, `allow_extra_fields`, `theme`.

A form can be used as a field of another form (`->add('address', AddressForm::class)`): its data is read and written in the property `address`.

`FileType` fields are usually `'mapped' => false`: move the file in the controller (`$form->get('image')->getData()?->move(...)`) and store its name in the entity.

`CollectionType` renders the attribute `data-prototype` (the HTML of a new entry, with `__name__` in place of the index) when `allow_add` is enabled, to add entries in JavaScript.

## Custom types

A type implements `FormTypeInterface`, usually by extending `NeoPHP\Component\Form\Contract\AbstractType`; it is created with the container, so its constructor is autowired.

```php
<?php

declare(strict_types=1);

namespace App\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Type\TextType;

class TagsInputType extends AbstractType
{
    public function getParent(): ?string
    {
        return TextType::class;
    }

    public function configureOptions(): array
    {
        return ['separator' => ','];
    }

    public function transform(mixed $data, array $options): mixed
    {
        return implode($options['separator'], (array) $data);
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        return array_values(array_filter(array_map('trim', explode($options['separator'], (string) $data))));
    }
}
```

| Method | Role |
|---|---|
| `getParent()` | parent type (`FormType` by default): its options, rendering and behaviour are inherited |
| `configureOptions()` | options of the type and their default values; an unknown option throws an exception |
| `buildForm()` | adds the fields |
| `buildView()` | adds variables to the view (`$view->vars`) |
| `transform()` / `reverseTransform()` | converts the model value to the view value and back; throw `TransformationFailedException` for an invalid value |
| `mapDataToForms()` / `mapFormsToData()` | reads and writes the data of the children (compound types) |
| `preSubmit()` | changes the submitted value before it is processed |
| `getBlockPrefix()` | name used by the themes (`tags_input_widget`); by default, the class name without `Type` / `Form` in snake_case |

## Rendering

PHP templates:

```php
<?= $this->form_start($form) ?>
<?= $this->form_errors($form) ?>
<?= $this->form_row($form['title']) ?>
<?= $this->form_row($form['content'], ['attr' => ['rows' => 8]]) ?>
<button type="submit">Save</button>
<?= $this->form_end($form) ?>
```

Twig templates:

```twig
{{ form_start(form) }}
    {{ form_errors(form) }}
    {{ form_row(form.title) }}
    {{ form_label(form.tags, 'Tags') }}
    {{ form_widget(form.tags, {attr: {class: 'tags'}}) }}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

| Helper | Renders |
|---|---|
| `form(form)` | the whole form |
| `form_start(form, vars)` | `<form>` (method, action, `enctype` when there is a file field; `_method` hidden field for `PUT`, `PATCH`, `DELETE`) |
| `form_end(form, vars)` | the fields not rendered yet (CSRF token included) and `</form>`; `{render_rest: false}` skips them |
| `form_row(field, vars)` | label, widget, help and errors |
| `form_label(field, label, vars)`, `form_widget(field, vars)`, `form_errors(field)`, `form_help(field)` | one part of a field |
| `form_rest(form)` | the fields not rendered yet |

The helpers accept the form or `$form->createView()`. The variables (`attr`, `label`, `label_attr`, `row_attr`, `help`...) override the options of the field.

The labels, helps, placeholders and choice labels are translated through the Translation package (domain `translation_domain`, `messages` by default) and the errors through the `validators` domain (`translations/validators.{locale}.yaml|xlf`, key `The CSRF token is invalid. Please try to resubmit the form.` for the CSRF error); an untranslated text is rendered as written.

## Themes

`config/framework/form.yaml`:

```yaml
theme: default
csrf_protection: true
csrf_field_name: _token
```

| Theme | Markup |
|---|---|
| `default` | plain HTML (`<div>`, `<label>`, `<ul class="form-errors">`) |
| `bootstrap5` (or `bootstrap`) | Bootstrap 5 (`mb-3`, `form-label`, `form-control`, `form-select`, `form-check`, `is-invalid`, `invalid-feedback`) |
| a class | a class extending `NeoPHP\Component\Form\Theme\DefaultTheme` (or `Bootstrap5Theme`) |

The theme of one form is set with the option `'theme' => 'bootstrap5'`. A theme renders blocks named after the types: for an `EmailType` field, the widget is rendered by `emailWidget()`, else `textWidget()`, else `formWidget()`. A custom theme overrides the methods it needs (`formRow()`, `choiceWidget()`, `checkboxRow()`...), or adds blocks for a type (`tagsInputWidget()`) or for one form (`postRow()` for the form `post`). A theme implements `ThemeInterface` (`formStart()`, `formEnd()`, `formRest()`, `formRow()`, `formWidget()`, `formLabel()`, `formErrors()`, `formHelp()`).

| Key | Default | Description |
|---|---|---|
| `theme` | `default` | default theme |
| `csrf_protection` | `true` | adds a CSRF token to every form (see the CSRF documentation) |
| `csrf_field_name` | `_token` | name of the token field |

## Exceptions

| Exception | Thrown when |
|---|---|
| `FormException` | invalid use of a form (unknown field, unknown option...) |
| `InvalidTypeException` | a type class does not exist or is not a form type |
| `TransformationFailedException` | a value cannot be converted; thrown by `transform()` / `reverseTransform()` |

## Changelog

- v1.20.0 — Messages translated through the Translation package (domain validators); `translation_domain` option for labels, helps and choices.
- v1.17.0 — `make:form` asks for its values when they are missing.
- v1.15.0 — `make:form` rewritten for the new console.
- v1.12.0 — Form component: form classes mapped to an entity or an array, field types, conversion and data mapping, validation, `default` and `bootstrap5` themes, `form_*` view helpers, automatic CSRF token, `make:form`.