<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session;

use NeoPHP\Component\Session\Exception\SessionException;

interface SessionManagerInterface
{
    /**
     * Starts the session with the configured cookie and storage options; does nothing when it is already started.
     *
     * @return void
     * @throws SessionException When the headers are already sent, the save path cannot be created or the session cannot be started
     */
    public function start(): void;

    /**
     * Tells whether the session is started.
     *
     * @return bool True when the session is active
     */
    public function isStarted(): bool;

    /**
     * Returns the session id.
     *
     * @return string The id, or an empty string when the session is not started
     */
    public function getId(): string;

    /**
     * Returns the name of the session cookie.
     *
     * @return string The cookie name (NEOSESSID by default)
     */
    public function getName(): string;

    /**
     * Returns a value of the session; the session is started only when the session cookie exists.
     *
     * @param string $key Key of the value
     * @param mixed $default Value returned when the key does not exist
     * @return mixed The value, or the default value
     * @throws SessionException When the session cannot be started
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Writes a value in the session, starting it first.
     *
     * @param string $key Key of the value
     * @param mixed $value The value
     * @return static The session manager
     * @throws SessionException When the session cannot be started
     */
    public function set(string $key, mixed $value): static;

    /**
     * Tells whether a key exists in the session.
     *
     * @param string $key Key of the value
     * @return bool True when the key exists
     * @throws SessionException When the session cannot be started
     */
    public function has(string $key): bool;

    /**
     * Removes a value from the session.
     *
     * @param string $key Key of the value
     * @return mixed The removed value, or null when the key does not exist
     * @throws SessionException When the session cannot be started
     */
    public function remove(string $key): mixed;

    /**
     * Returns every value of the session.
     *
     * @return array<string, mixed> The values, by key
     * @throws SessionException When the session cannot be started
     */
    public function all(): array;

    /**
     * Removes every value of the session, without changing its id.
     *
     * @return static The session manager
     * @throws SessionException When the session cannot be started
     */
    public function clear(): static;

    /**
     * Gives the session a new id, keeping its values (after a login, for example).
     *
     * @param bool $destroy Deletes the data stored under the previous id
     * @return static The session manager
     * @throws SessionException When the session cannot be started or its id cannot be regenerated
     */
    public function regenerate(bool $destroy = true): static;

    /**
     * Removes every value and gives the session a new id (after a logout, for example).
     *
     * @return static The session manager
     * @throws SessionException When the session cannot be started or its id cannot be regenerated
     */
    public function invalidate(): static;

    /**
     * Writes and closes the session.
     *
     * @return void
     */
    public function save(): void;
}