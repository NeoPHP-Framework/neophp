<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Contract;

use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;

interface ProviderInterface
{
    public function getName(): string;

    public function getType(): string;

    public function getModel(): string;

    public function isLocal(): bool;

    public function chat(Conversation|array $messages, array $options = []): ChatResponse;

    public function supportsStreaming(): bool;

    public function stream(Conversation|array $messages, callable $onChunk, array $options = []): ChatResponse;
}