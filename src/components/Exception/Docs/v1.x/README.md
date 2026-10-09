# Exception

The Exception component is the base of every framework exception: messages with placeholders, context, HTTP status and headers.
It also renders uncaught exceptions as an HTML page or a JSON document.

## Summary

- [FrameworkException](#frameworkexception)
- [Custom exceptions](#custom-exceptions)
- [Rendering](#rendering)
- [Changelog](#changelog)

## FrameworkException

Every framework exception extends `NeoPHP\Component\Exception\FrameworkException`, which extends `Contract\AbstractException` and implements `Contract\ExceptionInterface` (a `Throwable`).

```php
$exception = new FrameworkException('User {id} not found.', 0, null, ['id' => 42]);

$exception->getMessage();
$exception->getContext();
$exception->getStatusCode();
$exception->toArray();
```

`{placeholders}` in the message are replaced by the context values.

| Method | Description |
|---|---|
| `__construct($message = '', $code = 0, $previous = null, $context = [])` | creates the exception |
| `getContext()`, `setContext($context)` | context values |
| `getStatusCode()`, `setStatusCode($code)` | HTTP status, `500` by default |
| `getHeaders()`, `setHeaders($headers)` | HTTP headers of the error response |
| `getStackTrace()` | the trace, file and line included |
| `getPreviousExceptions()` | the chain of previous exceptions |
| `getShortName()` | class name without namespace |
| `toArray($withTrace = true)` | the exception as an array (class, message, code, file, line, context, trace, previous) |

The standard methods (`getMessage()`, `getCode()`, `getFile()`, `getLine()`, `getPrevious()`) are available too.

## Custom exceptions

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use NeoPHP\Component\Exception\FrameworkException;

class PaymentRequiredException extends FrameworkException
{
    public function __construct(string $message = 'Payment required.', array $context = [])
    {
        parent::__construct($message, 0, null, $context);
        $this->setStatusCode(402);
    }
}
```

Each component has its own exceptions (`DatabaseException`, `EventException`, `FormException`, HTTP exceptions...). Routing exceptions use 404 and 405 (with the `Allow` header). For HTTP errors, see the Http documentation.

## Rendering

`NeoPHP\Component\Exception\ExceptionManager` renders the uncaught exceptions. The kernel uses it; it is registered in the container with `kernel.debug`.

| Method | Description |
|---|---|
| `render($exception)` | HTML page |
| `renderJson($exception)` | array for a JSON response (used when the request sends `Accept: application/json`) |
| `getStatusCode($exception)`, `getHeaders($exception)` | status (500 for a non-framework exception) and headers |
| `isDebug()`, `setDebug($debug)` | in debug, the page shows the message, the context, the trace and the previous exceptions; otherwise only the status and its phrase (`ExceptionManager::PHRASES`) |
| `setDumper($dumper)`, `getDumper()` | closure used to dump the context values (set by the Debug component) |

The error page can be replaced with a listener of `ExceptionEvent` (see the Event documentation).

## Changelog

- v1.14.0 — debug page with dumper support.
- v1.0.0 — `FrameworkException`, `ExceptionInterface`, HTML and JSON rendering.