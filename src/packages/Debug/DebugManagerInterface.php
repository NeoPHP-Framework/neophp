<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug;

use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Debug\Cloner\VarCloner;

interface DebugManagerInterface
{
    /**
     * Tells whether the dumps are shown.
     *
     * @return bool True when dump() and dd() output their values
     */
    public function isEnabled(): bool;

    /**
     * Enables or disables the dumps.
     *
     * @param bool $enabled True to show the dumps
     * @return static The debug manager
     */
    public function setEnabled(bool $enabled): static;

    /**
     * Tells whether PHP runs from the command line.
     *
     * @return bool True for the cli and phpdbg SAPIs
     */
    public function isCli(): bool;

    /**
     * Dumps values: written to the output on the command line, injected in the HTML response otherwise.
     *
     * @param mixed ...$values The values to dump
     * @return void
     */
    public function dump(mixed ...$values): void;

    /**
     * Dumps values with the file and line they come from as label.
     *
     * @param string|null $location File and line of the call (file.php:12), or null
     * @param array<mixed> $values The values to dump
     * @return void
     */
    public function dumpFrom(?string $location, array $values): void;

    /**
     * Dumps values, then stops the script with a 500 status outside of the command line.
     *
     * @param mixed ...$values The values to dump
     * @return never
     */
    public function dd(mixed ...$values): never;

    /**
     * Dumps values with the file and line they come from as label, then stops the script.
     *
     * @param string|null $location File and line of the call (file.php:12), or null
     * @param array<mixed> $values The values to dump
     * @return never
     */
    public function ddFrom(?string $location, array $values): never;

    /**
     * Returns the HTML dump of a value.
     *
     * @param mixed $value The value
     * @param string|null $label Label shown above the dump
     * @param int|null $maxDepth Maximum depth, limited by the max_depth option; the option when null
     * @return string The HTML dump
     */
    public function toHtml(mixed $value, ?string $label = null, ?int $maxDepth = null): string;

    /**
     * Returns the text dump of a value.
     *
     * @param mixed $value The value
     * @param string|null $label Label shown above the dump
     * @param bool $colors Adds ANSI colors
     * @return string The text dump
     */
    public function toText(mixed $value, ?string $label = null, bool $colors = false): string;

    /**
     * Tells whether dumps are waiting to be injected in the response.
     *
     * @return bool True when dumps are pending
     */
    public function hasPending(): bool;

    /**
     * Returns the pending dumps and forgets them.
     *
     * @param bool $html Renders HTML dumps, text dumps otherwise
     * @return string The dumps
     */
    public function flush(bool $html = true): string;

    /**
     * Injects the pending dumps after the <body> tag of an HTML response, or before the content of another response.
     *
     * @param Response $response The response
     * @return Response The same response
     */
    public function injectInto(Response $response): Response;

    /**
     * Returns the cloner turning the values into dump nodes, with the max_depth, max_items and max_string limits.
     *
     * @return VarCloner The cloner
     */
    public function getCloner(): VarCloner;
}