<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie;

use NeoPHP\Component\Cookie\Exception\CookieException;
use NeoPHP\Component\Http\Response\Response;

interface CookieManagerInterface
{
    /**
     * Returns the value of a cookie, the queued value first.
     *
     * @param string $name Name of the cookie
     * @param mixed $default Value returned when the cookie does not exist or is queued for removal
     * @return mixed The value of the cookie, or the default value
     */
    public function get(string $name, mixed $default = null): mixed;

    /**
     * Returns the value of a signed cookie when its HMAC signature is valid.
     *
     * @param string $name Name of the cookie
     * @param mixed $default Value returned when the cookie does not exist, is not signed or its signature is invalid
     * @return mixed The value of the cookie without its signature, or the default value
     * @throws CookieException When no secret is configured (APP_SECRET)
     */
    public function getSigned(string $name, mixed $default = null): mixed;

    /**
     * Tells whether a cookie exists in the request or is queued, and is not queued for removal.
     *
     * @param string $name Name of the cookie
     * @return bool True when the cookie exists
     */
    public function has(string $name): bool;

    /**
     * Returns the cookies of the request with the queued changes applied.
     *
     * @return array<string, string> The values of the cookies, by name
     */
    public function all(): array;

    /**
     * Queues a cookie, added to the response by apply().
     *
     * @param string $name Name of the cookie
     * @param string $value Value of the cookie
     * @param array<string, mixed> $options Options replacing the defaults: lifetime (seconds, 0 for a session cookie), path, domain, secure (bool or "auto"), httponly, samesite (Lax, Strict, None or ""), signed
     * @return static The cookie manager
     * @throws CookieException When an option is unknown or the SameSite value is invalid
     */
    public function set(string $name, string $value, array $options = []): static;

    /**
     * Queues the removal of a cookie, expired in the response by apply().
     *
     * @param string $name Name of the cookie
     * @param array<string, mixed> $options Options of the cookie to remove, path and domain in particular
     * @return static The cookie manager
     * @throws CookieException When an option is unknown or the SameSite value is invalid
     */
    public function remove(string $name, array $options = []): static;

    /**
     * Returns the queued cookies, a null value meaning a removal.
     *
     * @return array<string, array{value: string|null, options: array<string, mixed>}> The queued cookies, by name
     */
    public function getQueued(): array;

    /**
     * Adds the queued cookies to a response, signing the signed ones, then empties the queue.
     *
     * @param Response $response The response
     * @return Response The same response
     * @throws CookieException When a signed cookie is queued and no secret is configured (APP_SECRET)
     */
    public function apply(Response $response): Response;
}