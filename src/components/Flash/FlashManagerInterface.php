<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash;

use NeoPHP\Component\Session\Exception\SessionException;

interface FlashManagerInterface
{
    /**
     * Adds a flash message, kept in the session until it is read.
     *
     * @param string $type Type of the message (success, error, warning, info...)
     * @param string $message The message
     * @return static The flash manager
     * @throws SessionException When the session cannot be started
     */
    public function add(string $type, string $message): static;

    /**
     * Returns the messages of a type and removes them from the session.
     *
     * @param string $type Type of the messages
     * @return list<string> The messages, in the order they were added
     * @throws SessionException When the session cannot be started
     */
    public function get(string $type): array;

    /**
     * Returns the messages of a type without removing them.
     *
     * @param string $type Type of the messages
     * @return list<string> The messages, in the order they were added
     */
    public function peek(string $type): array;

    /**
     * Returns every message and removes them from the session.
     *
     * @return array<string, list<string>> The messages, by type
     */
    public function all(): array;

    /**
     * Returns every message without removing them.
     *
     * @return array<string, list<string>> The messages, by type
     */
    public function peekAll(): array;

    /**
     * Tells whether messages of a type are waiting.
     *
     * @param string $type Type of the messages
     * @return bool True when at least one message of the type exists
     */
    public function has(string $type): bool;

    /**
     * Removes every message without reading them.
     *
     * @return static The flash manager
     */
    public function clear(): static;
}