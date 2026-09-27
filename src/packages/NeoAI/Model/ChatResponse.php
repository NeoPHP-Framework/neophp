<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Model;

class ChatResponse
{
    public function __construct(
        protected string $content,
        protected string $model = '',
        protected ?string $finishReason = null,
        protected int $promptTokens = 0,
        protected int $completionTokens = 0,
        protected array $raw = [],
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function getUsage(): array
    {
        return ['prompt' => $this->promptTokens, 'completion' => $this->completionTokens, 'total' => $this->getTotalTokens()];
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'finish_reason' => $this->finishReason,
            'usage' => $this->getUsage(),
        ];
    }
}