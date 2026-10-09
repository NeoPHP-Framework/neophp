# Validator

The Validator component checks objects, values and arrays against constraints.
A constraint is a class used either as a PHP attribute or as an object; its validator is built by the container, so custom validators can use services.

## Summary

- [Quick start](#quick-start)
- [Validating](#validating)
- [Violations](#violations)
- [Constraints](#constraints)
- [Messages](#messages)
- [Groups](#groups)
- [Nested data](#nested-data)
- [Callback](#callback)
- [Custom constraints](#custom-constraints)
- [Execution context](#execution-context)
- [JSON errors](#json-errors)
- [API](#api)
- [Changelog](#changelog)

## Quick start

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use NeoPHP\Component\Validator\Constraint as Assert;

class SignupDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 20)]
    public ?string $username = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\Length(min: 8)]
    public ?string $password = null;

    #[Assert\EqualTo(propertyPath: 'password', message: 'The passwords do not match.')]
    public ?string $confirm = null;

    #[Assert\Valid]
    public ?AddressDto $address = null;
}
```

In a controller, `validate()` returns the violations:

```php
$violations = $this->validate($dto);

if (count($violations) > 0) {
    return $this->json(['errors' => $violations->toArray()], 422);
}
```

Outside a controller, inject `NeoPHP\Component\Validator\Contract\ValidatorInterface`.

## Validating

| Call | Validates |
|---|---|
| `validate($object)` | the constraints declared with attributes on the object (properties and class) |
| `validate($value, new Email())` | a value with one constraint |
| `validate($value, [new NotBlank(), new Length(min: 3)])` | a value with several constraints |
| `validate($data, ['email' => [new NotBlank(), new Email()], 'age' => new Range(min: 18)])` | an array (or object) field by field; a missing field is `null` |
| `validateProperty($object, 'email')` | one property of an object |
| `validateOrFail(...)` | same as `validate()`, throws a `ValidationFailedException` (HTTP 422) when it fails |

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Validator\Constraint\Email;
use NeoPHP\Component\Validator\Constraint\NotBlank;
use NeoPHP\Component\Validator\Contract\ValidatorInterface;

final class NewsletterSubscriber
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function subscribe(string $email): void
    {
        $this->validator->validateOrFail($email, [new NotBlank(), new Email()]);
    }
}
```

## Violations

`validate()` returns a `ViolationList` (countable and iterable over `Violation` objects):

| Method | Returns |
|---|---|
| `count($violations)` / `count()` | number of violations |
| `has(?string $path = null)` | whether there is a violation (for a path) |
| `first(?string $path = null)` | first message, or `null` |
| `messages(string $path)` | messages of a path |
| `get(string $path)` | `Violation` objects of a path |
| `all()` | all the `Violation` objects |
| `toArray()` | `['email' => ['This value is not a valid email address.'], ...]` |
| `add(Violation $violation)` / `addAll(ViolationList $list)` | adds violations |

`Violation`: `getMessage()`, `getMessageTemplate()`, `getParameters()`, `getPropertyPath()`, `getInvalidValue()`, `getConstraint()`; it can be cast to string.

Paths: `email`, `address.city` (with `Valid`), `tags[1]` (with `All`), `data[email]` (with `Collection`).

## Constraints

All the constraints are in `NeoPHP\Component\Validator\Constraint`.

| Constraint | Options |
|---|---|
| `NotBlank` | `allowNull`, `trim` (blank: `null`, `''`, `[]`, `false`) |
| `Blank`, `NotNull`, `IsNull`, `IsTrue`, `IsFalse` | |
| `Type` | `type` (`string`, `int`, `float`, `bool`, `array`, `numeric`, `scalar`, `iterable`, `callable`, `object`, `alpha`, `digit`, `alnum` or a class; several allowed) |
| `Length` | `exactly`, `min`, `max` (characters, UTF-8) |
| `Count` | `exactly`, `min`, `max` (elements of an array or `Countable`) |
| `Range` | `min`, `max` (numbers or dates) |
| `EqualTo`, `NotEqualTo`, `GreaterThan`, `GreaterThanOrEqual`, `LessThan`, `LessThanOrEqual` | `value` or `propertyPath` (compares with another property) |
| `Positive`, `PositiveOrZero`, `Negative`, `NegativeOrZero` | |
| `Email`, `Uuid`, `Json`, `Date` (`Y-m-d`) | |
| `Url` | `protocols` (default `['http', 'https']`) |
| `Ip` | `version` (`4`, `6` or `all`) |
| `DateTime` | `format` (default `Y-m-d H:i:s`) |
| `Regex` | `pattern`, `match` (`false`: the value must not match) |
| `Choice` | `choices` or `callback`, `multiple`, `min`, `max`, `strict` (default `true`) |
| `All` | `constraints` applied to each element |
| `Collection` | `fields` (`['email' => [...]]`), `allowExtraFields`, `allowMissingFields` |
| `Valid` | validates the nested object (or each object of an array); `traverse` |
| `Callback` | `callback`: method of the object or callable (class or property) |
| `File` | `maxSize` (`500k`, `2M`, `1Gi` or bytes), `mimeTypes` (`['application/pdf', 'image/*']`), `extensions` (`['pdf']`); validates an `UploadedFile`, a `SplFileInfo` or a path |
| `Image` | the `File` options (`mimeTypes` defaults to `image/*`), `minWidth`, `maxWidth`, `minHeight`, `maxHeight` |

Every constraint accepts `groups`. Except `NotBlank`, `NotNull` and `IsNull`, constraints accept `null` and `''`: add `NotBlank` to make a value required.

`File::parseSize(int|string $size): int` converts a size (`2M`, `1Gi`) into bytes.

## Messages

Every constraint accepts `message`, or its specific messages (`minMessage`, `maxMessage`, `exactMessage`, `typeMessage`, `notInRangeMessage`, `multipleMessage`, `missingFieldsMessage`, `extraFieldsMessage`, `maxSizeMessage`, `mimeTypesMessage`...).

```php
#[Assert\Length(min: 3, minMessage: 'At least {{ limit }} characters.')]
public ?string $username = null;
```

Placeholders: `{{ value }}`, `{{ limit }}`, `{{ min }}`, `{{ max }}`, `{{ compared_value }}`, `{{ choices }}`, `{{ type }}`, and for files `{{ size }}`, `{{ types }}`, `{{ extension }}`, `{{ width }}`, `{{ height }}`.

The messages are translated in the current locale through the `validators` domain of the Translation package (the key is the English message); an untranslated message stays in English. The framework ships no translation: the application translates or rewords the messages it needs in `translations/validators.{locale}.yaml` (or `.xlf`):

```yaml
"This value should not be blank.": "Cette valeur ne doit pas être vide."
"This value is too long. It should have {{ limit }} character(s) or less.": "Cette chaîne est trop longue. Elle doit avoir au maximum {{ limit }} caractère(s)."
```

The keys are the default messages of the constraints, or the `message` option given to a constraint.

## Groups

A constraint belongs to the `Default` group (`AbstractConstraint::DEFAULT_GROUP`), unless `groups` is given. `validate()` validates the `Default` group, unless groups are given:

```php
#[Assert\NotBlank(groups: ['create'])]
public ?string $password = null;
```

```php
$this->validate($dto, null, ['Default', 'create']);
```

## Nested data

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use NeoPHP\Component\Validator\Constraint as Assert;

class OrderDto
{
    #[Assert\Valid]
    public array $lines = [];

    #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 20)])]
    public array $tags = [];

    #[Assert\Collection(fields: [
        'email' => [new Assert\NotBlank(), new Assert\Email()],
        'phone' => new Assert\Regex('/^\+?[0-9 ]+$/'),
    ], allowMissingFields: true)]
    public array $contact = [];
}
```

## Callback

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;
use NeoPHP\Component\Validator\Constraint as Assert;
use NeoPHP\Component\Validator\Context\ExecutionContext;

#[Assert\Callback('validatePeriod')]
class BookingDto
{
    public ?DateTimeImmutable $start = null;

    public ?DateTimeImmutable $end = null;

    public function validatePeriod(ExecutionContext $context): void
    {
        if ($this->start !== null && $this->end !== null && $this->end < $this->start) {
            $context->addViolation('The end must be after the start.', [], 'end');
        }
    }
}
```

On a property, the method receives `($value, ExecutionContext $context)`. `new Callback(fn (mixed $value, ExecutionContext $context): mixed => ...)` works with a closure.

## Custom constraints

A constraint extends `AbstractConstraint`. Its validator is the class with the same name followed by `Validator` (override `validatedBy()` to change it). The validator is built by the container: its dependencies are autowired.

```php
<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class UniqueUsername extends AbstractConstraint
{
    public string $message = 'The username {{ value }} is already used.';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Validator;

use App\Repository\UserRepository;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class UniqueUsernameValidator extends AbstractConstraintValidator
{
    public function __construct(protected UserRepository $users)
    {
    }

    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, UniqueUsername::class);

        if ($this->isEmpty($value)) {
            return;
        }

        if ($this->users->existsByUsername((string) $value)) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}
```

`AbstractConstraintValidator` helpers:

| Method | Description |
|---|---|
| `isEmpty(mixed $value): bool` | `true` for `null` and `''` |
| `toString(mixed $value): ?string` | string of a scalar or `Stringable`, else `null` |
| `expect(ConstraintInterface $constraint, string $class): void` | throws a `ValidatorException` when the constraint has another class |
| `static formatValue(mixed $value): string` | value formatted for a message (`"text"`, `null`, `true`, dates...) |

## Execution context

`NeoPHP\Component\Validator\Context\ExecutionContext` is given to validators and callbacks:

| Method | Description |
|---|---|
| `addViolation(string $message, array $parameters = [], ?string $path = null, mixed $invalidValue = null)` | adds a violation; `$path` is relative to the current path |
| `validate(mixed $value, ConstraintInterface\|array $constraints, string $path = '')` | validates a nested value |
| `validateObject(object $object, string $path = '')` | validates a nested object with its attributes |
| `getViolations()` | violations collected so far |
| `getPropertyPath()`, `getValue()`, `getConstraint()` | current path, value and constraint |
| `getObject()` | object being validated |
| `getRoot()` | value given to `validate()` |
| `getGroups()` | validated groups |

## JSON errors

`ValidationFailedException` has the HTTP status 422. For a request that expects JSON, an uncaught one returns a 422 response with the violations:

```json
{"error": {"status": 422, "message": "The data is not valid: 1 violation(s).", "violations": {"username": ["This value should not be blank."]}}}
```

## API

### ValidatorInterface

`NeoPHP\Component\Validator\Contract\ValidatorInterface`, implemented by `ValidatorManager` (extends `AbstractValidator`):

| Method | Description |
|---|---|
| `validate(mixed $value, ConstraintInterface\|array\|null $constraints = null, array $groups = ['Default']): ViolationList` | validates a value, an object or an array |
| `validateProperty(object $object, string $property, array $groups = ['Default']): ViolationList` | validates one property |
| `validateOrFail(mixed $value, ConstraintInterface\|array\|null $constraints = null, array $groups = ['Default']): void` | throws a `ValidationFailedException` |

### Contracts

| Interface / class | Methods |
|---|---|
| `ConstraintInterface` | `validatedBy(): string`, `getGroups(): array`, `inGroups(array $groups): bool` |
| `AbstractConstraint` | implements `ConstraintInterface`, public `$groups`, protected `setGroups(?array $groups)` |
| `ConstraintValidatorInterface` | `validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void` |
| `AbstractConstraintValidator` | base class of the validators (see [Custom constraints](#custom-constraints)) |

### Controllers

`validate(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = ['Default']): ViolationList` is available in controllers (see the Controller documentation).

### Exceptions

| Exception | Description |
|---|---|
| `ValidatorException` | invalid constraint or validator |
| `ValidationFailedException` | thrown by `validateOrFail()`; `create(ViolationList $violations)`, `getViolations(): ViolationList`; status 422 |

## Changelog

- v1.20.0 — Messages translated through the Translation package (domain validators).
- v1.12.0 — `File` and `Image` constraints.
- v1.10.0 — Constraints usable as attributes or objects, validation of objects, values and arrays, groups, `Valid`, `All`, `Collection`, `Callback`, custom constraints with autowired validators, `validate()` in controllers, 422 JSON response for `ValidationFailedException`.