<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Storage;

use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Conversation;

class ConversationStorage
{
    public const ID_PATTERN = '/^[a-f0-9]{16,64}$/';

    public function __construct(protected string $directory, protected int $maxMessages = 60)
    {
    }

    public function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function load(string $id): ?Conversation
    {
        if (!$this->isValidId($id)) {
            return null;
        }

        $file = $this->file($id);

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? Conversation::fromArray(['id' => $id] + $data) : null;
    }

    public function save(Conversation $conversation): void
    {
        if (!$this->isValidId($conversation->getId())) {
            throw new NeoAiException('Invalid conversation id "{id}".', 0, null, ['id' => $conversation->getId()]);
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new NeoAiException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $this->directory]);
        }

        $data = $conversation->toArray();
        $data['messages'] = array_slice($data['messages'], -$this->maxMessages);

        file_put_contents($this->file($conversation->getId()), (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }

    public function delete(string $id): bool
    {
        return $this->isValidId($id) && is_file($this->file($id)) && @unlink($this->file($id));
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    protected function file(string $id): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $id . '.json';
    }
}