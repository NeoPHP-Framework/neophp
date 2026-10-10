<?php

declare(strict_types=1);

namespace NeoPHP\Component\Exception;

use Closure;
use Throwable;

interface ExceptionManagerInterface
{
    /**
     * Sets the closure used to dump the arguments of the stack frames on the debug error page.
     *
     * @param Closure|null $dumper A closure receiving the arguments of a frame and returning their HTML dump, or null to hide the arguments
     * @return static The exception manager
     */
    public function setDumper(?Closure $dumper): static;

    /**
     * Returns the closure used to dump the arguments of the stack frames on the debug error page.
     *
     * @return Closure|null The dumper, or null when the arguments are hidden
     */
    public function getDumper(): ?Closure;

    /**
     * Tells whether the error pages show the details of the exception.
     *
     * @return bool True in debug
     */
    public function isDebug(): bool;

    /**
     * Shows or hides the details of the exception in the error pages.
     *
     * @param bool $debug True to show the details
     * @return static The exception manager
     */
    public function setDebug(bool $debug): static;

    /**
     * Returns the HTTP status of an exception: its own status for an exception of the framework, 500 otherwise.
     *
     * @param Throwable $exception The exception
     * @return int The HTTP status code
     */
    public function getStatusCode(Throwable $exception): int;

    /**
     * Returns the HTTP headers of an exception of the framework (Retry-After, WWW-Authenticate...).
     *
     * @param Throwable $exception The exception
     * @return array<string, mixed> The headers, empty for another exception
     */
    public function getHeaders(Throwable $exception): array;

    /**
     * Renders the HTML error page of an exception: the message, source excerpt and stack trace in debug, a simple page otherwise; errors of status 500 or more are logged with error_log().
     *
     * @param Throwable $exception The exception
     * @return string The HTML page
     */
    public function render(Throwable $exception): string;

    /**
     * Renders the JSON error of an exception; the message of an error of status 500 or more is hidden outside of debug.
     *
     * @param Throwable $exception The exception
     * @return array{error: array{status: int, message: string, exception?: array{class: string, file: string, line: int, trace: array<string>}}} The error, with the class, file, line and trace of the exception in debug
     */
    public function renderJson(Throwable $exception): array;
}