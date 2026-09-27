<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Model;

class Patch
{
    public function __construct(
        protected string $diff,
        protected array $files = [],
        protected string $description = '',
        protected array $hashes = [],
    ) {
    }

    public function getDiff(): string
    {
        return $this->diff;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getHashes(): array
    {
        return $this->hashes;
    }

    public function getHash(string $file): ?string
    {
        return $this->hashes[$file] ?? null;
    }

    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'files' => $this->files,
            'diff' => $this->diff,
        ];
    }
}