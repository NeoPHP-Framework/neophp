<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf;

use NeoPHP\Component\Session\Exception\SessionException;

interface CsrfManagerInterface
{
    /**
     * Returns the token of an identifier, generated and stored in the session when it does not exist yet; the value is masked differently on every call.
     *
     * @param string $id Identifier of the token (form name, "authenticate", "logout"...)
     * @return string The masked token
     * @throws SessionException When the session cannot be started
     */
    public function getToken(string $id): string;

    /**
     * Generates a new token for an identifier, replacing the previous one; the oldest tokens are removed beyond max_tokens.
     *
     * @param string $id Identifier of the token
     * @return string The masked token
     * @throws SessionException When the session cannot be started
     */
    public function refreshToken(string $id): string;

    /**
     * Removes the token of an identifier from the session.
     *
     * @param string $id Identifier of the token
     * @return void
     * @throws SessionException When the session cannot be started
     */
    public function removeToken(string $id): void;

    /**
     * Tells whether a token exists for an identifier.
     *
     * @param string $id Identifier of the token
     * @return bool True when the token exists
     */
    public function hasToken(string $id): bool;

    /**
     * Checks a submitted token against the token of an identifier, in constant time.
     *
     * @param string $id Identifier of the token
     * @param string|null $token The submitted masked token
     * @return bool True when the token is valid
     */
    public function isTokenValid(string $id, ?string $token): bool;

    /**
     * Returns the name of the form field holding the token.
     *
     * @return string The field name (_token by default)
     */
    public function getFieldName(): string;

    /**
     * Returns the name of the HTTP header holding the token for Ajax requests.
     *
     * @return string The header name (X-CSRF-TOKEN by default)
     */
    public function getHeaderName(): string;
}