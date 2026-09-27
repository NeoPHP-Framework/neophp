<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Model;

class Reply
{
    public function __construct(
        protected string $content,
        protected array $patches = [],
        protected array $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0],
        protected array $tools = [],
        protected int $iterations = 0,
        protected string $model = '',
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPatches(): array
    {
        return $this->patches;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function toArray(): array
    {
        return [
            'reply' => $this->content,
            'patches' => array_map(static fn (Patch $patch): array => $patch->toArray(), $this->patches),
            'usage' => $this->usage,
            'tools' => $this->tools,
            'iterations' => $this->iterations,
            'model' => $this->model,
        ];
    }
}